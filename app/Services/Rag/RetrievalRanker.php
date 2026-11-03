<?php

namespace App\Services\Rag;

use Illuminate\Support\Collection;

/**
 * Pure ranking rules applied by RetrievalService to the fused vector + lexical candidate pool.
 *
 * Cosine similarity is nearly flat on this corpus (0.16-0.33 across a whole top-40), so the
 * per-row boosts decide the final order. They used to be keyed on the single content_type label
 * ChunkMetadataExtractor assigns, which is often wrong (a webhook payload or acknowledgement
 * section mentions "champs"/"en-tete" and is labelled 'parameters'), and a +0.32 'parameters'
 * boost fired for any question containing "api". Together they buried chunks both retrievers
 * ranked first (an error-code table that is #1 vector AND #1 lexical ended up #24). The fused
 * rank is now the backbone. Since the knowledge base was rebuilt (section-aware chunks that
 * carry their heading path, metadata derived from the page structure), no keyword-triggered
 * topical boost is applied at all: measured on the chunk-level gold set it only hurt (a refund
 * question mentioning "callback" had its refund page pushed out by webhook chunks).
 */
final class RetrievalRanker
{
    public const HEADING_ONLY_PENALTY = 0.20;

    public const FUSION_WEIGHT = 0.30;

    // A chunk that is only its own "## Title" line (a section whose content was split into the
    // following chunks) carries nothing citable, yet its title matches the question as well as
    // any sibling's does.
    public function isHeadingOnly(string $content): bool
    {
        $body = (string) preg_replace('/\A\s*#{1,6}[^\n]*/u', '', $content, 1);

        return preg_match('/[\p{L}\p{N}]{2,}/u', $body) !== 1;
    }

    public function rankingScore(object $row, float $maxFusionScore): float
    {
        $fusion = $maxFusionScore > 0.0
            ? min(1.0, (float) ($row->fusion_score ?? 0.0) / $maxFusionScore)
            : 0.0;

        return (float) $row->relevance_score
            + min(1.0, (float) $row->lexical_rank) * 0.12
            + (float) $row->operation_boost
            + (float) $row->operation_metadata_boost
            + self::FUSION_WEIGHT * $fusion
            - (float) ($row->heading_only_penalty ?? 0.0);
    }

    /**
     * Stable re-order: once a document already holds $perDocument rows, its further rows move
     * behind every other document's, so one long tutorial cannot fill the whole context.
     *
     * @param  Collection<int, object>  $ranked
     * @return Collection<int, object>
     */
    public function diversify(Collection $ranked, int $perDocument): Collection
    {
        if ($perDocument <= 0) {
            return $ranked->values();
        }

        $counts = [];
        $kept = [];
        $overflow = [];

        foreach ($ranked as $row) {
            $documentId = (int) data_get($row, 'document_id');

            if (($counts[$documentId] ?? 0) < $perDocument) {
                $kept[] = $row;
                $counts[$documentId] = ($counts[$documentId] ?? 0) + 1;
            } else {
                $overflow[] = $row;
            }
        }

        return collect(array_merge($kept, $overflow));
    }

    /**
     * When positions p and p+2 of one document are both retrieved but p+1 is not, the missing
     * middle section is almost always the step between them (e.g. "Reception du webhook" and
     * "Regles de l'accuse de reception" retrieved, "Accuse de reception attendu" missing). Only a
     * single gap is bridged, only from the already-scored pool, and never with a heading-only row.
     *
     * @param  Collection<int, object>  $top
     * @param  Collection<int, object>  $pool
     * @return Collection<int, object>
     */
    public function gapBridgeRows(Collection $top, Collection $pool, int $maxRows): Collection
    {
        if ($maxRows <= 0) {
            return collect();
        }

        $positions = [];

        foreach ($top as $row) {
            $documentId = data_get($row, 'document_id');
            $position = data_get($row, 'position');

            if (is_numeric($documentId) && is_numeric($position)) {
                $positions[(int) $documentId][(int) $position] = $row;
            }
        }

        $poolByPosition = $pool
            ->filter(fn ($row): bool => is_numeric(data_get($row, 'document_id')) && is_numeric(data_get($row, 'position')))
            ->keyBy(fn ($row): string => (int) data_get($row, 'document_id').':'.(int) data_get($row, 'position'));
        $bridged = [];

        foreach ($positions as $documentId => $rows) {
            ksort($rows);

            foreach ($rows as $position => $seed) {
                if (count($bridged) >= $maxRows) {
                    break 2;
                }

                if (! isset($rows[$position + 2]) || isset($rows[$position + 1])) {
                    continue;
                }

                $candidate = $poolByPosition->get($documentId.':'.($position + 1));

                if ($candidate === null || $this->isHeadingOnly((string) data_get($candidate, 'contenu', ''))) {
                    continue;
                }

                $row = clone $candidate;
                $row->is_gap_bridge = true;
                $row->bridge_seed_chunk_ids = [
                    (int) data_get($seed, 'chunk_id'),
                    (int) data_get($rows[$position + 2], 'chunk_id'),
                ];
                $row->bridge_seed_relevance = min(
                    (float) data_get($seed, 'relevance_score', 0.0),
                    (float) data_get($rows[$position + 2], 'relevance_score', 0.0),
                );
                $bridged[] = $row;
            }
        }

        return collect($bridged);
    }
}
