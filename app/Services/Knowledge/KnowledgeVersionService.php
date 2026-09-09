<?php

namespace App\Services\Knowledge;

use App\Exceptions\KnowledgeVersionTransitionException;
use App\Models\DocumentApi;
use App\Models\KnowledgeVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class KnowledgeVersionService
{
    public function createStaging(string $version, array $metadata = []): KnowledgeVersion
    {
        $version = trim($version);

        if ($version === '') {
            throw new InvalidArgumentException('Knowledge version identifier cannot be empty.');
        }

        return DB::transaction(fn (): KnowledgeVersion => KnowledgeVersion::create([
            'version' => $version,
            'statut' => 'staging',
            'metadata' => $metadata,
        ]), 3);
    }

    public function markValidated(KnowledgeVersion|int $version, array $metadata = []): KnowledgeVersion
    {
        return DB::transaction(function () use ($version, $metadata): KnowledgeVersion {
            $versions = $this->lockVersions();
            $target = $this->resolveLocked($versions, $version);

            if (! in_array($target->statut, ['staging', 'failed'], true)) {
                throw new KnowledgeVersionTransitionException(
                    'invalid_state',
                    "La version [{$target->version}] ne peut pas etre validee depuis le statut [{$target->statut}].",
                );
            }

            $this->preflight($target);
            $this->refreshCounts($target);
            $target->forceFill([
                'statut' => 'validated',
                'metadata' => $this->mergeMetadata($target, $metadata),
            ])->save();

            return $target->refresh();
        }, 3);
    }

    public function activate(KnowledgeVersion|int $version): KnowledgeVersion
    {
        return DB::transaction(function () use ($version): KnowledgeVersion {
            $versions = $this->lockVersions();
            $target = $this->resolveLocked($versions, $version);

            if ($target->statut !== 'validated') {
                throw new KnowledgeVersionTransitionException(
                    'invalid_state',
                    "La version [{$target->version}] doit etre validee avant son activation.",
                );
            }

            $preflight = $this->preflight($target);
            $this->demoteActiveVersions($versions, $target->id);
            $this->publishDocuments($target, $preflight['publishable_document_ids']);
            $this->refreshCounts($target);
            $target->forceFill([
                'statut' => 'active',
                'date_activation' => now(),
            ])->save();

            return $target->refresh();
        }, 3);
    }

    public function fail(KnowledgeVersion|int $version, string $reason, array $metadata = []): KnowledgeVersion
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A failure reason is required.');
        }

        return DB::transaction(function () use ($version, $reason, $metadata): KnowledgeVersion {
            $versions = $this->lockVersions();
            $target = $this->resolveLocked($versions, $version);

            if ($target->statut === 'active') {
                throw new KnowledgeVersionTransitionException(
                    'invalid_state',
                    'La version de connaissance active ne peut pas etre marquee en echec.',
                );
            }

            $target->forceFill([
                'statut' => 'failed',
                'metadata' => $this->mergeMetadata($target, [
                    ...$metadata,
                    'failure_reason' => $reason,
                    'failed_at' => now()->toIso8601String(),
                ]),
            ])->save();

            return $target->refresh();
        }, 3);
    }

    public function rollback(KnowledgeVersion|int|null $version = null): KnowledgeVersion
    {
        return DB::transaction(function () use ($version): KnowledgeVersion {
            $versions = $this->lockVersions();
            $active = $versions->firstWhere('statut', 'active');

            if (! $active instanceof KnowledgeVersion) {
                throw new KnowledgeVersionTransitionException(
                    'no_active_version',
                    'Aucune version de connaissance active ne peut etre restauree.',
                );
            }

            $target = $version === null
                ? $versions
                    ->filter(fn (KnowledgeVersion $candidate): bool => $candidate->statut === 'validated'
                        && $candidate->date_activation !== null)
                    ->sort(function (KnowledgeVersion $left, KnowledgeVersion $right): int {
                        $byActivation = $right->date_activation->getTimestamp()
                            <=> $left->date_activation->getTimestamp();

                        return $byActivation !== 0 ? $byActivation : $right->id <=> $left->id;
                    })
                    ->first()
                : $this->resolveLocked($versions, $version);

            if (! $target instanceof KnowledgeVersion || $target->statut !== 'validated') {
                throw new KnowledgeVersionTransitionException(
                    'invalid_rollback_target',
                    'Le rollback exige une version de connaissance precedemment validee.',
                );
            }

            $preflight = $this->preflight($target);
            $active->forceFill(['statut' => 'validated'])->save();
            $this->publishDocuments($target, $preflight['publishable_document_ids']);
            $this->refreshCounts($target);
            $target->forceFill([
                'statut' => 'active',
                'date_activation' => now(),
            ])->save();

            return $target->refresh();
        }, 3);
    }

    /**
     * @return Collection<int, KnowledgeVersion>
     */
    private function lockVersions(): Collection
    {
        return KnowledgeVersion::query()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function resolveLocked(Collection $versions, KnowledgeVersion|int $version): KnowledgeVersion
    {
        $id = $version instanceof KnowledgeVersion ? $version->getKey() : $version;
        $target = $versions->firstWhere('id', $id);

        if (! $target instanceof KnowledgeVersion) {
            throw new KnowledgeVersionTransitionException(
                'version_not_found',
                "La version de connaissance [{$id}] est introuvable.",
            );
        }

        return $target;
    }

    private function demoteActiveVersions(Collection $versions, int $exceptId): void
    {
        $versions
            ->filter(fn (KnowledgeVersion $candidate): bool => $candidate->id !== $exceptId
                && $candidate->statut === 'active')
            ->each(function (KnowledgeVersion $active): void {
                $active->forceFill(['statut' => 'validated'])->save();
            });
    }

    private function refreshCounts(KnowledgeVersion $version): void
    {
        $version->forceFill([
            'nombre_documents' => DocumentApi::query()
                ->where('knowledge_version_id', $version->id)
                ->count(),
            'nombre_chunks' => DB::table('chunks')
                ->join('documents_api', 'documents_api.id', '=', 'chunks.document_id')
                ->where('documents_api.knowledge_version_id', $version->id)
                ->where('chunks.status', 'active')
                ->count(),
        ]);
    }

    /**
     * @param  list<int>  $publishableDocumentIds
     */
    private function publishDocuments(KnowledgeVersion $version, array $publishableDocumentIds): void
    {
        if ($publishableDocumentIds === []) {
            throw new KnowledgeVersionTransitionException(
                'no_publishable_document',
                'La version de connaissance ne contient aucun document verifie publiable.',
            );
        }

        // Deactivate first inside the same transaction so the partial unique
        // source index is never violated while a new corpus is published.
        DocumentApi::query()->where('actif', true)->update(['actif' => false]);
        DB::table('open_api_specs')->where('actif', true)->update(['actif' => false]);
        $publishedDocuments = DocumentApi::query()
            ->where('knowledge_version_id', $version->id)
            ->whereIn('id', $publishableDocumentIds)
            ->whereNotNull('link_verified_at')
            ->update(['actif' => true]);

        if ($publishedDocuments !== count($publishableDocumentIds)) {
            throw new KnowledgeVersionTransitionException(
                'publication_changed',
                'Le corpus a change pendant sa publication; aucune modification n a ete conservee.',
            );
        }

        DB::table('open_api_specs')
            ->whereIn('document_id', $publishableDocumentIds)
            ->update(['actif' => true]);
    }

    /**
     * @return array{publishable_document_ids: list<int>, document_count: int, chunk_count: int, embedding_model: string}
     */
    private function preflight(KnowledgeVersion $version): array
    {
        $documents = DocumentApi::query()
            ->where('knowledge_version_id', $version->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'lien_officiel', 'link_verified_at', 'has_known_conflicts']);

        if ($documents->isEmpty()) {
            throw new KnowledgeVersionTransitionException(
                'empty_version',
                'La version de connaissance ne contient aucun document.',
            );
        }

        $hasVerifiedNonConflictingDocument = $documents->contains(
            static fn (DocumentApi $document): bool => $document->lien_officiel !== null
                && $document->link_verified_at !== null
                && ! $document->has_known_conflicts,
        );

        if (! $hasVerifiedNonConflictingDocument) {
            throw new KnowledgeVersionTransitionException(
                'no_verified_non_conflicting_document',
                'La version doit contenir au moins un document verifie sans conflit connu.',
            );
        }

        if ($documents->contains(
            static fn (DocumentApi $document): bool => $document->lien_officiel === null
                || $document->link_verified_at === null,
        )) {
            throw new KnowledgeVersionTransitionException(
                'unverified_document',
                'Chaque document de la version doit posseder une source officielle verifiee.',
            );
        }

        $duplicateSource = $documents
            ->filter(static fn (DocumentApi $document): bool => $document->lien_officiel !== null)
            ->groupBy('lien_officiel')
            ->first(static fn (Collection $sourceDocuments): bool => $sourceDocuments->count() > 1);

        if ($duplicateSource !== null) {
            throw new KnowledgeVersionTransitionException(
                'duplicate_source',
                'Une meme source documentaire ne peut apparaitre plusieurs fois dans une version.',
            );
        }

        $documentIds = $documents->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $chunks = DB::table('chunks')
            ->whereIn('document_id', $documentIds)
            ->where('status', 'active')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'document_id']);
        $chunkCounts = $chunks->countBy(static fn (object $chunk): int => (int) $chunk->document_id);

        if ($documents->contains(
            static fn (DocumentApi $document): bool => (int) $chunkCounts->get($document->id, 0) < 1,
        )) {
            throw new KnowledgeVersionTransitionException(
                'document_without_chunks',
                'Chaque document de la version doit contenir au moins un chunk.',
            );
        }

        $chunkIds = $chunks->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $embeddings = DB::table('embeddings')
            ->whereIn('chunk_id', $chunkIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'chunk_id', 'modele', 'vecteur']);
        $embeddingsByChunk = $embeddings->groupBy(static fn (object $embedding): int => (int) $embedding->chunk_id);

        foreach ($chunkIds as $chunkId) {
            $chunkEmbeddings = $embeddingsByChunk->get($chunkId, collect());

            if ($chunkEmbeddings->count() !== 1 || $chunkEmbeddings->first()->vecteur === null) {
                throw new KnowledgeVersionTransitionException(
                    'invalid_chunk_embedding',
                    'Chaque chunk de la version doit posseder exactement un embedding non nul.',
                );
            }
        }

        $embeddingModels = $embeddings
            ->map(static fn (object $embedding): string => trim((string) $embedding->modele));

        if ($embeddingModels->contains('') || $embeddingModels->unique()->count() !== 1) {
            throw new KnowledgeVersionTransitionException(
                'mixed_embedding_models',
                'Tous les embeddings de la version doivent utiliser un seul modele non vide.',
            );
        }

        $expectedEmbeddingModel = $this->expectedEmbeddingModel();

        if ($embeddingModels->first() !== $expectedEmbeddingModel) {
            throw new KnowledgeVersionTransitionException(
                'embedding_model_mismatch',
                'Le modele d embedding de la version ne correspond pas au modele configure pour le retrieval.',
            );
        }

        return [
            'publishable_document_ids' => $documents
                ->filter(static fn (DocumentApi $document): bool => $document->link_verified_at !== null)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->values()
                ->all(),
            'document_count' => $documents->count(),
            'chunk_count' => $chunks->count(),
            'embedding_model' => $embeddingModels->first(),
        ];
    }

    private function mergeMetadata(KnowledgeVersion $version, array $metadata): array
    {
        return array_replace($version->metadata ?? [], $metadata);
    }

    private function expectedEmbeddingModel(): string
    {
        $provider = trim((string) config('embedding.default_provider', 'nvidia'));
        $model = trim((string) config("embedding.providers.{$provider}.model", ''));

        if ($provider === '' || $model === '') {
            throw new KnowledgeVersionTransitionException(
                'embedding_configuration_invalid',
                'Le fournisseur d embeddings actif est incompletement configure.',
            );
        }

        return $provider.':'.$model;
    }
}
