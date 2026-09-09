<?php

namespace App\Console\Commands;

use App\Services\Llm\FallbackLlmClient;
use App\Services\Llm\OpenAiCompatibleLlmClient;
use Illuminate\Console\Command;
use Throwable;

class TestLlm extends Command
{
    protected $signature = 'llm:test
        {--provider=fallback : Provider to test: fallback, ngrok, gemini, groq, deepseek or mistral}
        {--question=Reponds exactement: OK BakoAI. : Short test question}';

    protected $description = 'Test configured LLM providers without running the RAG database pipeline.';

    public function handle(
        FallbackLlmClient $fallback,
        OpenAiCompatibleLlmClient $openAiCompatible,
    ): int {
        $provider = (string) $this->option('provider');
        $question = (string) $this->option('question');
        $systemPrompt = 'Tu es un assistant de test. Reponds en francais, brievement, sans code source.';

        $this->line("Testing LLM provider: {$provider}");

        try {
            $answer = match ($provider) {
                'fallback' => $fallback->complete($systemPrompt, 'Test de connectivite sans contexte RAG.', $question),
                'ngrok', 'gemini', 'groq', 'deepseek', 'mistral' => $openAiCompatible->completeWithProvider($provider, $systemPrompt, 'Test de connectivite sans contexte RAG.', $question),
                default => throw new \InvalidArgumentException("Unknown provider [{$provider}]."),
            };
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('LLM call succeeded.');
        $this->line($answer);

        return self::SUCCESS;
    }
}
