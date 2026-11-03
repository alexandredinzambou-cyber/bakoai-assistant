<?php

namespace App\Services\Evaluation;

use App\Services\Rag\EmbeddingService;
use Illuminate\Support\Facades\File;

/**
 * Query-embedding cache for evaluation runs: a benchmark rerun after a ranking change must not
 * depend on the embedding provider being up, nor pay for the same vectors again. Entries are
 * keyed by the configured embedding model and the exact text, so a model change never reuses a
 * stale vector.
 *
 * Only a vector produced by the configured model is cached. A fallback embedding (the provider
 * was down and a lower-quality model answered) is returned uncached and reported through
 * fallbackQueries(): a run that retrieved with the wrong model measures an outage, not the
 * ranking, and is invalidated by its command.
 */
class CachedQueryEmbeddingService extends EmbeddingService
{
    /** Off for --no-cache: vectors are embedded again, and the cache refreshed. */
    public bool $readCache = true;

    private string $cachedModel = '';

    /** @var list<string> */
    private array $fallbackQueries = [];

    public function embedForQuery(string $text): array
    {
        $expectedModel = self::primaryModel();
        $file = storage_path('app/eval-cache/embeddings/'.sha1($expectedModel."\n".$text).'.json');

        if ($this->readCache && is_file($file)) {
            $cached = json_decode((string) file_get_contents($file), true);

            if (is_array($cached) && ($cached['model'] ?? null) === $expectedModel && is_array($cached['vector'] ?? null)) {
                $this->cachedModel = $expectedModel;

                return $cached['vector'];
            }
        }

        $vector = parent::embedForQuery($text);
        $this->cachedModel = parent::lastModel();

        if ($this->cachedModel === $expectedModel) {
            File::ensureDirectoryExists(dirname($file));
            File::put($file, json_encode(['model' => $this->cachedModel, 'vector' => $vector]));
        } else {
            $this->fallbackQueries[] = $text;
        }

        return $vector;
    }

    /**
     * The model the index is built with, as EmbeddingService::lastModel() names it
     * ("provider:model").
     */
    public static function primaryModel(): string
    {
        $provider = (string) config('embedding.default_provider', 'nvidia');

        return $provider.':'.(string) config("embedding.providers.{$provider}.model");
    }

    public function lastModel(): string
    {
        return $this->cachedModel !== '' ? $this->cachedModel : parent::lastModel();
    }

    /**
     * @return list<string>
     */
    public function fallbackQueries(): array
    {
        return $this->fallbackQueries;
    }
}
