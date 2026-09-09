<?php

namespace App\Services\Rag;

use Illuminate\Support\Collection;

final class ReciprocalRankFusion
{
    /**
     * Merge independently ranked candidate lists without assuming their raw
     * vector and lexical scores share the same scale.
     *
     * @param  array<string, Collection<int, object>>  $rankings
     * @param  array<string, float>  $weights
     * @return Collection<int, object>
     */
    public function fuse(array $rankings, array $weights = [], int $rankConstant = 60): Collection
    {
        $rankConstant = max(1, $rankConstant);
        $candidates = [];

        foreach ($rankings as $name => $ranking) {
            $weight = max(0.0, (float) ($weights[$name] ?? 1.0));

            if ($weight === 0.0) {
                continue;
            }

            foreach ($ranking->values() as $offset => $candidate) {
                $chunkId = (int) data_get($candidate, 'chunk_id');

                if ($chunkId <= 0) {
                    continue;
                }

                if (! isset($candidates[$chunkId])) {
                    $candidates[$chunkId] = clone $candidate;
                    $candidates[$chunkId]->fusion_score = 0.0;
                    $candidates[$chunkId]->retrieval_ranks = [];
                } else {
                    $this->mergeMissingAttributes($candidates[$chunkId], $candidate);
                }

                $rank = $offset + 1;
                $candidates[$chunkId]->fusion_score += $weight / ($rankConstant + $rank);
                $candidates[$chunkId]->retrieval_ranks[$name] = $rank;
            }
        }

        return collect(array_values($candidates))
            ->sortByDesc(static fn (object $candidate): float => (float) $candidate->fusion_score)
            ->values();
    }

    private function mergeMissingAttributes(object $target, object $source): void
    {
        foreach (get_object_vars($source) as $attribute => $value) {
            if ($attribute === 'lexical_rank' && is_numeric($value)) {
                $target->{$attribute} = max((float) ($target->{$attribute} ?? 0.0), (float) $value);

                continue;
            }

            if ($attribute === 'distance' && is_numeric($value)) {
                $target->{$attribute} = ! isset($target->{$attribute}) || ! is_numeric($target->{$attribute})
                    ? (float) $value
                    : min((float) $target->{$attribute}, (float) $value);

                continue;
            }

            if (! property_exists($target, $attribute) || $target->{$attribute} === null) {
                $target->{$attribute} = $value;
            }
        }
    }
}
