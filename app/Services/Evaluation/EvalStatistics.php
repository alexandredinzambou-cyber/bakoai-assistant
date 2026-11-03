<?php

namespace App\Services\Evaluation;

/**
 * Small-sample statistics for evaluation reports. On a benchmark of a few dozen questions a
 * rate without its interval reads as more precise than it is, and two runs can only be told
 * apart by a paired test on the same questions.
 */
class EvalStatistics
{
    /**
     * Wilson score interval of a proportion (95% by default). Unlike the normal approximation it
     * stays inside [0, 1] and is usable at 0/n and n/n.
     *
     * @return array{low: float, high: float}|null
     */
    public static function wilson(int $successes, int $total, float $z = 1.96): ?array
    {
        if ($total <= 0) {
            return null;
        }

        $p = $successes / $total;
        $z2 = $z * $z;
        $denominator = 1 + $z2 / $total;
        $centre = ($p + $z2 / (2 * $total)) / $denominator;
        $margin = $z * sqrt($p * (1 - $p) / $total + $z2 / (4 * $total * $total)) / $denominator;

        return [
            'low' => round(max(0.0, $centre - $margin), 4),
            'high' => round(min(1.0, $centre + $margin), 4),
        ];
    }

    /**
     * Exact two-sided McNemar test on the discordant pairs of a paired comparison: $b cases pass
     * only in the first run, $c only in the second. Returns 1.0 when there is no discordance.
     */
    public static function mcnemarExact(int $b, int $c): float
    {
        $n = $b + $c;

        if ($n === 0) {
            return 1.0;
        }

        $tail = 0.0;

        for ($i = 0; $i <= min($b, $c); $i++) {
            $tail += exp(self::logBinomial($n, $i) - $n * log(2));
        }

        return round(min(1.0, 2 * $tail), 4);
    }

    /**
     * Paired comparison of two runs on their common case ids.
     *
     * @param  array<string, bool>  $before  case id => passed in the baseline run
     * @param  array<string, bool>  $after  case id => passed in the current run
     * @return array{paired: int, only_before: list<string>, only_after: list<string>, p_value: float}
     */
    public static function pairedComparison(array $before, array $after): array
    {
        $common = array_values(array_intersect(array_keys($before), array_keys($after)));
        $onlyBefore = [];
        $onlyAfter = [];

        foreach ($common as $id) {
            if ($before[$id] && ! $after[$id]) {
                $onlyBefore[] = (string) $id;
            } elseif (! $before[$id] && $after[$id]) {
                $onlyAfter[] = (string) $id;
            }
        }

        return [
            'paired' => count($common),
            'only_before' => $onlyBefore,
            'only_after' => $onlyAfter,
            'p_value' => self::mcnemarExact(count($onlyBefore), count($onlyAfter)),
        ];
    }

    /**
     * @param  list<int|float>  $values
     */
    public static function mean(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 4);
    }

    private static function logBinomial(int $n, int $k): float
    {
        $result = 0.0;

        for ($i = 1; $i <= $k; $i++) {
            $result += log($n - $k + $i) - log($i);
        }

        return $result;
    }
}
