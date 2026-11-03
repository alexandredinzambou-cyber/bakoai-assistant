<?php

namespace Tests\Unit\Evaluation;

use App\Services\Evaluation\EvalRunMetadata;
use App\Services\Evaluation\EvalStatistics;
use PHPUnit\Framework\TestCase;

class EvalStatisticsTest extends TestCase
{
    public function test_wilson_interval_matches_the_reference_values_and_stays_in_bounds(): void
    {
        // 8/10: the textbook Wilson 95% interval is [0.490, 0.943].
        $interval = EvalStatistics::wilson(8, 10);
        $this->assertEqualsWithDelta(0.4902, $interval['low'], 0.0005);
        $this->assertEqualsWithDelta(0.9433, $interval['high'], 0.0005);

        $none = EvalStatistics::wilson(0, 10);
        $this->assertSame(0.0, $none['low']);
        $this->assertGreaterThan(0.25, $none['high']);

        $all = EvalStatistics::wilson(10, 10);
        $this->assertSame(1.0, $all['high']);
        $this->assertLessThan(0.75, $all['low']);

        $this->assertNull(EvalStatistics::wilson(0, 0));
    }

    public function test_exact_mcnemar_uses_only_the_discordant_pairs(): void
    {
        $this->assertSame(1.0, EvalStatistics::mcnemarExact(0, 0));
        // 4 cases fixed and none broken: p = 2 * 0.5^4.
        $this->assertSame(0.125, EvalStatistics::mcnemarExact(0, 4));
        // 1 against 6: p = 2 * (1 + 7) / 2^7.
        $this->assertSame(0.125, EvalStatistics::mcnemarExact(1, 6));
        $this->assertSame(1.0, EvalStatistics::mcnemarExact(3, 3));
        $this->assertLessThan(0.05, EvalStatistics::mcnemarExact(0, 6));
    }

    public function test_paired_comparison_pairs_cases_by_id_only(): void
    {
        $comparison = EvalStatistics::pairedComparison(
            ['a' => true, 'b' => false, 'c' => false, 'gone' => true],
            ['a' => false, 'b' => true, 'c' => true, 'new' => false],
        );

        $this->assertSame(3, $comparison['paired']);
        $this->assertSame(['a'], $comparison['only_before']);
        $this->assertSame(['b', 'c'], $comparison['only_after']);
        $this->assertSame(1.0, $comparison['p_value']);
    }

    public function test_a_run_polluted_by_infrastructure_failures_is_invalid(): void
    {
        $this->assertTrue(EvalRunMetadata::validity(40, ['llm_rate_limited' => 2])['valid']);

        $polluted = EvalRunMetadata::validity(40, ['llm_rate_limited' => 2, 'embedding_fallback' => 1]);
        $this->assertFalse($polluted['valid']);
        $this->assertSame(0.075, $polluted['infrastructure_failure_rate']);

        $this->assertFalse(EvalRunMetadata::validity(0, [])['valid']);
    }
}
