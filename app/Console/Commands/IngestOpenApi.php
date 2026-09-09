<?php

namespace App\Console\Commands;

use App\Services\Rag\OpenApiIngestionService;
use Illuminate\Console\Command;
use Throwable;

class IngestOpenApi extends Command
{
    protected $signature = 'rag:ingest-openapi
        {path : Local OpenAPI JSON file}
        {--source-url= : Official HTTPS URL of the OpenAPI JSON file}
        {--moyen=PVIT : Payment method name}
        {--doc-version= : API documentation version override}
        {--knowledge-version= : Staging knowledge version ID}';

    protected $description = 'Validate and ingest an official OpenAPI JSON specification as safe explanatory prose.';

    public function handle(OpenApiIngestionService $ingestion): int
    {
        $path = (string) $this->argument('path');
        $sourceUrl = trim((string) $this->option('source-url'));
        $moyenPaiement = (string) $this->option('moyen');

        if ($sourceUrl === '') {
            $this->error('--source-url est obligatoire.');

            return self::INVALID;
        }

        try {
            $result = $ingestion->ingestFile(
                $path,
                $moyenPaiement,
                $sourceUrl,
                trim((string) $this->option('doc-version')) ?: null,
                $this->option('knowledge-version') !== null ? (int) $this->option('knowledge-version') : null,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(
            "OpenAPI #{$result['open_api_spec_id']} indexee dans le document #{$result['document_id']} "
            ."({$result['paths']} chemins, {$result['operations']} operations).",
        );

        return self::SUCCESS;
    }
}
