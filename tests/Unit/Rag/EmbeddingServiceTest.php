<?php

namespace Tests\Unit\Rag;

use App\Services\Rag\EmbeddingService;
use App\Services\Rag\GeminiEmbeddingClient;
use App\Services\Rag\LocalHashEmbeddingClient;
use App\Services\Rag\NvidiaEmbeddingClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmbeddingServiceTest extends TestCase
{
    public function test_provider_fallback_is_disabled_by_default_to_preserve_vector_space(): void
    {
        config()->set([
            'embedding.default_provider' => 'nvidia',
            'embedding.fallback_providers' => ['nvidia', 'local'],
            'embedding.allow_provider_fallback' => false,
            'embedding.retry_attempts' => 1,
            'embedding.providers.nvidia.base_url' => 'https://nvidia.test/v1',
            'embedding.providers.nvidia.api_key' => 'test-key',
            'embedding.providers.nvidia.model' => 'test-model',
        ]);
        Http::fake(['https://nvidia.test/v1/embeddings' => Http::response([], 503)]);

        $service = app(EmbeddingService::class);

        $this->expectExceptionMessage('No configured embedding provider succeeded. nvidia:');
        $service->embedForQuery('question de test');
    }

    public function test_compatible_fallback_requires_an_explicit_opt_in(): void
    {
        config()->set([
            'embedding.default_provider' => 'nvidia',
            'embedding.fallback_providers' => ['nvidia', 'local'],
            'embedding.allow_provider_fallback' => true,
            'embedding.retry_attempts' => 1,
            'embedding.dimensions' => 8,
            'embedding.providers.nvidia.base_url' => 'https://nvidia.test/v1',
            'embedding.providers.nvidia.api_key' => 'test-key',
            'embedding.providers.nvidia.model' => 'test-model',
        ]);
        Http::fake(['https://nvidia.test/v1/embeddings' => Http::response([], 503)]);

        $service = new EmbeddingService(
            app(NvidiaEmbeddingClient::class),
            app(GeminiEmbeddingClient::class),
            app(LocalHashEmbeddingClient::class),
        );
        $vector = $service->embedForQuery('question de test');

        $this->assertCount(8, $vector);
        $this->assertSame('local:local-hash-v1', $service->lastModel());
    }
}
