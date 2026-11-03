<?php

namespace Tests\Feature;

use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\DocumentApi;
use App\Services\Assistant\ConversationEvidence;
use App\Services\Assistant\JsonExamples;
use App\Services\Llm\OpenAiCompatibleLlmClient;
use App\Services\Rag\RetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class EighthAuditPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function noProviders(): void
    {
        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldNotReceive('search');
        $this->instance(RetrievalService::class, $retrieval);
        $llm = Mockery::mock(OpenAiCompatibleLlmClient::class);
        $llm->shouldNotReceive('completeWithProvider');
        $llm->shouldNotReceive('completeWithImage');
        $this->instance(OpenAiCompatibleLlmClient::class, $llm);
    }

    public function test_a_refund_navigation_cannot_consume_the_sandbox_plan(): void
    {
        $memory = ['topic_question' => 'Paiements Sandbox', 'last_question' => 'Plan Sandbox', 'plan_subject' => 'sandbox', 'plan_steps' => ['Identifiants de test.', 'Callback Sandbox.', 'Simulation succès.'], 'plan_index' => 0, 'plan_sources' => []];
        $conversation = Conversation::create(['reference' => 'conv_owner_eighth', 'statut' => 'active', 'contexte' => $memory]);
        $this->noProviders();
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Va à l’étape 2 de la procédure de remboursement.', 'conversation_id' => $conversation->reference])->assertOk();
        $this->assertStringContainsString('autre procédure', $response->json('answer'));
        $this->assertStringNotContainsString('Callback Sandbox', $response->json('answer'));
        $this->assertSame($memory, $conversation->fresh()->contexte);
        Cache::flush();
        $this->postJson('/api/mistral-raw/ask', ['question' => 'Va à l’étape 3.', 'conversation_id' => $conversation->reference])->assertOk()->assertJsonPath('answer', 'Étape 3 : Simulation succès.');
    }

    public function test_days_convert_to_hours_after_database_reload(): void
    {
        $conversation = Conversation::create(['reference' => 'conv_units_eighth', 'statut' => 'active', 'contexte' => ['topic_question' => 'Durée des exports', 'last_question' => 'Combien de temps un export est-il disponible ?', 'last_answer' => 'Le fichier reste disponible 7 jours après la demande [1].', 'last_sources' => []]]);
        $this->noProviders();
        Cache::flush();
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Convertis cette durée en heures.', 'conversation_id' => $conversation->reference])->assertOk();
        $this->assertStringContainsString('168 heures', $response->json('answer'));
        Cache::flush();
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Et en minutes ?', 'conversation_id' => $conversation->reference])->assertOk();
        $this->assertStringContainsString('10080 minutes', $response->json('answer'));
    }

    public function test_declined_php_still_allows_the_previous_documentary_json(): void
    {
        $json = "Accusé à renvoyer :\n```json\n{\"transactionId\":\"PAY1\",\"responseCode\":200}\n```";
        $conversation = Conversation::create(['reference' => 'conv_php_eighth', 'statut' => 'active', 'contexte' => ['topic_question' => 'Callback', 'last_question' => 'Quel JSON pour l’accusé de réception du callback ?', 'last_answer' => '`transactionId`, `responseCode`.', 'payload_answer' => $json, 'payload_sources' => []]]);
        $this->noProviders();
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Je ne veux pas de code PHP, mais montre l’exemple JSON de cet accusé de réception.', 'conversation_id' => $conversation->reference])->assertOk();
        $this->assertStringContainsString('```json', $response->json('answer'));
        $this->assertStringContainsString('responseCode', $response->json('answer'));
        $this->assertStringNotContainsString('Je ne peux pas générer', $response->json('answer'));
    }

    public function test_structured_keys_survive_a_raw_json_larger_than_the_old_text_limit(): void
    {
        $json = "Réponse à renvoyer :\n```json\n".json_encode(['transactionId' => str_repeat('X', 10000), 'responseCode' => 200])."\n```";
        $conversation = Conversation::create(['reference' => 'conv_long_eighth', 'statut' => 'active', 'contexte' => ['topic_question' => 'Callback', 'last_question' => 'Accusé de réception callback', 'last_answer' => substr($json, 0, 8000), 'payload_answer' => substr($json, 0, 8000), 'payload_examples' => (new JsonExamples)->extract($json), 'payload_sources' => []]]);
        $this->noProviders();
        Cache::flush();
        $this->postJson('/api/mistral-raw/ask', ['question' => 'Indique les noms des champs à renvoyer.', 'conversation_id' => $conversation->reference])->assertOk()->assertJsonPath('answer', '`transactionId`, `responseCode`.');
    }

    public function test_a_document_update_invalidates_persisted_plan_and_examples(): void
    {
        $doc = DocumentApi::create(['titre' => 'Guide Sandbox', 'actif' => true, 'lien_officiel' => 'https://docs.mypvit.pro/fr/test', 'link_verified_at' => now()]);
        $chunk = Chunk::create(['document_id' => $doc->id, 'contenu' => 'Première procédure active.', 'section' => 'Plan', 'position' => 0, 'status' => 'active']);
        $sources = [['chunk_id' => $chunk->id]];
        $stamp = (new ConversationEvidence)->capture($sources);
        $this->assertTrue((new ConversationEvidence)->isCurrent($stamp));
        $conversation = Conversation::create(['reference' => 'conv_stale_eighth', 'statut' => 'active', 'contexte' => ['topic_question' => 'Sandbox', 'last_question' => 'Plan Sandbox', 'last_answer' => 'Ancienne réponse', 'plan_subject' => 'sandbox', 'plan_steps' => ['Ancienne étape 1', 'Ancienne étape 2'], 'plan_index' => 0, 'plan_sources' => $sources, 'evidence_stamp' => $stamp, 'payload_answer' => 'Ancien exemple']]);
        $chunk->update(['contenu' => 'Nouvelle procédure active.']);
        $this->noProviders();
        Cache::flush();
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Va à l’étape 2.', 'conversation_id' => $conversation->reference])->assertOk();
        $this->assertStringContainsString('documentation utilisée dans cette conversation a changé', $response->json('answer'));
        $this->assertSame([], $conversation->fresh()->contexte['plan_steps']);
        $this->assertSame('', $conversation->fresh()->contexte['payload_answer']);
    }

    public function test_deactivated_sources_are_not_current_even_when_the_text_did_not_change(): void
    {
        $doc = DocumentApi::create(['titre' => 'Guide', 'actif' => true, 'lien_officiel' => 'https://docs.mypvit.pro/fr/test', 'link_verified_at' => now()]);
        $chunk = Chunk::create(['document_id' => $doc->id, 'contenu' => 'Même texte.', 'position' => 0, 'status' => 'active']);
        $service = new ConversationEvidence;
        $stamp = $service->capture([['chunk_id' => $chunk->id]]);
        $doc->update(['actif' => false]);
        $this->assertFalse($service->isCurrent($stamp));
    }
}
