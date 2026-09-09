<?php

namespace Tests\Unit\Rag;

use App\Services\Rag\ReciprocalRankFusion;
use PHPUnit\Framework\TestCase;

class ReciprocalRankFusionTest extends TestCase
{
    public function test_it_rewards_candidates_found_by_both_retrievers(): void
    {
        $fusion = new ReciprocalRankFusion;

        $results = $fusion->fuse([
            'vector' => collect([
                (object) ['chunk_id' => 1, 'distance' => 0.08, 'lexical_rank' => 0.0],
                (object) ['chunk_id' => 2, 'distance' => 0.10, 'lexical_rank' => 0.0],
            ]),
            'lexical' => collect([
                (object) ['chunk_id' => 2, 'lexical_rank' => 0.9],
                (object) ['chunk_id' => 3, 'lexical_rank' => 0.8],
            ]),
        ], ['vector' => 1.0, 'lexical' => 0.8]);

        $this->assertSame([2, 1, 3], $results->pluck('chunk_id')->all());
        $this->assertSame(['vector' => 2, 'lexical' => 1], $results->first()->retrieval_ranks);
        $this->assertSame(0.10, $results->first()->distance);
        $this->assertSame(0.9, $results->first()->lexical_rank);
    }

    public function test_it_ignores_invalid_candidates_and_zero_weight_rankings(): void
    {
        $fusion = new ReciprocalRankFusion;

        $results = $fusion->fuse([
            'vector' => collect([(object) ['chunk_id' => 0]]),
            'lexical' => collect([(object) ['chunk_id' => 7]]),
        ], ['vector' => 1.0, 'lexical' => 0.0]);

        $this->assertTrue($results->isEmpty());
    }
}
