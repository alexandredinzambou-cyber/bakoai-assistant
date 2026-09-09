<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssistantApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_code_request_is_refused_and_persisted_without_calling_an_external_service(): void
    {
        Http::preventStrayRequests();

        $response = $this->postJson('/api/ask', [
            'question' => 'Écris le code PHP complet pour initier un paiement.',
            'moyen_paiement' => 'PVIT',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'refused_code')
            ->assertJsonPath('reason', 'code_request')
            ->assertJsonPath('contains_code', false)
            ->assertJsonPath('should_escalate', false);

        $questionId = $response->json('question_id');
        $this->assertDatabaseHas('questions', ['id' => $questionId]);
        $this->assertDatabaseHas('reponses', [
            'question_id' => $questionId,
            'statut' => 'refused_code',
            'guard_reason' => 'code_request',
        ]);
        Http::assertNothingSent();
    }

    public function test_retrieval_failure_becomes_a_safe_support_decision_instead_of_http_500(): void
    {
        Http::preventStrayRequests();

        // phpunit.xml forces the local hash-based embedding provider so the suite never needs
        // network access. That provider never makes an HTTP call, so it would silently succeed
        // here and mask the failure this test means to simulate. Force a network-backed provider
        // just for this test so Http::preventStrayRequests() actually triggers retrieval failure.
        config([
            'embedding.default_provider' => 'nvidia',
            'embedding.allow_provider_fallback' => false,
        ]);

        $this->postJson('/api/ask', [
            'question' => 'Comment fonctionne un callback Airtel Money ?',
            'moyen_paiement' => 'Airtel Money',
            'operation' => 'callback',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'needs_support')
            ->assertJsonPath('reason', 'retrieval_unavailable')
            ->assertJsonPath('should_escalate', true)
            ->assertJsonPath('contains_code', false);

        Http::assertNothingSent();
    }

    public function test_filters_are_restricted_to_the_known_scope(): void
    {
        $this->postJson('/api/ask', [
            'question' => 'Question valide mais filtre inconnu',
            'moyen_paiement' => 'Crypto inconnue',
            'operation' => 'contournement',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['moyen_paiement', 'operation']);

        $this->assertDatabaseCount('questions', 0);
    }

    public function test_validation_errors_are_localized_for_api_clients(): void
    {
        $this->postJson('/api/ask', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.question.0', 'Le champ question est obligatoire.');
    }

    public function test_public_clients_cannot_attach_questions_to_an_arbitrary_developer(): void
    {
        $this->postJson('/api/ask', [
            'question' => 'Bonjour',
            'developpeur_id' => 42,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('developpeur_id');

        $this->assertDatabaseCount('questions', 0);
    }

    public function test_ticket_creation_is_idempotent(): void
    {
        $ask = $this->postJson('/api/ask', [
            'question' => 'Comment fonctionne un callback Airtel Money ?',
            'moyen_paiement' => 'Airtel Money',
        ])->assertOk()->assertJsonPath('should_escalate', true);

        $payload = [
            'question_id' => $ask->json('question_id'),
            'ticket_token' => $ask->json('ticket_token'),
        ];

        $first = $this->postJson('/api/ticket', $payload)
            ->assertCreated();
        $second = $this->postJson('/api/ticket', $payload)
            ->assertOk();

        $this->assertSame($first->json('ticket_id'), $second->json('ticket_id'));
        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_ticket_creation_rejects_an_unrelated_or_tampered_token(): void
    {
        $this->postJson('/api/ticket', [
            'question_id' => 123,
            'ticket_token' => 'tampered-token',
        ])->assertForbidden();
    }

    public function test_status_is_side_effect_free_and_admin_route_is_protected(): void
    {
        Http::preventStrayRequests();

        $this->getJson('/api/status')
            ->assertOk()
            ->assertJsonPath('connected', false)
            ->assertJsonStructure(['database', 'pgvector', 'llm_configured', 'provider', 'embedding_provider', 'embedding_model', 'documents']);

        config(['rag.admin_key' => 'test-admin-key']);

        $this->withHeader('X-BakoAI-Admin-Key', 'wrong-key')
            ->getJson('/api/knowledge/sources')
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_knowledge_upload_rejects_a_source_url_outside_the_official_allowlist(): void
    {
        config([
            'rag.admin_key' => 'test-admin-key',
            'rag.allowed_source_hosts' => ['docs.mypvit.pro'],
        ]);

        $this->withToken('test-admin-key')
            ->post('/api/knowledge/sources', [
                'source' => UploadedFile::fake()->create('source.md', 1, 'text/markdown'),
                'moyen_paiement' => 'PVIT',
                'source_url' => 'https://evil.example/source',
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source_url');

        $this->assertDatabaseCount('documents_api', 0);
    }

    public function test_knowledge_upload_rejects_a_path_outside_the_french_documentation_scope(): void
    {
        config([
            'rag.admin_key' => 'test-admin-key',
            'rag.allowed_source_hosts' => ['docs.mypvit.pro'],
            'rag.allowed_source_path_prefixes' => ['/fr/'],
        ]);

        $this->withToken('test-admin-key')
            ->post('/api/knowledge/sources', [
                'source' => UploadedFile::fake()->create('source.md', 1, 'text/markdown'),
                'moyen_paiement' => 'PVIT',
                'source_url' => 'https://docs.mypvit.pro/en/intro/getting-started',
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source_url');

        $this->assertDatabaseCount('documents_api', 0);
    }
}
