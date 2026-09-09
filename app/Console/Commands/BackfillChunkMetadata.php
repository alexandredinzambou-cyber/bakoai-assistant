<?php

namespace App\Console\Commands;

use App\Models\Chunk;
use App\Services\Rag\ChunkMetadataExtractor;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class BackfillChunkMetadata extends Command
{
    protected $signature = 'rag:backfill-metadata
        {--all : Recompute fields that already contain a value}
        {--dry-run : Count eligible chunks without updating them}';

    protected $description = 'Populate structured RAG filters on existing chunks without changing content or embeddings.';

    public function handle(ChunkMetadataExtractor $extractor): int
    {
        $query = Chunk::query()->with('document.moyenPaiement');

        if (! $this->option('all')) {
            $query->where(function (Builder $builder): void {
                $builder->whereNull('token_count')
                    ->orWhereNull('payment_method')
                    ->orWhereNull('section_slug')
                    ->orWhereNull('content_type')
                    ->orWhereNull('search_text');
            });
        }

        $eligible = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("{$eligible} chunk(s) eligible; no row was changed.");

            return self::SUCCESS;
        }

        $updated = 0;
        $recomputeAll = (bool) $this->option('all');

        $query->chunkById(100, function ($chunks) use ($extractor, $recomputeAll, &$updated): void {
            foreach ($chunks as $chunk) {
                $document = $chunk->document;
                $extracted = $extractor->extract(
                    $chunk->contenu,
                    $chunk->section,
                    $document?->moyenPaiement?->nom ?? 'PVIT',
                    $document?->lien_officiel,
                );
                $updates = [];

                foreach (['token_count', 'payment_method', 'section_slug', 'content_type', 'search_text', 'index_terms', 'operation', 'environment', 'http_method', 'endpoint', 'error_code', 'http_status'] as $field) {
                    if ($recomputeAll || $chunk->{$field} === null) {
                        $updates[$field] = $extracted[$field];
                    }
                }

                $updates['status'] = $chunk->status ?: 'active';
                $updates['metadata'] = array_replace($extracted['metadata'], $chunk->metadata ?? []);
                $chunk->forceFill($updates)->save();
                $updated++;
            }
        });

        $this->info("Structured metadata updated on {$updated} chunk(s); embeddings were not changed.");

        $propagated = $this->propagateOperationFromDocumentSiblings();
        $this->info("Operation backfilled by document-sibling propagation on {$propagated} chunk(s).");

        return self::SUCCESS;
    }

    /**
     * ChunkMetadataExtractor::operation() matches keywords in a single chunk's own text, so a
     * step that never repeats one of the trigger words -- e.g. an OTP-verification step inside a
     * registration flow -- is left untagged even though it plainly belongs to that operation.
     * When every other tagged chunk in the same document agrees on exactly one operation, that is
     * a reliable enough signal to backfill the untagged ones with it. A document whose tagged
     * chunks disagree (a general guide spanning several operations) is left alone rather than
     * guessing.
     */
    private function propagateOperationFromDocumentSiblings(): int
    {
        $updated = 0;

        Chunk::query()
            ->where('status', 'active')
            ->whereNull('operation')
            ->select('document_id')
            ->distinct()
            ->pluck('document_id')
            ->each(function (int $documentId) use (&$updated): void {
                $siblingOperations = Chunk::query()
                    ->where('document_id', $documentId)
                    ->where('status', 'active')
                    ->whereNotNull('operation')
                    ->pluck('operation')
                    ->unique();

                if ($siblingOperations->count() !== 1) {
                    return;
                }

                $updated += Chunk::query()
                    ->where('document_id', $documentId)
                    ->where('status', 'active')
                    ->whereNull('operation')
                    ->update(['operation' => $siblingOperations->first()]);
            });

        return $updated;
    }
}
