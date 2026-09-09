<?php

namespace App\Services\Llm;

use App\Services\Llm\Exceptions\InvalidLlmResponseException;
use App\Services\Llm\Exceptions\LlmClientException;
use App\Services\Llm\Exceptions\LlmConfigurationException;
use App\Services\Llm\Exceptions\LlmTunnelExpiredException;
use App\Services\Llm\Exceptions\LlmUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

class OpenAiCompatibleLlmClient implements LlmClientInterface
{
    private const ALLOWED_AUTH_HEADERS = [
        'authorization',
        'x-api-key',
        'x-auth-token',
    ];

    private const FORBIDDEN_EXTRA_HEADERS = [
        'accept',
        'authorization',
        'connection',
        'content-length',
        'content-type',
        'cookie',
        'forwarded',
        'host',
        'proxy-authorization',
        'set-cookie',
        'transfer-encoding',
        'x-api-key',
        'x-auth-token',
        'x-forwarded-for',
        'x-forwarded-host',
        'x-forwarded-proto',
    ];

    public function complete(string $systemPrompt, string $context, string $question): string
    {
        return $this->completeWithProvider(
            (string) config('llm.default_provider', 'ngrok'),
            $systemPrompt,
            $context,
            $question,
        );
    }

    /**
     * @throws LlmClientException
     */
    public function completeWithProvider(string $provider, string $systemPrompt, string $context, string $question): string
    {
        $config = config("llm.providers.{$provider}");

        if (! is_array($config)) {
            throw new LlmConfigurationException("Unknown LLM provider [{$provider}].");
        }

        $baseUrl = rtrim(trim((string) ($config['base_url'] ?? '')), '/');
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $model = trim((string) ($config['model'] ?? ''));

        if ($baseUrl === '' || $apiKey === '' || $model === '') {
            throw new LlmConfigurationException("LLM provider [{$provider}] is not configured. Check base URL, API key and model.");
        }

        $this->validateBaseUrl($provider, $baseUrl, (bool) ($config['tunnel'] ?? false));

        $timeout = min(120, max(1, (int) ($config['timeout'] ?? config('llm.timeout', 45))));
        $connectTimeout = min($timeout, min(30, max(1, (int) ($config['connect_timeout'] ?? 10))));
        $retryAttempts = min(5, max(1, (int) ($config['retry_attempts'] ?? config('llm.retry_attempts', 2))));
        $retryDelayMs = min(10_000, max(0, (int) ($config['retry_delay_ms'] ?? config('llm.retry_delay_ms', 1000))));
        $headers = $this->requestHeaders($provider, $config, $apiKey);

        try {
            $response = Http::withHeaders($headers)
                ->asJson()
                ->acceptJson()
                ->withOptions($this->httpOptions())
                ->connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->retry(
                    $retryAttempts,
                    $retryDelayMs,
                    fn (Throwable $exception): bool => $this->shouldRetry($exception),
                    throw: false,
                )
                ->post($this->chatCompletionsUrl($baseUrl, $config['endpoint'] ?? null), [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => "Contexte documentaire pertinent :\n".$context."\n\nQuestion du developpeur :\n".$question],
                    ],
                    'temperature' => (float) config('llm.temperature', 0.1),
                ]);
        } catch (ConnectionException) {
            throw new LlmUnavailableException("Unable to connect to LLM provider [{$provider}].");
        }

        if ($this->isExpiredTunnelResponse($response, $config)) {
            throw new LlmTunnelExpiredException(
                "LLM tunnel [{$provider}] is expired or offline (HTTP {$response->status()}).",
            );
        }

        if (! $response->successful()) {
            throw $this->safeHttpFailure($provider, $response->status());
        }

        $content = data_get($response->json(), 'choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new InvalidLlmResponseException(
                "LLM provider [{$provider}] response is missing choices.0.message.content.",
            );
        }

        return trim($content);
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

        return in_array($status, [408, 425, 429], true) || $status >= 500;
    }

    private function safeHttpFailure(string $provider, int $status): LlmClientException
    {
        $message = match (true) {
            in_array($status, [401, 403], true) => "LLM provider [{$provider}] authentication failed (HTTP {$status}).",
            $status === 429 => "LLM provider [{$provider}] rate limit exceeded (HTTP 429).",
            $status >= 500 => "LLM provider [{$provider}] is temporarily unavailable (HTTP {$status}).",
            default => "LLM provider [{$provider}] rejected the request (HTTP {$status}).",
        };

        return in_array($status, [408, 425, 429], true) || $status >= 500
            ? new LlmUnavailableException($message)
            : new LlmClientException($message);
    }

    private function chatCompletionsUrl(string $baseUrl, mixed $configuredEndpoint): string
    {
        $endpoint = trim((string) $configuredEndpoint);

        if ($endpoint === '' && str_ends_with($baseUrl, '/chat/completions')) {
            return $baseUrl;
        }

        $endpoint = $endpoint === '' ? 'chat/completions' : $endpoint;

        if (preg_match('/[\r\n]/', $endpoint) === 1
            || str_contains($endpoint, '://')
            || str_starts_with($endpoint, '//')
            || str_contains($endpoint, '?')
            || str_contains($endpoint, '#')) {
            throw new LlmConfigurationException('LLM endpoint configuration is invalid.');
        }

        return $baseUrl.'/'.ltrim($endpoint, '/');
    }

    private function validateBaseUrl(string $provider, string $baseUrl, bool $tunnel): void
    {
        $parts = parse_url($baseUrl);
        $valid = is_array($parts)
            && in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && filled($parts['host'] ?? null)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);

        if (! $valid || ($tunnel && ($parts['scheme'] ?? null) !== 'https')) {
            throw new LlmConfigurationException("LLM provider [{$provider}] base URL is invalid.");
        }
    }

    private function requestHeaders(string $provider, array $config, string $apiKey): array
    {
        $authHeader = trim((string) ($config['auth_header'] ?? 'Authorization'));
        $authScheme = trim((string) ($config['auth_scheme'] ?? 'Bearer'));

        if (! in_array(strtolower($authHeader), self::ALLOWED_AUTH_HEADERS, true)
            || ($authScheme !== '' && preg_match('/\A[A-Za-z][A-Za-z0-9._~-]*\z/', $authScheme) !== 1)
            || preg_match('/[\r\n]/', $apiKey) === 1) {
            throw new LlmConfigurationException("LLM provider [{$provider}] authentication header configuration is invalid.");
        }

        $headers = [
            $authHeader => $authScheme === '' ? $apiKey : $authScheme.' '.$apiKey,
        ];
        $extraHeaders = $config['headers'] ?? [];

        if (! is_array($extraHeaders)) {
            throw new LlmConfigurationException("LLM provider [{$provider}] custom headers configuration is invalid.");
        }

        $headersJson = trim((string) ($config['headers_json'] ?? ''));

        if ($headersJson !== '' && $headersJson !== '{}') {
            try {
                $decodedHeaders = json_decode($headersJson, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new LlmConfigurationException("LLM provider [{$provider}] custom headers configuration is invalid.");
            }

            if (! is_array($decodedHeaders) || array_is_list($decodedHeaders)) {
                throw new LlmConfigurationException("LLM provider [{$provider}] custom headers configuration is invalid.");
            }

            $extraHeaders = [...$extraHeaders, ...$decodedHeaders];
        }

        if (count($extraHeaders) > 16) {
            throw new LlmConfigurationException("LLM provider [{$provider}] custom headers configuration is invalid.");
        }

        foreach ($extraHeaders as $name => $value) {
            $normalizedName = strtolower((string) $name);

            if (! is_string($name)
                || preg_match('/\A[A-Za-z0-9!#$%&\'*+.^_`|~-]+\z/', $name) !== 1
                || in_array($normalizedName, self::FORBIDDEN_EXTRA_HEADERS, true)
                || $normalizedName === strtolower($authHeader)
                || ! is_string($value)
                || strlen($value) > 4096
                || preg_match('/[\r\n]/', $value) === 1) {
                throw new LlmConfigurationException("LLM provider [{$provider}] custom headers configuration is invalid.");
            }

            $headers[$name] = $value;
        }

        return $headers;
    }

    private function isExpiredTunnelResponse(Response $response, array $config): bool
    {
        if (! ($config['tunnel'] ?? false)) {
            return false;
        }

        $headerCode = (string) $response->header('Ngrok-Error-Code');
        $jsonCode = (string) data_get($response->json(), 'error_code', data_get($response->json(), 'error.code', ''));
        $bodyPrefix = substr($response->body(), 0, 8192);
        $hasNgrokErrorCode = preg_match('/\bERR_NGROK_\d+\b/i', $headerCode.' '.$jsonCode.' '.$bodyPrefix) === 1;
        $server = strtolower((string) $response->header('Server'));

        return $hasNgrokErrorCode
            || (in_array($response->status(), [404, 410], true) && str_contains($server, 'ngrok'));
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
