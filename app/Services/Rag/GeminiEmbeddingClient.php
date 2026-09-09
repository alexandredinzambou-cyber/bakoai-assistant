<?php

namespace App\Services\Rag;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class GeminiEmbeddingClient implements EmbeddingClientInterface
{
    public function embed(string $text, string $inputType = 'document', ?string $title = null): array
    {
        $config = config('embedding.providers.gemini');

        if (! is_array($config)) {
            throw new RuntimeException('Gemini embedding provider is missing from config.');
        }

        $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $apiKey = (string) ($config['api_key'] ?? '');
        $model = (string) ($config['model'] ?? '');

        if ($baseUrl === '' || $apiKey === '' || $model === '') {
            throw new RuntimeException('Gemini embedding is not configured. Set GEMINI_API_KEY or GEMINI_EMBEDDING_API_KEY.');
        }

        $timeout = max(1, (int) ($config['timeout'] ?? config('embedding.timeout', 45)));
        $dimensions = (int) config('embedding.dimensions', 768);

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->asJson()
                ->acceptJson()
                ->withOptions($this->httpOptions())
                ->connectTimeout(min(10, $timeout))
                ->timeout($timeout)
                ->retry(
                    max(1, (int) config('embedding.retry_attempts', 2)),
                    max(0, (int) config('embedding.retry_delay_ms', 1000)),
                    fn (Throwable $exception): bool => $this->shouldRetry($exception),
                    throw: false,
                )
                ->post("{$baseUrl}/models/{$model}:embedContent", [
                    'model' => "models/{$model}",
                    'content' => [
                        'parts' => [
                            ['text' => $this->prepareText($text, $inputType, $title)],
                        ],
                    ],
                    // Gemini Embedding 2's official REST example uses this snake_case field.
                    'output_dimensionality' => $dimensions,
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Unable to connect to Gemini embedding provider.');
        }

        if (! $response->successful()) {
            throw new RuntimeException($this->safeHttpFailureMessage($response->status()));
        }

        $json = $response->json();
        $values = data_get($json, 'embedding.values') ?? data_get($json, 'embeddings.0.values');

        if (! is_array($values) || $values === []) {
            throw new RuntimeException('Gemini embedding response is missing embedding.values.');
        }

        if (count($values) !== $dimensions) {
            throw new RuntimeException('Gemini embedding returned '.count($values)." dimensions; {$dimensions} were expected.");
        }

        return array_map(static fn ($value): float => (float) $value, $values);
    }

    public function model(): string
    {
        return (string) config('embedding.providers.gemini.model', 'gemini-embedding-2');
    }

    private function prepareText(string $text, string $inputType, ?string $title): string
    {
        $title = $title ?: 'none';

        return match ($inputType) {
            'query' => 'task: question answering | query: '.$text,
            default => 'title: '.$title.' | text: '.$text,
        };
    }

    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response->status();

        return $status === 429 || $status >= 500;
    }

    private function safeHttpFailureMessage(int $status): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => "Gemini embedding authentication failed (HTTP {$status}).",
            $status === 429 => 'Gemini embedding rate limit exceeded (HTTP 429).',
            $status >= 500 => "Gemini embedding is temporarily unavailable (HTTP {$status}).",
            default => "Gemini embedding request was rejected (HTTP {$status}).",
        };
    }

    private function httpOptions(): array
    {
        $options = ['allow_redirects' => false];

        if (PHP_OS_FAMILY === 'Windows' && defined('CURLOPT_SSL_OPTIONS')) {
            $options['curl'] = [
                CURLOPT_SSL_OPTIONS => defined('CURLSSLOPT_NO_REVOKE') ? CURLSSLOPT_NO_REVOKE : 2,
            ];
        }

        return $options;
    }
}
