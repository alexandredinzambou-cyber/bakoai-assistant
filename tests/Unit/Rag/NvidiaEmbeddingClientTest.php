<?php

namespace Tests\Unit\Rag;

use App\Services\Rag\NvidiaEmbeddingClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class NvidiaEmbeddingClientTest extends TestCase
{
    private const BASE_URL = 'https://integrate.api.nvidia.test/v1';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'embedding.dimensions' => 768,
            'embedding.retry_attempts' => 2,
            'embedding.retry_delay_ms' => 0,
            'embedding.providers.nvidia' => [
                'base_url' => self::BASE_URL,
                'api_key' => 'nvidia-test-key',
                'model' => 'nvidia/llama-nemotron-embed-vl-1b-v2',
                'timeout' => 5,
                'truncate' => 'END',
            ],
        ]);

        Http::preventStrayRequests();
    }

    public function test_it_uses_the_nvidia_openai_embedding_contract(): void
    {
        Http::fake([
            self::BASE_URL.'/embeddings' => Http::response([
                'object' => 'list',
                'data' => [
                    ['object' => 'embedding', 'index' => 0, 'embedding' => array_fill(0, 768, 0.125)],
                ],
                'model' => 'nvidia/llama-nemotron-embed-vl-1b-v2',
            ]),
        ]);

        $client = new NvidiaEmbeddingClient;

        $this->assertCount(768, $client->embed('Comment fonctionne PVIT ?', 'query'));
        $this->assertCount(768, $client->embed('PVIT agit comme une passerelle.', 'document', 'Presentation PVIT'));

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === self::BASE_URL.'/embeddings'
                && $request->hasHeader('Authorization', 'Bearer nvidia-test-key')
                && $request['model'] === 'nvidia/llama-nemotron-embed-vl-1b-v2'
                && $request['input'] === 'Comment fonctionne PVIT ?'
                && $request['input_type'] === 'query'
                && $request['modality'] === 'text'
                && $request['encoding_format'] === 'float'
                && $request['dimensions'] === 768
                && $request['truncate'] === 'END';
        });

        Http::assertSent(function (Request $request): bool {
            return $request['input'] === "Presentation PVIT\n\nPVIT agit comme une passerelle."
                && $request['input_type'] === 'passage';
        });

        Http::assertSentCount(2);
    }

    public function test_it_retries_transient_failures_and_hides_provider_bodies(): void
    {
        Http::fake([
            self::BASE_URL.'/embeddings' => Http::sequence()
                ->push(['error' => ['message' => 'temporary internal detail']], 503)
                ->push(['data' => [['embedding' => array_fill(0, 768, 0.25)]]], 200)
                ->push(['error' => ['message' => 'sensitive provider detail']], 400),
        ]);

        $client = new NvidiaEmbeddingClient;

        $this->assertCount(768, $client->embed('Question', 'query'));

        try {
            $client->embed('Question invalide', 'query');
            $this->fail('A rejected request should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('NVIDIA embedding request was rejected (HTTP 400).', $exception->getMessage());
            $this->assertStringNotContainsString('sensitive provider detail', $exception->getMessage());
        }

        Http::assertSentCount(3);
    }

    public function test_it_rejects_vectors_with_an_unexpected_dimension(): void
    {
        Http::fake([
            self::BASE_URL.'/embeddings' => Http::response([
                'data' => [['embedding' => [0.1, 0.2]]],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NVIDIA embedding returned 2 dimensions; 768 were expected.');

        (new NvidiaEmbeddingClient)->embed('Question', 'query');
    }
}
