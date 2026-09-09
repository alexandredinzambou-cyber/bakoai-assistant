<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GatewayEvaluationDatasetTest extends TestCase
{
    public function test_dataset_models_one_pvit_corpus_and_at_least_150_llm_free_gateway_routing_cases(): void
    {
        $payload = json_decode(
            File::get(base_path('database/seeders/eval_questions.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('PVIT', $payload['documentation_corpus_payment_method']);
        $this->assertArrayNotHasKey('required_answerable_payment_methods', $payload);
        $this->assertSame(config('rag.gateway_payment_contexts'), $payload['required_gateway_payment_contexts']);

        $routingCases = 0;
        $coveredContexts = [];
        $answerableGatewayCases = 0;

        foreach ($payload['templates'] as $template) {
            $expandedCount = count($template['moyens_paiement'])
                * count($template['operations'])
                * count($template['questions']);

            if ($template['category'] === 'gateway_routing') {
                $routingCases += $expandedCount;
                $coveredContexts = [...$coveredContexts, ...$template['moyens_paiement']];
                $this->assertSame('PVIT', $template['expected_documentation_corpus_payment_method']);
            }

            if ($template['category'] === 'answerable') {
                $answerableGatewayCases += $expandedCount;
                $this->assertNotEmpty($template['expected_source_documents']);
                $this->assertSame('PVIT', $template['expected_documentation_corpus_payment_method']);
            }
        }

        foreach ($payload['items'] as $item) {
            if (($item['category'] ?? null) === 'gateway_routing') {
                $routingCases++;
                $this->assertSame('PVIT', $item['expected_documentation_corpus_payment_method']);
            }
        }

        $this->assertSame(176, $routingCases);
        $this->assertSame(5, $answerableGatewayCases);
        $this->assertEqualsCanonicalizing(
            $payload['required_gateway_payment_contexts'],
            array_values(array_unique($coveredContexts)),
        );
    }
}
