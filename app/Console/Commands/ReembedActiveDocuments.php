<?php

namespace App\Console\Commands;

use App\Models\DocumentApi;
use App\Services\Rag\EmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ReembedActiveDocuments extends Command
{
    protected $signature = 'rag:reembed
        {--provider= : Provider to use; defaults to EMBEDDING_DEFAULT_PROVIDER}
        {--all : Re-embed every document, including inactive historical documents}';

    protected $description = 'Atomically replace embeddings for all active documents with one provider and model.';

    public function handle(EmbeddingService $embeddingService): int
    {
        $provider = trim((string) ($this->option('provider') ?: config('embedding.default_provider', 'gemini')));
        $dimensions = (int) config('embedding.dimensions', 768);
        $includeInactive = (bool) $this->option('all');
        $documents = DocumentApi::query()
            ->when(! $includeInactive, fn ($query) => $query->where('actif', true))
            ->with(['chunks' => fn ($query) => $query->orderBy('position')])
            ->orderBy('id')
            ->get();

        $chunkCount = $documents->sum(fn (DocumentApi $document): int => $document->chunks->count());

        if ($chunkCount === 0) {
            $this->error($includeInactive
                ? 'No chunks are available to re-embed.'
                : 'No chunks from active documents are available to re-embed.');

            return self::FAILURE;
        }

        $scope = $includeInactive ? 'all documents' : 'active documents';
        $this->line("Preparing {$chunkCount} embeddings from {$scope} with provider [{$provider}] before the atomic database update.");
        $prepared = [];
        $indexModel = null;

        try {
            foreach ($documents as $document) {
                foreach ($document->chunks as $chunk) {
                    $vector = $embeddingService->embedWithProvider(
                        $provider,
                        $chunk->contenu,
                        'document',
                        $document->titre,
                    );
                    $model = $embeddingService->lastModel();
                    $indexModel ??= $model;

                    if ($model !== $indexModel) {
                        throw new RuntimeException('Re-embedding aborted because the provider model changed during preparation.');
                    }

                    if (count($vector) !== $dimensions) {
                        throw new RuntimeException('Re-embedding aborted because a vector has an unexpected dimension.');
                    }

                    $prepared[] = [
                        'chunk_id' => $chunk->id,
                        'modele' => $model,
                        'vecteur' => $embeddingService->toSqlLiteral($vector),
                    ];
                }
            }

            DB::transaction(function () use ($prepared): void {
                foreach ($prepared as $embedding) {
                    if (DB::getDriverName() === 'pgsql') {
                        $updated = DB::update(
                            'UPDATE embeddings SET modele = ?, vecteur = ?::vector, updated_at = now() WHERE chunk_id = ?',
                            [$embedding['modele'], $embedding['vecteur'], $embedding['chunk_id']],
                        );

                        if ($updated === 0) {
                            DB::insert(
                                'INSERT INTO embeddings (chunk_id, modele, vecteur, created_at, updated_at) VALUES (?, ?, ?::vector, now(), now())',
                                [$embedding['chunk_id'], $embedding['modele'], $embedding['vecteur']],
                            );
                        }

                        continue;
                    }

                    DB::table('embeddings')->updateOrInsert(
                        ['chunk_id' => $embedding['chunk_id']],
                        [
                            'modele' => $embedding['modele'],
                            'vecteur' => $embedding['vecteur'],
                            'updated_at' => now(),
                        ],
                    );
                }
            });
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            $this->warn('No embedding was changed in the database.');

            return self::FAILURE;
        }

        $this->info("Re-embedded {$chunkCount} chunks atomically with [{$indexModel}].");

        return self::SUCCESS;
    }
}
