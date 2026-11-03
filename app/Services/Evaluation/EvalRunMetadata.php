<?php

namespace App\Services\Evaluation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * What an evaluation report must record to be comparable with another one: the code (commit and
 * whether the tree was dirty), the knowledge base it ran on, the models and the retrieval
 * settings. Two reports whose fingerprints differ measure two different systems.
 */
class EvalRunMetadata
{
    /**
     * @param  array<string, mixed>  $extra  Run-specific settings (top_k, split, mode...).
     * @return array<string, mixed>
     */
    public function collect(string $datasetFile, array $extra = []): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'git' => $this->git(),
            'dataset' => $this->dataset($datasetFile),
            'knowledge_base' => $this->knowledgeBase(),
            'embedding_model' => CachedQueryEmbeddingService::primaryModel(),
            'generator' => [
                'provider' => 'mistral',
                'model' => (string) config('llm.providers.mistral.model'),
                'temperature' => (float) config('llm.temperature', 0.1),
            ],
            'retrieval' => [
                'top_k' => (int) config('rag.top_k', 5),
                'candidate_pool' => config('rag.candidate_pool'),
                'max_chunks_per_document' => (int) config('rag.retrieval_max_chunks_per_document', 3),
                'gap_bridge_max_rows' => (int) config('rag.gap_bridge_max_rows', 2),
                'context_min_chunks' => (int) config('rag.context_min_chunks', 3),
                'context_relative_score' => (float) config('rag.context_relative_score', 0.6),
                'context_budget_chars' => (int) config('rag.context_budget_chars', 10000),
            ],
            'php' => PHP_VERSION,
        ] + $extra;
    }

    /**
     * @return array{commit: ?string, dirty_files: ?int}
     */
    public function git(): array
    {
        try {
            $commit = Process::path(base_path())->run('git rev-parse HEAD');
            $status = Process::path(base_path())->run('git status --porcelain');

            return [
                'commit' => $commit->successful() ? trim($commit->output()) : null,
                'dirty_files' => $status->successful()
                    ? count(array_filter(preg_split('/\R/', trim($status->output())) ?: []))
                    : null,
            ];
        } catch (Throwable) {
            return ['commit' => null, 'dirty_files' => null];
        }
    }

    /**
     * @return array{file: string, sha1: ?string, version: mixed}
     */
    public function dataset(string $datasetFile): array
    {
        $payload = is_file($datasetFile) ? json_decode((string) file_get_contents($datasetFile), true) : null;

        return [
            'file' => str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $datasetFile)),
            'sha1' => is_file($datasetFile) ? sha1_file($datasetFile) : null,
            'version' => is_array($payload) ? ($payload['version'] ?? null) : null,
        ];
    }

    /**
     * Fingerprint of the active corpus: its chunk ids and contents, and the embedding models the
     * active chunks are indexed with. Any re-ingestion or re-embedding changes it.
     *
     * @return array{chunks: ?int, fingerprint: ?string, embedding_models: array<string, int>}
     */
    public function knowledgeBase(): array
    {
        try {
            if (DB::connection()->getDriverName() !== 'pgsql') {
                return ['chunks' => null, 'fingerprint' => null, 'embedding_models' => []];
            }

            $corpus = DB::selectOne(
                "select count(*) as chunks, md5(string_agg(chunks.id::text || ':' || md5(chunks.contenu), ',' order by chunks.id)) as fingerprint
                from chunks
                join documents_api on documents_api.id = chunks.document_id
                where chunks.status = 'active' and documents_api.actif = true"
            );
            $models = DB::select(
                "select embeddings.modele as model, count(*) as total
                from embeddings
                join chunks on chunks.id = embeddings.chunk_id
                join documents_api on documents_api.id = chunks.document_id
                where chunks.status = 'active' and documents_api.actif = true and embeddings.vecteur is not null
                group by embeddings.modele"
            );

            return [
                'chunks' => (int) $corpus->chunks,
                'fingerprint' => $corpus->fingerprint,
                'embedding_models' => collect($models)->mapWithKeys(
                    static fn (object $row): array => [(string) $row->model => (int) $row->total],
                )->all(),
            ];
        } catch (Throwable) {
            return ['chunks' => null, 'fingerprint' => null, 'embedding_models' => []];
        }
    }

    /**
     * A run is invalid when more than $threshold of its cases failed for infrastructure reasons
     * (provider outage, rate limit, embedding fallback) rather than on their merits: its rates
     * would measure provider availability, not the assistant.
     *
     * @param  array<string, int>  $infrastructureFailures  reason => count
     * @return array{valid: bool, infrastructure_failures: array<string, int>, infrastructure_failure_rate: float, threshold: float}
     */
    public static function validity(int $total, array $infrastructureFailures, float $threshold = 0.05): array
    {
        $failed = array_sum($infrastructureFailures);
        $rate = $total > 0 ? $failed / $total : 0.0;

        return [
            'valid' => $total > 0 && $rate <= $threshold,
            'infrastructure_failures' => $infrastructureFailures,
            'infrastructure_failure_rate' => round($rate, 4),
            'threshold' => $threshold,
        ];
    }
}
