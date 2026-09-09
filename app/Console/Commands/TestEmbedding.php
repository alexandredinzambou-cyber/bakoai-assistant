<?php

namespace App\Console\Commands;

use App\Services\Rag\EmbeddingService;
use Illuminate\Console\Command;
use Throwable;

class TestEmbedding extends Command
{
    protected $signature = 'embedding:test
        {--provider=fallback : Provider to test: fallback, nvidia, gemini or local}
        {--type=query : Input type: query or document}
        {--text=Comment renouveler le secret PVIT ? : Text to embed}';

    protected $description = 'Test configured embedding providers without running PostgreSQL or ingestion.';

    public function handle(EmbeddingService $embeddingService): int
    {
        $provider = (string) $this->option('provider');
        $type = (string) $this->option('type');
        $text = (string) $this->option('text');

        $this->line("Testing embedding provider: {$provider}");

        try {
            $vector = $provider === 'fallback'
                ? ($type === 'query' ? $embeddingService->embedForQuery($text) : $embeddingService->embedForDocument($text, 'Test document'))
                : $embeddingService->embedWithProvider($provider, $text, $type, 'Test document');
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Embedding call succeeded.');
        $this->line('Model: '.$embeddingService->lastModel());
        $this->line('Dimensions: '.count($vector));
        $this->line('First values: '.implode(', ', array_map(static fn (float $value): string => (string) round($value, 6), array_slice($vector, 0, 8))));

        return self::SUCCESS;
    }
}
