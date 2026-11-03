<?php

namespace Tests\Unit\Rag;

use App\Services\Rag\RetrievalRanker;
use PHPUnit\Framework\TestCase;

class RetrievalRankerTest extends TestCase
{
    public function test_heading_only_detection(): void
    {
        $ranker = new RetrievalRanker;

        $this->assertTrue($ranker->isHeadingOnly('## Étapes pour configurer un callback'));
        $this->assertTrue($ranker->isHeadingOnly("## 1. Installation du plugin\n\n-"));
        $this->assertFalse($ranker->isHeadingOnly("## 401\n\nNon autorisé"));
        $this->assertFalse($ranker->isHeadingOnly("## Code callback\n\nRécupérez l'URL de callback post."));
        $this->assertFalse($ranker->isHeadingOnly('Le secret doit etre renouvele avant expiration.'));
    }

    public function test_a_row_both_retrievers_rank_first_outweighs_a_slightly_closer_cosine(): void
    {
        $ranker = new RetrievalRanker;
        $maxFusion = 1.85 / 61;

        $rankedFirstByBoth = $this->row(['relevance_score' => 0.27, 'lexical_rank' => 0.3, 'fusion_score' => $maxFusion]);
        $closerCosineOnly = $this->row(['relevance_score' => 0.33, 'lexical_rank' => 0.3, 'fusion_score' => 1.0 / 100]);

        $this->assertGreaterThan(
            $ranker->rankingScore($closerCosineOnly, $maxFusion),
            $ranker->rankingScore($rankedFirstByBoth, $maxFusion),
        );

        $stub = $this->row(['relevance_score' => 0.27, 'lexical_rank' => 0.3, 'fusion_score' => $maxFusion, 'heading_only_penalty' => RetrievalRanker::HEADING_ONLY_PENALTY]);
        $this->assertEqualsWithDelta(
            $ranker->rankingScore($rankedFirstByBoth, $maxFusion) - RetrievalRanker::HEADING_ONLY_PENALTY,
            $ranker->rankingScore($stub, $maxFusion),
            1e-9,
        );
    }

    public function test_diversify_moves_a_documents_extra_rows_behind_other_documents(): void
    {
        $ranker = new RetrievalRanker;
        $ranked = collect([
            $this->row(['chunk_id' => 1, 'document_id' => 7]),
            $this->row(['chunk_id' => 2, 'document_id' => 7]),
            $this->row(['chunk_id' => 3, 'document_id' => 7]),
            $this->row(['chunk_id' => 4, 'document_id' => 7]),
            $this->row(['chunk_id' => 5, 'document_id' => 8]),
            $this->row(['chunk_id' => 6, 'document_id' => 7]),
            $this->row(['chunk_id' => 7, 'document_id' => 9]),
        ]);

        $this->assertSame([1, 2, 3, 5, 7, 4, 6], $ranker->diversify($ranked, 3)->pluck('chunk_id')->all());
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $ranker->diversify($ranked, 0)->pluck('chunk_id')->all());
    }

    public function test_gap_bridge_appends_the_single_missing_section_between_two_retrieved_siblings(): void
    {
        $ranker = new RetrievalRanker;
        $pool = collect([
            $this->row(['chunk_id' => 439, 'document_id' => 93, 'position' => 14, 'relevance_score' => 0.40]),
            $this->row(['chunk_id' => 440, 'document_id' => 93, 'position' => 15, 'relevance_score' => 0.10, 'contenu' => "## Accusé de réception attendu\n\nRépondez HTTP 200."]),
            $this->row(['chunk_id' => 441, 'document_id' => 93, 'position' => 16, 'relevance_score' => 0.37]),
            $this->row(['chunk_id' => 50, 'document_id' => 5, 'position' => 1]),
            $this->row(['chunk_id' => 51, 'document_id' => 5, 'position' => 2, 'contenu' => '## Étapes']),
            $this->row(['chunk_id' => 52, 'document_id' => 5, 'position' => 3]),
            $this->row(['chunk_id' => 60, 'document_id' => 6, 'position' => 10]),
            $this->row(['chunk_id' => 61, 'document_id' => 6, 'position' => 11]),
        ]);
        $top = $pool->whereIn('chunk_id', [439, 441, 50, 52, 60, 61])->values();

        $bridged = $ranker->gapBridgeRows($top, $pool, 2);

        // 51 is heading-only; 60/61 are adjacent, nothing to bridge.
        $this->assertSame([440], $bridged->pluck('chunk_id')->all());
        $this->assertTrue($bridged->first()->is_gap_bridge);
        $this->assertSame([439, 441], $bridged->first()->bridge_seed_chunk_ids);
        $this->assertSame(0.37, $bridged->first()->bridge_seed_relevance);
        $this->assertObjectNotHasProperty('is_gap_bridge', $pool->firstWhere('chunk_id', 440));

        $this->assertTrue($ranker->gapBridgeRows($top, $pool, 0)->isEmpty());
        $this->assertTrue($ranker->gapBridgeRows($top, $pool->where('chunk_id', '!=', 440), 2)->isEmpty());

        // A gap of two sections is never bridged.
        $this->assertTrue($ranker->gapBridgeRows($pool->whereIn('chunk_id', [60])->concat([
            $this->row(['chunk_id' => 63, 'document_id' => 6, 'position' => 13]),
        ]), $pool, 2)->isEmpty());
    }

    private function row(array $attributes): object
    {
        return (object) array_merge([
            'chunk_id' => 1,
            'document_id' => 1,
            'position' => 1,
            'section' => 'Section',
            'section_slug' => 'section',
            'content_type' => 'prose',
            'contenu' => "## Section\n\nContenu documentaire.",
            'relevance_score' => 0.3,
            'lexical_rank' => 0.0,
            'operation_boost' => 0.0,
            'operation_metadata_boost' => 0.0,
            'content_type_boost' => 0.0,
            'fusion_score' => 0.0,
        ], $attributes);
    }
}
