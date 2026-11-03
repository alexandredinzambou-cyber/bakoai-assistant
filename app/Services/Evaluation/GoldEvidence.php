<?php

namespace App\Services\Evaluation;

use Illuminate\Support\Str;

/**
 * Chunk-id-free relevance judgments for the gold retrieval set.
 *
 * A case lists evidence groups: the answer needs every group, and a group is satisfied by any
 * one of its alternatives. An alternative is a normalized phrase that must appear verbatim in a
 * retrieved chunk, or a set of tokens that must all appear in it. Matching on text rather than
 * on chunk ids keeps the gold set valid across re-ingestion, where every id changes.
 */
class GoldEvidence
{
    /**
     * Lowercase ASCII words separated by single spaces, padded with one space on each side so a
     * phrase matches on whole words only.
     */
    public static function normalize(string $text): string
    {
        $ascii = Str::ascii(mb_strtolower($text));

        return ' '.trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', $ascii))).' ';
    }

    /**
     * @param  array{phrase?: string, all?: list<string>}  $evidence
     */
    public static function matches(array $evidence, string $normalizedText): bool
    {
        if (isset($evidence['phrase'])) {
            $phrase = trim(self::normalize((string) $evidence['phrase']));

            return $phrase !== '' && str_contains($normalizedText, ' '.$phrase.' ');
        }

        $tokens = array_values(array_filter(array_map(
            static fn (string $token): string => trim(self::normalize($token)),
            (array) ($evidence['all'] ?? []),
        )));

        if ($tokens === []) {
            return false;
        }

        foreach ($tokens as $token) {
            if (! str_contains($normalizedText, ' '.$token.' ')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Retrieval metrics of one case over a ranked list of chunk texts (already cut to top-k).
     *
     * - full: every evidence group is satisfied by some chunk;
     * - group_recall: share of the groups satisfied;
     * - hit: at least one group satisfied;
     * - reciprocal_rank: 1 / rank of the first chunk that satisfies any group;
     * - rank_to_full: rank at which the last missing group gets satisfied (null if never);
     * - ndcg: each group scores 1/log2(rank + 1) at the rank of its first match, against the
     *   ideal of one group per rank from rank 1; capped at 1 when one chunk carries several;
     * - evidence_precision: share of the retrieved chunks that match at least one alternative.
     *   A lower bound of context precision: a chunk can be relevant without quoting the gold.
     *
     * @param  list<list<array{phrase?: string, all?: list<string>}>>  $groups
     * @param  list<string>  $texts  Raw chunk texts, in rank order.
     * @return array{full: bool, groups_total: int, groups_satisfied: int, group_recall: float, hit: bool, reciprocal_rank: float, rank_to_full: ?int, ndcg: float, evidence_precision: float, missing_groups: list<int>, group_ranks: list<?int>}
     */
    public static function score(array $groups, array $texts): array
    {
        $normalized = array_map(static fn (string $text): string => self::normalize($text), array_values($texts));
        $groupRanks = [];
        $matchingChunks = [];

        foreach ($groups as $group) {
            $rank = null;

            foreach ($normalized as $index => $text) {
                foreach ($group as $evidence) {
                    if (self::matches($evidence, $text)) {
                        $matchingChunks[$index] = true;
                        $rank ??= $index + 1;
                    }
                }
            }

            $groupRanks[] = $rank;
        }

        $total = count($groups);
        $satisfiedRanks = array_values(array_filter($groupRanks, static fn (?int $rank): bool => $rank !== null));
        $satisfied = count($satisfiedRanks);
        $idealDcg = 0.0;
        $dcg = 0.0;

        for ($i = 1; $i <= $total; $i++) {
            $idealDcg += 1 / log($i + 1, 2);
        }

        foreach ($satisfiedRanks as $rank) {
            $dcg += 1 / log($rank + 1, 2);
        }

        $missing = [];

        foreach ($groupRanks as $index => $rank) {
            if ($rank === null) {
                $missing[] = $index;
            }
        }

        return [
            'full' => $total > 0 && $satisfied === $total,
            'groups_total' => $total,
            'groups_satisfied' => $satisfied,
            'group_recall' => $total > 0 ? round($satisfied / $total, 4) : 0.0,
            'hit' => $satisfied > 0,
            'reciprocal_rank' => $satisfiedRanks === [] ? 0.0 : round(1 / min($satisfiedRanks), 4),
            'rank_to_full' => $total > 0 && $satisfied === $total ? max($satisfiedRanks) : null,
            'ndcg' => $idealDcg > 0 ? round(min(1.0, $dcg / $idealDcg), 4) : 0.0,
            'evidence_precision' => $normalized === [] ? 0.0 : round(count($matchingChunks) / count($normalized), 4),
            'missing_groups' => $missing,
            'group_ranks' => $groupRanks,
        ];
    }
}
