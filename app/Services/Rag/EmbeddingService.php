<?php

namespace App\Services\Rag;

use RuntimeException;
use Throwable;

class EmbeddingService
{
    private string $lastModel = '';

    public function __construct(
        private readonly NvidiaEmbeddingClient $nvidia,
        private readonly GeminiEmbeddingClient $gemini,
        private readonly LocalHashEmbeddingClient $local,
    ) {}

    public function embed(string $text): array
    {
        return $this->embedForDocument($text);
    }

    public function embedForDocument(string $text, ?string $title = null): array
    {
        return $this->embedWithFallback($text, 'document', $title);
    }

    public function embedForQuery(string $text): array
    {
        return $this->embedWithFallback($text, 'query');
    }

    public function embedWithProvider(string $provider, string $text, string $inputType = 'document', ?string $title = null): array
    {
        $client = $this->client($provider);
        $vector = $client->embed($text, $inputType, $title);
        $this->lastModel = $provider.':'.$client->model();

        return $vector;
    }

    public function toSqlLiteral(array $vector): string
    {
        return '['.implode(',', array_map(static fn (float $value): string => (string) $value, $vector)).']';
    }

    public function lastModel(): string
    {
        return $this->lastModel ?: (string) config('embedding.providers.local.model', 'local-hash-v1');
    }

    private function embedWithFallback(string $text, string $inputType, ?string $title = null): array
    {
        $errors = [];

        foreach ($this->providers() as $provider) {
            try {
                return $this->embedWithProvider($provider, $text, $inputType, $title);
            } catch (Throwable $exception) {
                $errors[] = "{$provider}: ".$exception->getMessage();
            }
        }

        throw new RuntimeException('No configured embedding provider succeeded. '.implode(' | ', $errors));
    }

    private function providers(): array
    {
        $defaultProvider = (string) config('embedding.default_provider', 'nvidia');

        // Embeddings from unrelated providers do not share a comparable vector
        // space. Fail closed unless an operator deliberately opts into a
        // compatible fallback and re-embeds the whole active knowledge version.
        if (! (bool) config('embedding.allow_provider_fallback', false)) {
            return [$defaultProvider];
        }

        $providers = config('embedding.fallback_providers', []);

        if (! is_array($providers) || $providers === []) {
            return [$defaultProvider];
        }

        return array_values(array_unique(array_map('strval', $providers)));
    }

    private function client(string $provider): EmbeddingClientInterface
    {
        return match ($provider) {
            'nvidia' => $this->nvidia,
            'gemini' => $this->gemini,
            'local' => $this->local,
            default => throw new RuntimeException("Unknown embedding provider [{$provider}]."),
        };
    }
}
