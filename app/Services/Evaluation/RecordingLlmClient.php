<?php

namespace App\Services\Evaluation;

use App\Services\Llm\OpenAiCompatibleLlmClient;
use Throwable;

/**
 * Generator client for assistant:answer-eval --record. It forwards every call unchanged and
 * keeps what the model was given and what it returned: the judge scores an answer against the
 * exact context the generator read, not against a later re-retrieval.
 */
class RecordingLlmClient extends OpenAiCompatibleLlmClient
{
    /** @var list<array{provider: string, system_prompt_sha1: string, context: string, question: string, options: array, output: ?string, error: ?string, ms: int}> */
    private array $calls = [];

    public function completeWithProvider(string $provider, string $systemPrompt, string $context, string $question, array $options = []): string
    {
        $started = microtime(true);
        $call = [
            'provider' => $provider,
            'system_prompt_sha1' => sha1($systemPrompt),
            'context' => $context,
            'question' => $question,
            'options' => $options,
            'output' => null,
            'error' => null,
            'ms' => 0,
        ];

        try {
            $call['output'] = parent::completeWithProvider($provider, $systemPrompt, $context, $question, $options);

            return $call['output'];
        } catch (Throwable $exception) {
            $call['error'] = $exception->getMessage();

            throw $exception;
        } finally {
            $call['ms'] = (int) round((microtime(true) - $started) * 1000);
            $this->calls[] = $call;
        }
    }

    /**
     * The calls made since the last reset, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function takeCalls(): array
    {
        $calls = $this->calls;
        $this->calls = [];

        return $calls;
    }
}
