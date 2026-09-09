<?php

namespace App\Services\Rag;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RetrievalService
{
    // See the comment where this is used: a full-strength coverage ratio, computed from as few
    // as 2-4 surviving question tokens, matches too easily by coincidence to be trusted as much
    // as cosine similarity.
    private const LEXICAL_COVERAGE_WEIGHT = 0.5;

    public function __construct(
        private readonly EmbeddingService $embeddingService,
        private readonly PaymentScopeResolver $paymentScopeResolver,
        private readonly ReciprocalRankFusion $fusion,
    ) {}

    public function search(
        string $question,
        ?string $moyenPaiement = null,
        ?string $operation = null,
        ?int $limit = null,
        array $filters = [],
        bool $vectorOnly = false,
    ): Collection {
        $corpusPaymentMethod = $this->paymentScopeResolver->corpusFor($moyenPaiement);
        $retrievalQuestion = $this->paymentScopeResolver->retrievalQuestion($question);
        $vector = $this->embeddingService->toSqlLiteral($this->embeddingService->embedForQuery($retrievalQuestion));
        $model = $this->embeddingService->lastModel();
        $limit ??= (int) config('rag.top_k', 5);
        $lexicalQuery = $this->lexicalQuery($retrievalQuestion);
        $operationTerms = $operation ? $this->operationTerms($operation) : [];
        $candidateLimit = max($limit * 4, 20);

        $baseQuery = fn () => DB::table('embeddings')
            ->join('chunks', 'chunks.id', '=', 'embeddings.chunk_id')
            ->join('documents_api', 'documents_api.id', '=', 'chunks.document_id')
            ->leftJoin('moyens_paiement', 'moyens_paiement.id', '=', 'documents_api.moyen_paiement_id')
            ->leftJoin('knowledge_versions', 'knowledge_versions.id', '=', 'documents_api.knowledge_version_id')
            ->where('embeddings.modele', $model)
            ->whereNotNull('embeddings.vecteur')
            ->whereNotNull('documents_api.lien_officiel')
            ->whereNotNull('documents_api.link_verified_at')
            ->where('documents_api.actif', true)
            ->where('chunks.status', 'active')
            ->where(function ($query): void {
                $query->where('knowledge_versions.statut', 'active')
                    ->orWhere(function ($legacy): void {
                        $legacy->whereNull('documents_api.knowledge_version_id')
                            ->whereNotExists(function ($activeVersion): void {
                                $activeVersion->selectRaw('1')
                                    ->from('knowledge_versions as active_knowledge_versions')
                                    ->where('active_knowledge_versions.statut', 'active');
                            });
                    });
            })
            ->select([
                'chunks.id as chunk_id',
                'chunks.contenu',
                'chunks.search_text',
                'chunks.section',
                'chunks.section_slug',
                'chunks.content_type',
                'chunks.position',
                'chunks.token_count',
                'chunks.payment_method',
                'chunks.operation',
                'chunks.environment',
                'chunks.http_method',
                'chunks.endpoint',
                'chunks.error_code',
                'chunks.http_status',
                'chunks.status as chunk_status',
                'chunks.index_terms',
                'documents_api.id as document_id',
                'documents_api.titre as document_titre',
                'documents_api.lien_officiel',
                'documents_api.version',
                'documents_api.date_indexation',
                'documents_api.knowledge_version_id',
                'knowledge_versions.version as knowledge_version',
                'documents_api.has_known_conflicts',
                'documents_api.conflict_note',
                'moyens_paiement.nom as moyen_paiement',
            ]);

        $applyScope = function ($query) use ($corpusPaymentMethod): void {
            if (! $corpusPaymentMethod) {
                return;
            }

            // The requested rail remains in the original question, while retrieval is
            // constrained to PVIT, the owner of the public gateway contract.
            $query->whereRaw('lower(moyens_paiement.nom) = lower(?)', [$corpusPaymentMethod]);
        };

        $applyMetadataFilters = function ($query) use ($filters): void {
            foreach (['operation', 'environment', 'http_method', 'endpoint', 'error_code', 'content_type', 'section_slug'] as $field) {
                $value = $filters[$field] ?? null;

                if (! is_string($value) || trim($value) === '') {
                    continue;
                }

                if ($field === 'operation') {
                    // Per-chunk operation tagging is keyword-based on that chunk's own text
                    // alone (see ChunkMetadataExtractor::operation()), so a step that never
                    // repeats one of the trigger words -- e.g. an OTP-verification step inside
                    // a registration flow -- is left untagged even though it plainly belongs to
                    // that operation. Treat untagged as "unknown", not "wrong operation": a hard
                    // equality filter would silently drop it instead of just deprioritizing it
                    // relative to chunks that matched (see operation_boost below).
                    $query->whereRaw('(chunks.operation is null or lower(chunks.operation) = lower(?))', [trim($value)]);

                    continue;
                }

                $query->whereRaw("lower(chunks.{$field}) = lower(?)", [trim($value)]);
            }

            if (isset($filters['http_status']) && is_numeric($filters['http_status'])) {
                $query->where('chunks.http_status', (int) $filters['http_status']);
            }

            $knowledgeVersion = $filters['knowledge_version'] ?? null;

            if (is_string($knowledgeVersion) && trim($knowledgeVersion) !== '') {
                $query->where('knowledge_versions.version', trim($knowledgeVersion));
            }
        };

        // Keep the ANN ordering isolated so PostgreSQL can use the HNSW index.
        // Mixing FTS expressions in this ORDER BY would usually force a broader scan.
        $vectorQuery = $baseQuery()
            ->selectRaw('embeddings.vecteur <=> ?::vector as distance', [$vector])
            ->selectRaw('0::double precision as lexical_rank')
            ->orderByRaw('embeddings.vecteur <=> ?::vector', [$vector]);
        $applyScope($vectorQuery);
        $applyMetadataFilters($vectorQuery);
        $vectorCandidates = $vectorQuery->limit($candidateLimit)->get();

        if ($vectorOnly) {
            // Bypasses lexical fusion and the relevance-heuristic rescoring below so
            // callers (embedding-quality evaluation) see the raw ANN cosine ordering.
            return $vectorCandidates->take($limit)->values();
        }

        // Retrieve lexical candidates independently through the GIN expression index.
        $lexicalQueryBuilder = $baseQuery()
            ->selectRaw('NULL::double precision as distance')
            ->selectRaw("ts_rank_cd(to_tsvector('french', coalesce(chunks.search_text, chunks.contenu)), websearch_to_tsquery('french', ?)) as lexical_rank", [$lexicalQuery])
            ->whereRaw("to_tsvector('french', coalesce(chunks.search_text, chunks.contenu)) @@ websearch_to_tsquery('french', ?)", [$lexicalQuery])
            ->orderByDesc('lexical_rank');
        $applyScope($lexicalQueryBuilder);
        $applyMetadataFilters($lexicalQueryBuilder);
        $lexicalCandidates = $lexicalQueryBuilder->limit($candidateLimit)->get();

        $rows = $this->fusion->fuse(
            ['vector' => $vectorCandidates, 'lexical' => $lexicalCandidates],
            ['vector' => 1.0, 'lexical' => 0.85],
            (int) config('rag.rrf_rank_constant', 60),
        );

        // The requested rail is routing metadata, not evidence that every PVIT
        // paragraph must repeat the rail name. Keep those tokens in the FTS query
        // (they help find gateway-overview pages), but do not let them depress the
        // lexical coverage of a shared PVIT procedure such as registration.
        $questionTokens = array_values(array_diff(
            $this->relevanceTokens($retrievalQuestion),
            $this->paymentScopeTokens(),
        ));

        return $rows
            ->map(function ($row) use ($retrievalQuestion, $questionTokens, $operationTerms, $moyenPaiement, $corpusPaymentMethod) {
                $contentTokens = array_fill_keys($this->relevanceTokens(implode(' ', [
                    (string) $row->contenu,
                    (string) data_get($row, 'search_text', ''),
                    (string) data_get($row, 'content_type', ''),
                    (string) data_get($row, 'section_slug', ''),
                    (string) data_get($row, 'index_terms', ''),
                ])), true);
                $matches = count(array_filter($questionTokens, static fn (string $token): bool => isset($contentTokens[$token])));
                $coverage = $questionTokens === [] ? 0.0 : $matches / count($questionTokens);
                $distance = data_get($row, 'distance');
                $cosine = is_numeric($distance)
                    ? max(0.0, min(1.0, 1.0 - (float) $distance))
                    : 0.0;
                $normalizedOperationText = Str::ascii(mb_strtolower(implode(' ', [
                    (string) $row->document_titre,
                    (string) $row->section,
                    (string) data_get($row, 'content_type', ''),
                    (string) data_get($row, 'section_slug', ''),
                    (string) $row->contenu,
                ])));
                $normalizedMetadata = Str::ascii(mb_strtolower(implode(' ', [
                    (string) $row->document_titre,
                    (string) $row->section,
                    (string) data_get($row, 'content_type', ''),
                    (string) data_get($row, 'section_slug', ''),
                    (string) data_get($row, 'operation'),
                    (string) data_get($row, 'endpoint'),
                    (string) data_get($row, 'error_code'),
                    (string) data_get($row, 'index_terms', ''),
                ])));
                $operationMatches = collect($operationTerms)->filter(
                    static fn (string $term): bool => str_contains($normalizedOperationText, Str::ascii(mb_strtolower($term))),
                )->count();
                $metadataMatches = collect($operationTerms)->filter(
                    static fn (string $term): bool => str_contains($normalizedMetadata, Str::ascii(mb_strtolower($term))),
                )->count();
                $row->operation_boost = min(0.36, $operationMatches * 0.12);
                $row->operation_metadata_boost = min(0.48, $metadataMatches * 0.24);
                $row->content_type_boost = $this->contentTypeBoost($retrievalQuestion, data_get($row, 'content_type'));
                $row->lexical_coverage = $coverage;
                // Letting raw coverage compete at full strength lets a couple of generic tokens
                // ("connecter", "ligne" via the unrelated phrase "paiements en ligne") outrank a
                // chunk the embedding correctly recognizes as on-topic, purely because they
                // happen to also appear -- with only 2-4 question tokens surviving the stop-word
                // filter, matching most of them is easy and says little about relevance. Coverage
                // still needs to rescue a genuine exact-term match the embedding underweights
                // (an error code, an endpoint name), so it isn't dropped -- just capped well below
                // what a confident cosine similarity can reach on its own.
                $row->relevance_score = max($cosine, $coverage * self::LEXICAL_COVERAGE_WEIGHT);
                $row->requested_moyen_paiement = $moyenPaiement;
                $row->corpus_moyen_paiement = $corpusPaymentMethod;

                return $row;
            })
            ->sortByDesc(static fn ($row): float => (float) $row->relevance_score
                + min(1.0, (float) $row->lexical_rank) * 0.12
                + (float) $row->operation_boost
                + (float) $row->operation_metadata_boost
                + (float) $row->content_type_boost
                + min(0.08, (float) $row->fusion_score * 2.5))
            ->take($limit)
            ->values();
    }

    private function operationTerms(string $operation): array
    {
        return match (mb_strtolower(trim($operation))) {
            'authentification' => ['authent', 'secret', 'token'],
            'inscription' => ['inscri', 'register', 'compte'],
            'initiation de paiement', 'paiement' => ['paiement', 'payment', 'transaction'],
            'callback' => ['callback', 'notification', 'reception'],
            'gestion des erreurs', 'erreur' => ['erreur', 'error', 'statut'],
            'remboursement' => ['rembourse', 'refund'],
            'renouvellement de secret' => ['renew-secret', 'renew secret', 'renouvel', 'secret'],
            "méthode d'intégration" => ['frontend', 'backend', 'lien de paiement', 'module', 'e-commerce', 'ecommerce', 'woocommerce', 'prestashop', 'interface', 'integration', 'plugin', 'checkout'],
            default => [$operation],
        };
    }

    private function lexicalQuery(string $question): string
    {
        $tokens = $this->relevanceTokens($question);

        return $tokens === [] ? 'documentation' : implode(' OR ', $tokens);
    }

    private function contentTypeBoost(string $question, mixed $contentType): float
    {
        if (! is_string($contentType) || $contentType === '') {
            return 0.0;
        }

        $normalized = Str::ascii(mb_strtolower($question));

        if (preg_match('/\b(?:donnees?|champs?|parametres?|transmettre|envoyer|requete|requetes|request|payload|body|headers?|entetes?|api|endpoint)\b/u', $normalized) === 1) {
            return match ($contentType) {
                'parameters' => 0.32,
                'request', 'authentication' => 0.18,
                default => 0.0,
            };
        }

        if (preg_match('/\b(?:callback|webhook|notification)\b/u', $normalized) === 1) {
            return $contentType === 'callback' ? 0.22 : 0.0;
        }

        if (preg_match('/\b(?:erreur|error|statut|status|code)\b/u', $normalized) === 1) {
            return $contentType === 'error' ? 0.22 : 0.0;
        }

        return 0.0;
    }

    /**
     * @return list<string>
     */
    private function relevanceTokens(string $text): array
    {
        $text = Str::ascii(mb_strtolower($text));
        $text = (string) preg_replace('/\be[\s-]+mail\b/u', 'email', $text);
        $tokens = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stopWords = array_fill_keys([
            'avec', 'avant', 'cette', 'comment', 'dans', 'des', 'elle', 'elles', 'est', 'etre',
            'ils', 'lors', 'mais', 'nous', 'pour', 'quel', 'quelle', 'quelles', 'quels', 'sans',
            'sont', 'sur', 'une', 'vous', 'votre', 'aux', 'les', 'leur', 'leurs', 'par', 'pas',
            'cadre', 'via',
            'the', 'and', 'for', 'from', 'how', 'into', 'that', 'this', 'what', 'when', 'with',
        ], true);

        return collect($tokens)
            ->filter(static fn (string $token): bool => strlen($token) >= 3 && ! isset($stopWords[$token]))
            ->map(static fn (string $token): string => strlen($token) > 5
                ? (string) preg_replace('/(?:es|s)$/', '', $token)
                : $token)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function paymentScopeTokens(): array
    {
        return collect((array) config('rag.payment_method_aliases', []))
            ->flatten()
            ->flatMap(function (string $alias): array {
                $normalized = Str::ascii(mb_strtolower($alias));

                return preg_split('/[^a-z0-9]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            })
            ->filter(static fn (string $token): bool => strlen($token) >= 3)
            ->map(static fn (string $token): string => strlen($token) > 5
                ? (string) preg_replace('/(?:es|s)$/', '', $token)
                : $token)
            ->unique()
            ->values()
            ->all();
    }
}
