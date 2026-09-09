<?php

namespace Tests\Unit\Llm;

use App\Services\Llm\Exceptions\LlmTunnelExpiredException;
use App\Services\Llm\FallbackLlmClient;
use App\Services\Llm\OpenAiCompatibleLlmClient;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FallbackLlmClientTest extends TestCase
{
    public function test_it_uses_gemini_as_the_configured_provider(): void
    {
        config()->set('llm.fallback_providers', ['gemini']);

        $client = Mockery::mock(OpenAiCompatibleLlmClient::class);
        $client->shouldReceive('completeWithProvider')
            ->once()
            ->with('gemini', 'S', 'C', 'Q')
            ->andReturn('Reponse Gemini');

        $this->assertSame('Reponse Gemini', (new FallbackLlmClient($client))->complete('S', 'C', 'Q'));
    }

    public function test_it_tries_the_next_openai_compatible_provider_after_a_failure(): void
    {
        config()->set('llm.fallback_providers', ['gemini', 'groq']);

        $client = Mockery::mock(OpenAiCompatibleLlmClient::class);
        $client->shouldReceive('completeWithProvider')
            ->once()
            ->with('gemini', 'S', 'C', 'Q')
            ->andThrow(new RuntimeException('Gemini unavailable'));
        $client->shouldReceive('completeWithProvider')
            ->once()
            ->with('groq', 'S', 'C', 'Q')
            ->andReturn('Reponse de secours');

        $this->assertSame('Reponse de secours', (new FallbackLlmClient($client))->complete('S', 'C', 'Q'));
    }

    public function test_it_uses_the_default_provider_when_the_fallback_list_is_empty(): void
    {
        config()->set([
            'llm.default_provider' => 'gemini',
            'llm.fallback_providers' => [],
        ]);

        $client = Mockery::mock(OpenAiCompatibleLlmClient::class);
        $client->shouldReceive('completeWithProvider')
            ->once()
            ->with('gemini', 'S', 'C', 'Q')
            ->andReturn('OK');

        $this->assertSame('OK', (new FallbackLlmClient($client))->complete('S', 'C', 'Q'));
    }

    public function test_it_reports_all_provider_failures(): void
    {
        config()->set('llm.fallback_providers', ['gemini']);

        $client = Mockery::mock(OpenAiCompatibleLlmClient::class);
        $client->shouldReceive('completeWithProvider')
            ->once()
            ->andThrow(new RuntimeException('quota exceeded'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No configured LLM provider succeeded. gemini: quota exceeded');

        (new FallbackLlmClient($client))->complete('S', 'C', 'Q');
    }

    public function test_it_preserves_a_typed_failure_for_a_single_ngrok_provider(): void
    {
        config()->set('llm.fallback_providers', ['ngrok']);
        $failure = new LlmTunnelExpiredException('LLM tunnel [ngrok] is expired or offline (HTTP 404).');

        $client = Mockery::mock(OpenAiCompatibleLlmClient::class);
        $client->shouldReceive('completeWithProvider')
            ->once()
            ->with('ngrok', 'S', 'C', 'Q')
            ->andThrow($failure);

        try {
            (new FallbackLlmClient($client))->complete('S', 'C', 'Q');
            $this->fail('The typed tunnel failure should be preserved.');
        } catch (LlmTunnelExpiredException $exception) {
            $this->assertSame($failure, $exception);
        }
    }
}
