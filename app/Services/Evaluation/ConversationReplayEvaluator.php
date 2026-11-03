<?php

namespace App\Services\Evaluation;

class ConversationReplayEvaluator
{
    public function evaluate(array $case, int $http, array $response): array
    {
        $failures = [];
        $answer = (string) ($response['answer'] ?? $response['error'] ?? '');
        if ($http !== ($case['http'] ?? 200)) {
            $failures[] = 'http:'.$http;
        }
        if (trim($answer) === '') {
            $failures[] = 'empty_answer';
        }
        foreach ($case['contains'] ?? [] as $value) {
            if (mb_stripos($answer, $value) === false) {
                $failures[] = 'missing:'.$value;
            }
        }
        foreach ($case['forbidden'] ?? [] as $value) {
            if (mb_stripos($answer, $value) !== false) {
                $failures[] = 'forbidden:'.$value;
            }
        }
        foreach ($case['patterns'] ?? [] as $pattern) {
            if (preg_match($pattern, $answer) !== 1) {
                $failures[] = 'pattern:'.$pattern;
            }
        }
        if (array_key_exists('support', $case) && ($response['should_escalate'] ?? false) !== $case['support']) {
            $failures[] = 'support';
        }
        if (isset($case['type']) && ($response['answer_type'] ?? null) !== $case['type']) {
            $failures[] = 'answer_type';
        }
        if (isset($case['numbered_points']) && preg_match_all('/^\d+[.)]\s+/mu', $answer) !== $case['numbered_points']) {
            $failures[] = 'numbered_points';
        }
        if (isset($response['memory_status']) && $response['memory_status'] !== ($case['memory_status'] ?? 'durable')) {
            $failures[] = 'memory_status:'.$response['memory_status'];
        }
        preg_match_all('/```json\s*\R(.*?)```/su', $answer, $blocks);
        foreach ($blocks[1] as $block) {
            json_decode($block, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $failures[] = 'invalid_json';
            }
        }
        if (str_contains($answer, '[SOURCE:')) {
            $failures[] = 'raw_source_marker';
        }

        return $failures;
    }
}
