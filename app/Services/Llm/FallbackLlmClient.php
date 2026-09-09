<?php

namespace App\Services\Llm;

use App\Services\Llm\Exceptions\LlmClientException;
use Throwable;

class FallbackLlmClient implements LlmClientInterface
{
    public function __construct(
        private readonly OpenAiCompatibleLlmClient $openAiCompatible,
    ) {}

    public function complete(string $systemPrompt, string $context, string $question): string
    {
        $errors = [];
        $providers = $this->providers();
        $typedFailure = null;

        foreach ($providers as $provider) {
            try {
                return $this->openAiCompatible->completeWithProvider($provider, $systemPrompt, $context, $question);
            } catch (Throwable $exception) {
                $errors[] = "{$provider}: ".$exception->getMessage();
                $typedFailure ??= $exception instanceof LlmClientException ? $exception : null;
            }
        }

        if (count($providers) === 1 && $typedFailure instanceof LlmClientException) {
            throw $typedFailure;
        }

        throw new LlmClientException('No configured LLM provider succeeded. '.implode(' | ', $errors));
    }

    private function providers(): array
    {
        $providers = config('llm.fallback_providers', []);

        if (! is_array($providers) || $providers === []) {
            return [(string) config('llm.default_provider', 'ngrok')];
        }

        return $providers;
    }
}
