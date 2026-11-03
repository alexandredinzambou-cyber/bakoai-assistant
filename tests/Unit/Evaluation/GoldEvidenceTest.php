<?php

namespace Tests\Unit\Evaluation;

use App\Services\Evaluation\GoldEvidence;
use Tests\TestCase;

class GoldEvidenceTest extends TestCase
{
    public function test_normalization_folds_accents_ligatures_and_punctuation(): void
    {
        $this->assertSame(' au coeur de l integration x secret ', GoldEvidence::normalize("Au cœur de l'intégration : `X-Secret`."));
    }

    public function test_a_phrase_matches_on_whole_words_and_a_token_set_needs_every_token(): void
    {
        $text = GoldEvidence::normalize('Le champ callback_url_code contient le code de l’URL de callback.');

        $this->assertTrue(GoldEvidence::matches(['phrase' => 'callback url code contient le code'], $text));
        // "code" must not match inside "codes", nor a phrase across a missing word.
        $this->assertFalse(GoldEvidence::matches(['phrase' => 'le codes'], $text));
        $this->assertFalse(GoldEvidence::matches(['phrase' => 'callback url contient'], $text));
        $this->assertTrue(GoldEvidence::matches(['all' => ['callback_url_code', 'URL']], $text));
        $this->assertFalse(GoldEvidence::matches(['all' => ['callback', 'webhook']], $text));
        $this->assertFalse(GoldEvidence::matches(['all' => []], $text));
    }

    public function test_score_reports_coverage_ranks_and_precision(): void
    {
        $groups = [
            [['phrase' => 'cliquez sur parametrages'], ['phrase' => 'menu parametrages']],
            [['phrase' => 'un code unique sera genere']],
            [['all' => ['ipv4', 'valide']]],
        ];
        $texts = [
            'Page sans rapport avec la question.',
            'Dans l’espace marchand, cliquez sur « Paramétrages ».',
            'Rien ici non plus.',
            'Un code unique sera généré automatiquement.',
        ];

        $score = GoldEvidence::score($groups, $texts);

        $this->assertFalse($score['full']);
        $this->assertSame(2, $score['groups_satisfied']);
        $this->assertSame([2, 4, null], $score['group_ranks']);
        $this->assertSame([2], $score['missing_groups']);
        $this->assertEqualsWithDelta(0.6667, $score['group_recall'], 0.0001);
        $this->assertTrue($score['hit']);
        $this->assertSame(0.5, $score['reciprocal_rank']);
        $this->assertNull($score['rank_to_full']);
        $this->assertSame(0.5, $score['evidence_precision']);
        // (1/log2(3) + 1/log2(5)) / (1 + 1/log2(3) + 1/log2(4)) = 1.0616 / 2.1309
        $this->assertEqualsWithDelta(0.4982, $score['ndcg'], 0.0005);

        $complete = GoldEvidence::score($groups, [
            'cliquez sur parametrages puis saisissez une IPv4 valide',
            'un code unique sera genere',
        ]);
        $this->assertTrue($complete['full']);
        $this->assertSame(2, $complete['rank_to_full']);
        $this->assertSame(1.0, $complete['reciprocal_rank']);
        // Two groups in the first chunk score above the one-group-per-rank ideal: capped at 1.
        $this->assertSame(1.0, $complete['ndcg']);
    }

    public function test_the_versioned_gold_dataset_is_well_formed(): void
    {
        $dataset = json_decode((string) file_get_contents(base_path('database/seeders/eval_gold.json')), true);

        $this->assertIsString($dataset['version']);
        $ids = array_column($dataset['cases'], 'id');
        $this->assertSame(count($ids), count(array_unique($ids)), 'Case ids must be unique.');

        foreach ($dataset['cases'] as $case) {
            $this->assertContains($case['split'], ['dev', 'test'], $case['id']);
            $this->assertContains($case['behavior'], ['answer', 'decline', 'abstain'], $case['id']);
            $this->assertNotSame('', trim($case['question']), $case['id']);

            if ($case['behavior'] === 'answer') {
                $this->assertNotEmpty($case['must_evidence'], $case['id'].' needs retrieval evidence.');
                $this->assertNotEmpty($case['key_facts'], $case['id'].' needs key facts.');
            }

            foreach ($case['must_evidence'] as $group) {
                $this->assertNotEmpty($group, $case['id'].' has an empty evidence group.');

                foreach ($group as $evidence) {
                    $this->assertTrue(isset($evidence['phrase']) xor isset($evidence['all']), $case['id']);
                }
            }

            foreach ($case['key_facts'] as $fact) {
                $this->assertNotSame('', trim($fact['fact']), $case['id']);
                $this->assertStringStartsWith('https://docs.mypvit.pro/', $fact['page'], $case['id']);
            }
        }

        // Near-duplicate questions answered by the same passages stay in one split.
        $split = array_column($dataset['cases'], 'split', 'id');
        $this->assertSame($split['g1-02'], $split['g2-06']);
        $this->assertSame($split['g2-01'], $split['g2-02']);
    }
}
