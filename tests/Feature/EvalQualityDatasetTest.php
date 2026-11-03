<?php

namespace Tests\Feature;

use App\Services\Assistant\AnswerQualityEvaluator;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EvalQualityDatasetTest extends TestCase
{
    public function test_dataset_is_valid_and_covers_the_required_quality_categories(): void
    {
        $payload = $this->dataset();
        $ids = array_map(static fn (array $case): string => (string) $case['id'], $payload['cases']);

        $this->assertNotSame([], $ids);
        $this->assertSame($ids, array_unique($ids), 'Duplicate case ids in the quality dataset.');
        $this->assertContains('concision-001', $ids, 'The concision complaint must stay represented.');
        $this->assertSame('mistral_raw', $payload['default_target']);
        $this->assertSame(['mistral_raw', 'assistant'], array_values(array_unique(array_merge(
            [$payload['default_target']],
            array_map(static fn (array $case): string => (string) ($case['target'] ?? $payload['default_target']), $payload['cases']),
        ))), 'Unexpected eval target.');
    }

    public function test_every_case_carries_the_fields_the_evaluator_needs(): void
    {
        foreach ($this->dataset()['cases'] as $case) {
            $this->assertArrayHasKey('question', $case, 'Case missing a question: '.($case['id'] ?? '?'));
            $this->assertArrayHasKey('type', $case, 'Case missing a type: '.($case['id'] ?? '?'));
            $this->assertContains($case['type'], ['answerable', 'refusal', 'policy'], 'Unknown type for '.($case['id'] ?? '?'));
            $this->assertArrayHasKey('must_include', $case, 'Case missing must_include: '.($case['id'] ?? '?'));

            if ($case['type'] === 'answerable') {
                $this->assertArrayHasKey('must_not_include', $case, 'Answerable case should declare forbidden terms: '.($case['id'] ?? '?'));
            }
        }
    }

    public function test_offline_fixtures_judge_as_expected_through_the_real_evaluator(): void
    {
        $evaluator = new AnswerQualityEvaluator;

        // A well-formed grounded answer for a simple question passes the full rubric.
        $good = $evaluator->evaluate(
            'Quel est le champ obligatoire pour initier un paiement PVIT ?',
            'Le montant est le champ obligatoire principal [Guide PVIT / Initiation].',
            [['url' => 'https://docs.mypvit.pro/fr/payment']],
            mustInclude: ['montant'],
            maxWords: 80,
        );
        $this->assertTrue($good->passed, implode(', ', $good->violations));

        // A rambling answer to the same question is caught by the concision rule -- the
        // regression this dataset exists to detect.
        $bad = $evaluator->evaluate(
            'Quel est le champ obligatoire pour initier un paiement PVIT ?',
            trim(str_repeat('Le montant est obligatoire, le compte aussi, le telephone aussi, la devise aussi. ', 8)),
            [['url' => 'https://docs.mypvit.pro/fr/payment']],
            maxWords: 80,
        );
        $this->assertFalse($bad->passed);
        $this->assertContains('too_long', $bad->violations);
    }

    private function dataset(): array
    {
        return json_decode(
            File::get(base_path('database/seeders/eval_quality_questions.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
