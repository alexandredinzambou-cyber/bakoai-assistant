<?php

namespace Tests\Unit\Llm;

use App\Services\Llm\Exceptions\InvalidLlmResponseException;
use App\Services\Llm\Exceptions\LlmConfigurationException;
use App\Services\Llm\Exceptions\LlmTunnelExpiredException;
use App\Services\Llm\Exceptions\LlmUnavailableException;
use App\Services\Llm\OpenAiCompatibleLlmClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NgrokOpenAiCompatibleLlmClientTest extends TestCase
{
    private const BASE_URL = 'https://bakoai-tunnel.ngrok-free.app/api/v1';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'llm.default_provider' => 'ngrok',
            'llm.temperature' => 0.1,
            'llm.timeout' => 45,
            'llm.retry_attempts' => 2,
            'llm.retry_delay_ms' => 0,
        ]);
        $this->configureNgrok();
        Http::preventStrayRequests();
    }

    public function test_it_calls_the_configurable_openai_compatible_endpoint_with_safe_headers(): void
    {
        $this->configureNgrok([
            'auth_header' => 'X-API-Key',
            'auth_scheme' => '',
            'headers_json' => json_encode([
                'ngrok-skip-browser-warning' => 'bakoai-assistant',
                'X-BakoAI-Client' => 'integration-assistant',
            ], JSON_THROW_ON_ERROR),
        ]);
        Http::fake([
            self::BASE_URL.'/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '  Réponse via tunnel.  ']]],
            ]),
        ]);

        $answer = (new OpenAiCompatibleLlmClient)->complete('SYSTEME', 'EXTRAIT', 'QUESTION');

        $this->assertSame('Réponse via tunnel.', $answer);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::BASE_URL.'/chat/completions'
                && $request->hasHeader('X-API-Key', 'ngrok-test-key')
                && ! $request->hasHeader('Authorization')
                && $request->hasHeader('ngrok-skip-browser-warning', 'bakoai-assistant')
                && $request->hasHeader('X-BakoAI-Client', 'integration-assistant')
                && $request['model'] === 'local-openai-compatible-model';
        });
    }

    public function test_it_rejects_an_unsafe_custom_header_without_exposing_its_value(): void
    {
        $this->configureNgrok([
            'headers_json' => json_encode(['Host' => 'secret-upstream.internal'], JSON_THROW_ON_ERROR),
        ]);

        try {
            (new OpenAiCompatibleLlmClient)->complete('S', 'C', 'Q');
            $this->fail('A transport-controlled custom header should be rejected.');
        } catch (LlmConfigurationException $exception) {
            $this->assertSame(
                'LLM provider [ngrok] custom headers configuration is invalid.',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString('secret-upstream.internal', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_it_reports_an_expired_ngrok_tunnel_without_leaking_the_response_body(): void
    {
        Http::fake([
            self::BASE_URL.'/chat/completions' => Http::response(
                '<html>ERR_NGROK_3200 private upstream detail</html>',
                404,
                ['Ngrok-Error-Code' => 'ERR_NGROK_3200'],
            ),
        ]);

        try {
            (new OpenAiCompatibleLlmClient)->complete('S', 'C', 'Q');
            $this->fail('An expired tunnel should throw a typed exception.');
        } catch (LlmTunnelExpiredException $exception) {
            $this->assertSame(
                'LLM tunnel [ngrok] is expired or offline (HTTP 404).',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString('private upstream detail', $exception->getMessage());
        }
    }

    public function test_it_types_final_upstream_unavailability_and_retries_transient_failures(): void
    {
        $this->configureNgrok(['retry_attempts' => 2, 'retry_delay_ms' => 0]);
        Http::fake([
            self::BASE_URL.'/chat/completions' => Http::sequence()
                ->push(['error' => ['message' => 'private first failure']], 503)
                ->push(['error' => ['message' => 'private final failure']], 503),
        ]);

        try {
            (new OpenAiCompatibleLlmClient)->complete('S', 'C', 'Q');
            $this->fail('A final upstream outage should throw a typed exception.');
        } catch (LlmUnavailableException $exception) {
            $this->assertSame(
                'LLM provider [ngrok] is temporarily unavailable (HTTP 503).',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString('private final failure', $exception->getMessage());
        }

        Http::assertSentCount(2);
    }

    public function test_it_types_a_successful_but_invalid_openai_compatible_response(): void
    {
        Http::fake([
            self::BASE_URL.'/chat/completions' => Http::response([
                'unexpected' => 'private response detail',
            ]),
        ]);

        try {
            (new OpenAiCompatibleLlmClient)->complete('S', 'C', 'Q');
            $this->fail('An invalid response should throw a typed exception.');
        } catch (InvalidLlmResponseException $exception) {
            $this->assertSame(
                'LLM provider [ngrok] response is missing choices.0.message.content.',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString('private response detail', $exception->getMessage());
        }
    }

    public function test_it_requires_https_for_the_public_tunnel(): void
    {
        $this->configureNgrok(['base_url' => 'http://bakoai-tunnel.ngrok-free.app/api/v1']);

        $this->expectException(LlmConfigurationException::class);
        $this->expectExceptionMessage('LLM provider [ngrok] base URL is invalid.');

        (new OpenAiCompatibleLlmClient)->complete('S', 'C', 'Q');
    }

    public function test_it_rejects_credentials_or_query_parameters_in_the_base_url(): void
    {
        $this->configureNgrok([
            'base_url' => 'https://user:private-password@bakoai-tunnel.ngrok-free.app/api/v1?token=private-token',
        ]);

        try {
            (new OpenAiCompatibleLlmClient)->complete('S', 'C', 'Q');
            $this->fail('Credentials and query parameters must be rejected in a provider base URL.');
        } catch (LlmConfigurationException $exception) {
            $this->assertSame('LLM provider [ngrok] base URL is invalid.', $exception->getMessage());
            $this->assertStringNotContainsString('private-password', $exception->getMessage());
            $this->assertStringNotContainsString('private-token', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    private function configureNgrok(array $overrides = []): void
    {
        config()->set('llm.providers.ngrok', [
            'label' => 'Ngrok OpenAI-compatible tunnel',
            'base_url' => self::BASE_URL,
            'endpoint' => 'chat/completions',
            'api_key' => 'ngrok-test-key',
            'model' => 'local-openai-compatible-model',
            'timeout' => 5,
            'connect_timeout' => 2,
            'retry_attempts' => 1,
            'retry_delay_ms' => 0,
            'auth_header' => 'Authorization',
            'auth_scheme' => 'Bearer',
            'headers_json' => '{}',
            'tunnel' => true,
            ...$overrides,
        ]);
    }
}
