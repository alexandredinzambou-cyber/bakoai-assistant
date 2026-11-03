<?php

namespace Tests\Unit\Evaluation;

use App\Services\Evaluation\ConversationReplayEvaluator;
use PHPUnit\Framework\TestCase;

class ConversationReplayEvaluatorTest extends TestCase
{
    public function test_http_success_does_not_hide_a_wrong_or_refused_answer(): void
    {
        $case = ['contains' => ['transactionId', 'responseCode'], 'forbidden' => ['ouvrez un ticket'], 'support' => false];
        $failures = (new ConversationReplayEvaluator)->evaluate($case, 200, ['answer' => 'Ouvrez un ticket de support.', 'should_escalate' => true]);
        $this->assertContains('missing:transactionId', $failures);
        $this->assertContains('missing:responseCode', $failures);
        $this->assertContains('forbidden:ouvrez un ticket', $failures);
        $this->assertContains('support', $failures);
    }

    public function test_generated_invalid_json_and_raw_citations_are_detected(): void
    {
        $failures = (new ConversationReplayEvaluator)->evaluate([], 200, ['answer' => "```json\n{bad json}\n``` [SOURCE:1]"]);
        $this->assertContains('invalid_json', $failures);
        $this->assertContains('raw_source_marker', $failures);
    }

    public function test_extra_steps_and_temporary_memory_cannot_pass_as_a_complete_success(): void
    {
        $failures = (new ConversationReplayEvaluator)->evaluate(['numbered_points' => 2], 200, ['answer' => "1. Un\n2. Deux\n3. Trois", 'memory_status' => 'temporary']);
        $this->assertContains('numbered_points', $failures);
        $this->assertContains('memory_status:temporary', $failures);
        $this->assertSame([], (new ConversationReplayEvaluator)->evaluate(['memory_status' => 'temporary'], 200, ['answer' => 'Suivi temporaire explicite.', 'memory_status' => 'temporary']));
    }

    public function test_citation_on_a_closing_fence_is_detected_without_rejecting_a_separate_citation(): void
    {
        $evaluator = new ConversationReplayEvaluator;
        foreach (['```', '~~~'] as $fence) {
            $this->assertContains('malformed_code_fence', $evaluator->evaluate([], 200, ['answer' => $fence."json\n{}\n".$fence.' [1]']));
            $this->assertSame([], $evaluator->evaluate([], 200, ['answer' => $fence."json\n{}\n".$fence."\n\n[1]"]));
        }
    }

    public function test_dataset_covers_multiple_complete_conversations_and_regression_assertions(): void
    {
        $scenarios = require dirname(__DIR__, 3).'/resources/evaluation/conversations.php';
        $this->assertCount(16, $scenarios);
        $this->assertGreaterThanOrEqual(411, array_sum(array_map('count', $scenarios)));
        foreach ($scenarios as $cases) {
            $this->assertGreaterThanOrEqual(12, count($cases));
            foreach ($cases as $case) {
                $this->assertNotEmpty($case['question']);
                $this->assertTrue(isset($case['support']) || isset($case['contains']) || isset($case['forbidden']));
            }
        }
    }
}
