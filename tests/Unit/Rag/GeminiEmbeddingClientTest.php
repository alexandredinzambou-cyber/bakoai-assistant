<?php

namespace Tests\Unit\Rag;

use App\Services\Rag\GeminiEmbeddingClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GeminiEmbeddingClientTest extends TestCase
{
    private const BASE_URL = 'https://generativelanguage.test/v1beta';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'embedding.dimensions' => 768,
            'embedding.retry_attempts' => 2,
            'embedding.retry_delay_ms' => 0,
            'embedding.providers.gemini' => [
                'base_url' => self::BASE_URL,
                'api_key' => 'gemini-test-key',
                'model' => 'gemini-embedding-2',
                'timeout' => 5,
            ],
        ]);

        Http::preventStrayRequests();
    }

    public function test_it_uses_the_gemini_embedding_2_rest_contract_and_task_prefixes(): void
    {
        Http::fake([
            self::BASE_URL.'/models/gemini-embedding-2:embedContent' => Http::response([
                'embedding' => ['values' => array_fill(0, 768, 0.125)],
            ]),
        ]);

        $client = new GeminiEmbeddingClient;

        $this->assertCount(768, $client->embed('Comment fonctionne PVIT ?', 'query'));
        $this->assertCount(768, $client->embed('PVIT agit comme une passerelle.', 'document', 'Présentation PVIT'));

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === self::BASE_URL.'/models/gemini-embedding-2:embedContent'
                && $request->hasHeader('x-goog-api-key', 'gemini-test-key')
                && $request['model'] === 'models/gemini-embedding-2'
                && $request['output_dimensionality'] === 768
                && $request['content']['parts'][0]['text'] === 'task: question answering | query: Comment fonctionne PVIT ?';
        });

        Http::assertSent(function (Request $request): bool {
            return $request['content']['parts'][0]['text'] === 'title: Présentation PVIT | text: PVIT agit comme une passerelle.';
        });
    }

    public function test_it_retries_transient_failures_and_does_not_expose_provider_bodies(): void
    {
        Http::fake([
            self::BASE_URL.'/models/gemini-embedding-2:embedContent' => Http::sequence()
                ->push(['error' => ['message' => 'temporary internal detail']], 503)
                ->push(['embedding' => ['values' => array_fill(0, 768, 0.25)]], 200)
                ->push(['error' => ['message' => 'sensitive provider detail']], 400),
        ]);

        $client = new GeminiEmbeddingClient;

        $this->assertCount(768, $client->embed('Question', 'query'));

        try {
            $client->embed('Question invalide', 'query');
            $this->fail('A rejected request should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gemini embedding request was rejected (HTTP 400).', $exception->getMessage());
            $this->assertStringNotContainsString('sensitive provider detail', $exception->getMessage());
        }

        Http::assertSentCount(3);
    }

    public function test_it_rejects_vectors_with_an_unexpected_dimension(): void
    {
        Http::fake([
            self::BASE_URL.'/models/gemini-embedding-2:embedContent' => Http::response([
                'embedding' => ['values' => [0.1, 0.2]],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Gemini embedding returned 2 dimensions; 768 were expected.');

        (new GeminiEmbeddingClient)->embed('Question', 'query');
    }
}
