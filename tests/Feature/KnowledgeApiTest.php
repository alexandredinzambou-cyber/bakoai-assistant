<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KnowledgeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_upload_returns_a_validation_error_when_the_official_link_is_unreachable(): void
    {
        config(['rag.admin_key' => 'a-long-test-administration-key']);
        $sourceUrl = 'https://docs.mypvit.pro/fr/tutoriels/unreachable';
        Http::fake([$sourceUrl => Http::response('', 404)]);

        $response = $this
            ->withToken('a-long-test-administration-key')
            ->post('/api/knowledge/sources', [
                'source' => UploadedFile::fake()->createWithContent(
                    'documentation.md',
                    "# Documentation\n\nUne phrase documentaire suffisamment longue.",
                ),
                'moyen_paiement' => 'PVIT',
                'source_url' => $sourceUrl,
            ], ['Accept' => 'application/json']);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source_url');
        $this->assertDatabaseCount('documents_api', 0);
    }

    public function test_admin_upload_requires_the_shared_pvit_documentation_corpus(): void
    {
        config(['rag.admin_key' => 'a-long-test-administration-key']);
        Http::preventStrayRequests();

        $this
            ->withToken('a-long-test-administration-key')
            ->post('/api/knowledge/sources', [
                'source' => UploadedFile::fake()->createWithContent(
                    'airtel.md',
                    "# Documentation\n\nUne phrase documentaire suffisamment longue.",
                ),
                'moyen_paiement' => 'Airtel Money',
                'source_url' => 'https://docs.mypvit.pro/fr/tutoriels/register',
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('moyen_paiement');

        Http::assertNothingSent();
        $this->assertDatabaseCount('documents_api', 0);
    }

    public function test_successful_admin_upload_is_audited_in_the_ingestion_transaction(): void
    {
        config(['rag.admin_key' => 'a-long-test-administration-key']);
        $sourceUrl = 'https://docs.mypvit.pro/fr/tutoriels/audited-source';
        Http::fake([$sourceUrl => Http::response('', 200)]);

        $response = $this
            ->withToken('a-long-test-administration-key')
            ->withHeader('X-Correlation-ID', 'knowledge-import-test')
            ->post('/api/knowledge/sources', [
                'source' => UploadedFile::fake()->createWithContent(
                    'audited.md',
                    "# Documentation auditee\n\nUne phrase documentaire officielle suffisamment longue.",
                ),
                'moyen_paiement' => 'PVIT',
                'source_url' => $sourceUrl,
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'knowledge.source.imported',
            'ressource' => 'document_api',
            'ressource_id' => (string) $response->json('data.document_id'),
            'correlation_id' => 'knowledge-import-test',
        ]);
    }
}
