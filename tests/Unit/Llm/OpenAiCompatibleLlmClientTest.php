<?php

namespace Tests\Unit\Llm;

use App\Services\Llm\Exceptions\InvalidLlmResponseException;
use App\Services\Llm\Exceptions\LlmUnavailableException;
use App\Services\Llm\OpenAiCompatibleLlmClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OpenAiCompatibleLlmClientTest extends TestCase
{
    private const BASE_URL = 'https://generativelanguage.test/v1beta/openai';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'llm.default_provider' => 'gemini',
            'llm.temperature' => 0.1,
            'llm.timeout' => 45,
            'llm.retry_attempts' => 2,
            'llm.retry_delay_ms' => 0,
            'llm.providers.gemini' => [
                'label' => 'Google Gemini Flash',
                'base_url' => self::BASE_URL,
                'api_key' => 'gemini-test-key',
                'model' => 'gemini-test-model',
                'timeout' => 5,
            ],
        ]);

        Http::preventStrayRequests();
    }

    public function test_it_calls_gemini_through_the_openai_compatible_contract(): void
    {
        Http::fake([
            self::BASE_URL.'/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => '  Reponse Gemini.  ']],
                ],
            ]),
        ]);

        $answer = (new OpenAiCompatibleLlmClient)->complete('SYSTEME', 'EXTRAIT', 'QUESTION');

        $this->assertSame('Reponse Gemini.', $answer);
        Http::assertSent(function (Request $request): bool {
            $messages = $request['messages'];

            return $request->method() === 'POST'
                && $request->url() === self::BASE_URL.'/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer gemini-test-key')
                && $request->hasHeader('Accept', 'application/json')
                && $request['model'] === 'gemini-test-model'
                && $request['temperature'] === 0.1
                && $messages[0] === ['role' => 'system', 'content' => 'SYSTEME']
                && $messages[1]['role'] === 'user'
                && str_contains($messages[1]['content'], "Contexte documentaire pertinent :\nEXTRAIT")
                && str_contains($messages[1]['content'], "Question du developpeur :\nQUESTION");
        });
    }

    public function test_it_does_not_duplicate_a_configured_chat_completions_suffix(): void
    {
        config()->set('llm.providers.gemini.base_url', self::BASE_URL.'/chat/completions');
        Http::fake([
            self::BASE_URL.'/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'OK']]],
            ]),
        ]);

        $this->assertSame(
            'OK',
            (new OpenAiCompatibleLlmClient)->completeWithProvider('gemini', 'S', 'C', 'Q'),
        );

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE_URL.'/chat/completions');
    }

    public function test_it_rejects_an_unknown_or_incomplete_provider(): void
    {
        $client = new OpenAiCompatibleLlmClient;

        try {
            $client->completeWithProvider('unknown', 'S', 'C', 'Q');
            $this->fail('An unknown provider should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unknown LLM provider [unknown].', $exception->getMessage());
        }

        config()->set('llm.providers.gemini.api_key', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LLM provider [gemini] is not configured. Check base URL, API key and model.');

        $client->completeWithProvider('gemini', 'S', 'C', 'Q');
    }

    public function test_it_reports_http_and_response_contract_failures(): void
    {
        Http::fake([
            self::BASE_URL.'/chat/completions' => Http::sequence()
                ->push(['error' => ['message' => 'bad request']], 400)
                ->push(['choices' => []], 200),
        ]);

        $client = new OpenAiCompatibleLlmClient;

        try {
            $client->complete('S', 'C', 'Q');
            $this->fail('An unsuccessful Gemini response should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'LLM provider [gemini] rejected the request (HTTP 400).',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString('bad request', $exception->getMessage());
        }

        $this->expectException(InvalidLlmResponseException::class);
        $this->expectExceptionMessage('LLM provider [gemini] response is missing choices.0.message.content.');

        $client->complete('S', 'C', 'Q');
    }

    public function test_it_retries_a_transient_gemini_failure_once(): void
    {
        Http::fake([
            self::BASE_URL.'/chat/completions' => Http::sequence()
                ->push(['error' => ['message' => 'temporarily unavailable']], 503)
                ->push(['choices' => [['message' => ['content' => 'Recovered']]]], 200),
        ]);

        $this->assertSame('Recovered', (new OpenAiCompatibleLlmClient)->complete('S', 'C', 'Q'));
        Http::assertSentCount(2);
    }

    public function test_it_converts_connection_failures_to_a_safe_provider_error(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('Transport details must not escape.');
        });

        $this->expectException(LlmUnavailableException::class);
        $this->expectExceptionMessage('Unable to connect to LLM provider [gemini].');

        (new OpenAiCompatibleLlmClient)->complete('S', 'C', 'Q');
    }
}
