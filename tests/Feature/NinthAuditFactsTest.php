<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Services\Llm\OpenAiCompatibleLlmClient;
use App\Services\Rag\RetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class NinthAuditFactsTest extends TestCase
{
    use RefreshDatabase;

    private function sources(string $text, string $title): void
    {
        $chunk = (object) ['chunk_id' => 901, 'contenu' => $text, 'document_titre' => $title, 'section' => $title, 'lien_officiel' => 'https://docs.mypvit.pro/fr/callback'];
        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldReceive('search')->andReturn(collect([$chunk]));
        $this->instance(RetrievalService::class, $retrieval);
        $model = Mockery::mock(OpenAiCompatibleLlmClient::class);
        $model->shouldNotReceive('completeWithProvider');
        $model->shouldNotReceive('completeWithImage');
        $this->instance(OpenAiCompatibleLlmClient::class, $model);
    }

    public function test_missing_json_role_is_retrieved_then_both_roles_survive_reload(): void
    {
        $ack = "Votre serveur répond HTTP 200 avec un écho dynamique : responseCode reprend le champ code de la notification reçue.\n```json\n{\"transactionId\":\"A\",\"responseCode\":200}\n```";
        $incoming = "Notification reçue :\n```json\n{\"transactionId\":\"A\",\"amount\":150,\"status\":\"SUCCESS\",\"code\":200}\n```";
        $conversation = Conversation::create(['reference' => 'conv_ninth_roles', 'statut' => 'active', 'contexte' => ['topic_question' => 'Callback', 'last_question' => 'Quel accusé pour le callback ?', 'last_answer' => $ack, 'payload_answer' => $ack, 'last_sources' => []]]);
        $credentials = "## Webhook de notification\n## Authentification\n### Réponse attendue\n```json\n{\"secret\":\"example\",\"expires_in\":3600}\n```\n## Notification reçue\n";
        $report = "\n## Corps de la notification de reporting\n```json\n{\"event\":\"export.complete\",\"job_id\":\"A\",\"status\":\"SUCCESS\"}\n```";
        $this->sources($credentials.$incoming."\n".$ack.$report, 'Réception callback');
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Liste les noms des champs de la notification entrante.', 'conversation_id' => $conversation->reference])->assertOk();
        $this->assertStringContainsString('amount', $response->json('answer'));
        $this->assertStringNotContainsString('responseCode', $response->json('answer'));
        Cache::flush();
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Donne les noms des champs de l’accusé à renvoyer.', 'conversation_id' => $conversation->reference])->assertOk();
        $this->assertStringContainsString('responseCode', $response->json('answer'));
        $this->assertStringNotContainsString('amount', $response->json('answer'));
    }

    public function test_direct_callback_fact_obeys_two_steps_and_retains_complete_plan(): void
    {
        $this->sources("## Étapes pour configurer un callback\n### 1. Ouvrez la configuration\nAccédez au menu callback.\n### 2. Saisissez votre URL publique\nIndiquez votre adresse de réception.\n### 3. Soumettre\nEnregistrez le formulaire.", 'Configurer un callback');
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Résume comment configurer une URL de callback en deux étapes courtes.'])->assertOk();
        $this->assertSame(2, preg_match_all('/^\d+\. /mu', $response->json('answer')));
        $this->assertStringContainsString('Soumettre', $response->json('answer'));
        $conversation = Conversation::where('reference', $response->json('conversation_id'))->firstOrFail();
        $this->assertCount(3, $conversation->contexte['plan_steps']);
        Cache::flush();
        $this->postJson('/api/mistral-raw/ask', ['question' => 'Va à l’étape 3.', 'conversation_id' => $conversation->reference])->assertOk()->assertSee('Soumettre');
    }

    public function test_named_duration_reminder_converts_instead_of_repeating_seconds(): void
    {
        $conversation = Conversation::create(['reference' => 'conv_ninth_reminder', 'statut' => 'active', 'contexte' => ['topic_question' => 'Clé secrète', 'last_question' => 'Validité de la clé secrète', 'last_answer' => '3600 secondes après génération.', 'last_sources' => []]]);
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Rappelle la durée de la clé secrète en heures.', 'conversation_id' => $conversation->reference])->assertOk();
        $this->assertStringContainsString('1 heure', $response->json('answer'));
        $this->assertSame('conversion', $response->json('answer_type'));
    }

    public function test_a_different_named_duration_uses_fresh_facts_instead_of_the_old_export(): void
    {
        $conversation = Conversation::create(['reference' => 'conv_ninth_fresh_duration', 'statut' => 'active', 'contexte' => ['topic_question' => 'Durée export', 'last_question' => 'Durée export', 'last_answer' => '7 jours après la demande.', 'duration_fact' => ['value' => 7, 'unit' => 'jour'], 'last_sources' => []]]);
        $this->sources('{"secret":"example", "expires_in":3600}', 'Clé secrète');
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Rappelle la durée de la clé secrète en heures.', 'conversation_id' => $conversation->reference])->assertOk();
        $this->assertStringContainsString('1 heure', $response->json('answer'));
        $this->assertStringNotContainsString('168 heures', $response->json('answer'));
    }
}
