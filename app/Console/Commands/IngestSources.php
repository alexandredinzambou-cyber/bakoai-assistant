<?php

namespace App\Console\Commands;

use App\Services\Rag\IngestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class IngestSources extends Command
{
    protected $signature = 'rag:ingest
        {--path= : Local file or directory containing .md, .html or .txt sources}
        {--url=* : Official documentation URL to download then ingest}
        {--source-url= : Official URL associated with one manually provided local file}
        {--moyen=PVIT : Payment method name}
        {--doc-version= : Documentation version}
        {--knowledge-version= : Staging knowledge version ID}';

    protected $description = 'Ingest BakoAI/PVIT documentation sources into PostgreSQL pgvector.';

    public function handle(IngestionService $ingestion): int
    {
        if (! extension_loaded('pdo_pgsql')) {
            $this->error('The PHP extension pdo_pgsql is not loaded. Enable/install pdo_pgsql before running rag:ingest because PostgreSQL + pgvector is required.');
            $this->line('Current php.ini: '.(php_ini_loaded_file() ?: 'none'));

            return self::FAILURE;
        }

        $path = $this->option('path') ?: config('rag.sources_path');
        $moyen = (string) $this->option('moyen');
        $remoteUrls = (array) $this->option('url');
        $manualSourceUrl = trim((string) $this->option('source-url')) ?: null;
        $knowledgeVersionId = $this->option('knowledge-version') !== null
            ? (int) $this->option('knowledge-version')
            : null;

        if ($remoteUrls !== [] && ($manualSourceUrl !== null || File::isFile($path))) {
            $this->error('--url exige un dossier de destination et ne peut pas être combiné avec --source-url.');

            return self::INVALID;
        }

        if ($remoteUrls === [] && $manualSourceUrl !== null && ! File::isFile($path)) {
            $this->error('--source-url exige que --path désigne un seul fichier local existant.');

            return self::INVALID;
        }

        if ($remoteUrls !== [] || ! File::exists($path)) {
            File::ensureDirectoryExists($path);
        }

        if (! in_array($moyen, (array) config('rag.documentation_corpora', ['PVIT']), true)) {
            $this->error("Corpus documentaire non autorise : {$moyen}. Utilisez PVIT pour les moyens servis par la passerelle.");

            return self::INVALID;
        }

        $results = [];
        $downloaded = [];

        foreach ($remoteUrls as $url) {
            try {
                $downloadedPath = $this->download($url, $path);
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            if ($downloadedPath) {
                $downloaded[] = ['path' => $downloadedPath, 'url' => $url];
            }
        }

        try {
            if ($downloaded !== []) {
                foreach ($downloaded as $source) {
                    $results[] = $ingestion->ingestFile(
                        $source['path'],
                        $moyen,
                        $source['url'],
                        $this->option('doc-version') ?: $this->inferVersion($source['url']),
                        $knowledgeVersionId,
                    );
                }
            } elseif (File::isFile($path)) {
                $results[] = $ingestion->ingestFile(
                    $path,
                    $moyen,
                    $manualSourceUrl,
                    $this->option('doc-version') ?: null,
                    $knowledgeVersionId,
                );
            } else {
                $results = $ingestion->ingestDirectory(
                    $path,
                    $moyen,
                    $manualSourceUrl,
                    $this->option('doc-version') ?: null,
                    $knowledgeVersionId,
                );
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($results as $result) {
            $this->line("Indexed document #{$result['document_id']}: {$result['titre']} ({$result['chunks']} chunks)");
        }

        $this->info('Ingestion complete.');

        return self::SUCCESS;
    }

    private function download(string $url, string $path): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $allowedHosts = array_map('strtolower', (array) config('rag.allowed_source_hosts', []));

        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! in_array($host, $allowedHosts, true)) {
            throw new \RuntimeException("URL HTTPS hors liste blanche : {$url}");
        }

        $response = Http::accept('text/html, text/markdown, application/json')
            ->withOptions($this->httpOptions())
            ->connectTimeout((int) config('rag.source_connect_timeout', 20))
            ->timeout((int) config('rag.source_timeout', 35))
            ->retry(2, 500, throw: false)
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException("Echec du telechargement {$url}: HTTP {$response->status()}");
        }

        $contentType = strtolower((string) $response->header('Content-Type'));

        if (str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '.json')
            || str_contains($contentType, 'json')) {
            throw new \RuntimeException('Le JSON brut n est pas indexe par rag:ingest. Telechargez la specification puis utilisez rag:ingest-openapi.');
        }

        if (strlen($response->body()) > (int) config('rag.max_source_bytes', 5 * 1024 * 1024)) {
            throw new \RuntimeException("Source distante trop volumineuse : {$url}");
        }

        $filename = Str::slug(parse_url($url, PHP_URL_PATH) ?: md5($url)) ?: md5($url);
        $target = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename.'.html';

        File::put($target, $response->body());
        $this->line("Downloaded {$url} to {$target}");

        return $target;
    }

    private function inferVersion(string $url): ?string
    {
        return preg_match('~/v([0-9]+)/~', $url, $match) === 1 ? 'v'.$match[1] : null;
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
