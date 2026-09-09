<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KnowledgeVersionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['rag.admin_key' => 'test-admin-key']);
    }

    public function test_admin_can_create_validate_and_activate_a_version(): void
    {
        $created = $this->withToken('test-admin-key')->postJson('/api/knowledge/versions', [
            'version' => 'kb-2026-08-05',
            'metadata' => ['source' => 'manual'],
        ])->assertCreated()->assertJsonPath('data.statut', 'staging');

        $versionId = $created->json('data.id');
        $sourceUrl = 'https://docs.mypvit.pro/fr/test/kb-2026-08-05';
        Http::fake([$sourceUrl => Http::response('', 200)]);

        $this->withToken('test-admin-key')->post('/api/knowledge/sources', [
            'source' => UploadedFile::fake()->createWithContent(
                'knowledge.md',
                "# Documentation officielle\n\nCette documentation explique le parcours d integration PVIT.",
            ),
            'moyen_paiement' => 'PVIT',
            'source_url' => $sourceUrl,
            'knowledge_version_id' => $versionId,
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->withToken('test-admin-key')
            ->postJson("/api/knowledge/versions/{$versionId}/validate")
            ->assertOk()
            ->assertJsonPath('data.statut', 'validated');

        $this->withToken('test-admin-key')
            ->postJson("/api/knowledge/versions/{$versionId}/activate")
            ->assertOk()
            ->assertJsonPath('data.statut', 'active');

        $this->assertDatabaseCount('audit_logs', 4);
    }

    public function test_version_routes_require_the_admin_key(): void
    {
        $this->getJson('/api/knowledge/versions')->assertForbidden();
    }

    public function test_invalid_state_transition_returns_conflict_without_an_audit_entry(): void
    {
        $versionId = $this->withToken('test-admin-key')
            ->postJson('/api/knowledge/versions', ['version' => 'kb-staging'])
            ->assertCreated()
            ->json('data.id');

        $this->withToken('test-admin-key')
            ->postJson("/api/knowledge/versions/{$versionId}/activate")
            ->assertConflict()
            ->assertJsonPath('message', 'La transition de version de connaissance est impossible.');

        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_activation_and_its_audit_entry_are_atomic(): void
    {
        $versionId = $this->withToken('test-admin-key')
            ->postJson('/api/knowledge/versions', ['version' => 'kb-atomic-audit'])
            ->assertCreated()
            ->json('data.id');
        $sourceUrl = 'https://docs.mypvit.pro/fr/test/kb-atomic-audit';
        Http::fake([$sourceUrl => Http::response('', 200)]);

        $import = $this->withToken('test-admin-key')->post('/api/knowledge/sources', [
            'source' => UploadedFile::fake()->createWithContent(
                'atomic.md',
                "# Corpus atomique\n\nUne phrase officielle exploitable pour le test de publication.",
            ),
            'moyen_paiement' => 'PVIT',
            'source_url' => $sourceUrl,
            'knowledge_version_id' => $versionId,
        ], ['Accept' => 'application/json'])->assertCreated();

        $documentId = $import->json('data.document_id');
        $this->withToken('test-admin-key')
            ->postJson("/api/knowledge/versions/{$versionId}/validate")
            ->assertOk();

        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_activation_audit
            BEFORE INSERT ON audit_logs
            WHEN NEW.action = 'knowledge.version.activated'
            BEGIN
                SELECT RAISE(ABORT, 'forced audit failure');
            END
            SQL);

        $this->withToken('test-admin-key')
            ->postJson("/api/knowledge/versions/{$versionId}/activate")
            ->assertInternalServerError();

        $this->assertDatabaseHas('knowledge_versions', ['id' => $versionId, 'statut' => 'validated']);
        $this->assertDatabaseHas('documents_api', ['id' => $documentId, 'actif' => false]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'knowledge.version.activated']);
    }
}
