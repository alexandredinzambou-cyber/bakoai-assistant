<?php

namespace App\Services\Rag;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class NvidiaEmbeddingClient implements EmbeddingClientInterface
{
    public function embed(string $text, string $inputType = 'document', ?string $title = null): array
    {
        $config = config('embedding.providers.nvidia');

        if (! is_array($config)) {
            throw new RuntimeException('NVIDIA embedding provider is missing from config.');
        }

        $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $apiKey = (string) ($config['api_key'] ?? '');
        $model = (string) ($config['model'] ?? '');

        if ($baseUrl === '' || $model === '') {
            throw new RuntimeException('NVIDIA embedding is not configured. Set NVIDIA_EMBEDDING_BASE_URL and NVIDIA_EMBEDDING_MODEL.');
        }

        if ($apiKey === '' && str_contains(parse_url($baseUrl, PHP_URL_HOST) ?: '', 'api.nvidia.com')) {
            throw new RuntimeException('NVIDIA embedding is not configured. Set NVIDIA_API_KEY or NVIDIA_EMBEDDING_API_KEY.');
        }

        $timeout = max(1, (int) ($config['timeout'] ?? config('embedding.timeout', 45)));
        $dimensions = (int) config('embedding.dimensions', 768);
        $payload = [
            'model' => $model,
            'input' => $this->prepareText($text, $inputType, $title),
            'input_type' => $inputType === 'query' ? 'query' : 'passage',
            'modality' => 'text',
            'encoding_format' => 'float',
            'dimensions' => $dimensions,
            'truncate' => (string) ($config['truncate'] ?? 'END'),
        ];

        try {
            $request = Http::asJson()
                ->acceptJson()
                ->withOptions($this->httpOptions())
                ->connectTimeout(min(10, $timeout))
                ->timeout($timeout)
                ->retry(
                    max(1, (int) config('embedding.retry_attempts', 2)),
                    max(0, (int) config('embedding.retry_delay_ms', 1000)),
                    fn (Throwable $exception): bool => $this->shouldRetry($exception),
                    throw: false,
                );

            if ($apiKey !== '') {
                $request = $request->withToken($apiKey);
            }

            $response = $request->post("{$baseUrl}/embeddings", $payload);
        } catch (ConnectionException) {
            throw new RuntimeException('Unable to connect to NVIDIA embedding provider.');
        }

        if (! $response->successful()) {
            throw new RuntimeException($this->safeHttpFailureMessage($response->status()));
        }

        $values = data_get($response->json(), 'data.0.embedding');

        if (! is_array($values) || $values === []) {
            throw new RuntimeException('NVIDIA embedding response is missing data.0.embedding.');
        }

        if (count($values) !== $dimensions) {
            throw new RuntimeException('NVIDIA embedding returned '.count($values)." dimensions; {$dimensions} were expected.");
        }

        return array_map(static fn ($value): float => (float) $value, $values);
    }

    public function model(): string
    {
        return (string) config('embedding.providers.nvidia.model', 'nvidia/llama-nemotron-embed-vl-1b-v2');
    }

    private function prepareText(string $text, string $inputType, ?string $title): string
    {
        if ($inputType === 'query') {
            return $text;
        }

        return trim(($title ? $title."\n\n" : '').$text);
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
            in_array($status, [401, 403], true) => "NVIDIA embedding authentication failed (HTTP {$status}).",
            $status === 429 => 'NVIDIA embedding rate limit exceeded (HTTP 429).',
            $status >= 500 => "NVIDIA embedding is temporarily unavailable (HTTP {$status}).",
            default => "NVIDIA embedding request was rejected (HTTP {$status}).",
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
