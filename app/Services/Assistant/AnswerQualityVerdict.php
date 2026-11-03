<?php

namespace App\Services\Assistant;

/**
 * Immutable result of one AnswerQualityEvaluator evaluation: a binary pass/fail plus the
 * named rubric violations and the measured stats (word count vs the concision cap), so a
 * report can show *why* an answer was judged bad, not just that it was.
 */
final class AnswerQualityVerdict
{
    /**
     * @param list<string> $violations
     * @param array{words: int, max_words: ?int, sources: int} $stats
     */
    public function __construct(
        public readonly bool $passed,
        public readonly array $violations,
        public readonly array $stats,
    ) {}

    /**
     * @return array{passed: bool, violations: list<string>, stats: array{words: int, max_words: ?int, sources: int}}
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'violations' => $this->violations,
            'stats' => $this->stats,
        ];
    }
}
