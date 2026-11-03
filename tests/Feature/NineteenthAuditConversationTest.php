<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Services\Llm\OpenAiCompatibleLlmClient;
use App\Services\Rag\RetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class NineteenthAuditConversationTest extends TestCase
{
    use RefreshDatabase;

    private function providers(bool $search = false): void
    {
        $retrieval = Mockery::mock(RetrievalService::class);
        if ($search) {
            $retrieval->shouldReceive('search')->andReturn(collect([(object) ['chunk_id' => 1100, 'document_titre' => 'Obtenir les frais', 'section' => 'Contrat', 'lien_officiel' => 'https://docs.mypvit.pro/fr/api/fees', 'contenu' => "## Paramètres de chemin\n- code-url-fees (string, Obligatoire) — Routage documenté.\n## Paramètres de requête\n- accountOperationCode (string, Obligatoire) — Compte documenté.\n- transactionType (string, Obligatoire) — Type de transaction documenté.\n- amount (number, Obligatoire) — Montant documenté.\n- operatorCode (string, Obligatoire) — Opérateur documenté.\n## En-têtes\n- X-Secret (string, Obligatoire) — Authentification documentée."]]));
        } else {
            $retrieval->shouldNotReceive('search');
        }
        $this->instance(RetrievalService::class, $retrieval);
        $llm = Mockery::mock(OpenAiCompatibleLlmClient::class);
        $llm->shouldNotReceive('completeWithProvider');
        $this->instance(OpenAiCompatibleLlmClient::class, $llm);
    }

    private function durationConversation(): Conversation
    {
        return Conversation::create(['reference' => 'conv_nineteenth_export', 'statut' => 'active', 'contexte' => ['topic_question' => 'Disponibilité du fichier d’export', 'last_question' => 'Combien de jours le fichier d’export reste-t-il disponible ?', 'last_answer' => 'Le fichier reste disponible 7 jours après la demande.', 'last_verified_answer' => 'Le fichier reste disponible 7 jours après la demande.', 'duration_fact' => ['value' => 7, 'unit' => 'jour'], 'last_sources' => []]]);
    }

    public function test_repeated_multiunit_conversions_preserve_the_canonical_fact_and_topic_after_reload(): void
    {
        $this->providers();
        $conversation = $this->durationConversation();
        foreach (['En heures et en minutes.', 'Et en heures et en minutes ?', 'En heures, puis en minutes.'] as $question) {
            Cache::flush();
            $response = $this->postJson('/api/mistral-raw/ask', ['question' => $question, 'conversation_id' => $conversation->reference])->assertOk()->assertJsonPath('should_escalate', false);
            $this->assertStringContainsString('168 heures', $response->json('answer'));
            $this->assertStringContainsString('10080 minutes', $response->json('answer'));
            $saved = $conversation->fresh()->contexte;
            $this->assertSame(['value' => 7, 'unit' => 'jour'], $saved['duration_fact']);
            $this->assertSame('Disponibilité du fichier d’export', $saved['topic_question']);
        }
    }

    public function test_unsupported_conversion_clarifies_without_retrieval_or_memory_changes(): void
    {
        $this->providers();
        $conversation = $this->durationConversation();
        $before = $conversation->contexte;
        foreach (['En semaines.', 'En heures et en semaines.'] as $question) {
            Cache::flush();
            $response = $this->postJson('/api/mistral-raw/ask', ['question' => $question, 'conversation_id' => $conversation->reference])->assertOk()->assertJsonPath('should_escalate', false);
            $this->assertStringContainsString('Précisez les unités', $response->json('answer'));
            $this->assertSame($before, $conversation->fresh()->contexte);
        }
    }

    public function test_a_first_unsupported_conversion_does_not_claim_a_previous_fact(): void
    {
        $this->providers();
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'En semaines.'])->assertOk()->assertJsonPath('should_escalate', false);
        $this->assertStringContainsString('Précisez aussi le délai', $response->json('answer'));
        $this->assertStringNotContainsString('durée précédente', $response->json('answer'));
        $this->assertNull($response->json('conversation_id'));
    }

    public function test_format_negations_do_not_pollute_parameter_scope_or_truncate_paragraphs(): void
    {
        $this->providers(true);
        $first = $this->postJson('/api/mistral-raw/ask', ['question' => 'Sans exemple, liste uniquement les en-têtes obligatoires pour consulter les frais.'])->assertOk();
        $reference = $first->json('conversation_id');
        $this->assertStringContainsString('X-Secret', $first->json('answer'));
        $this->assertStringNotContainsString('amount', $first->json('answer'));
        $this->assertSame('headers', Conversation::where('reference', $reference)->firstOrFail()->contexte['active_parameter_scope']);
        $question = 'Sans code, présente uniquement les paramètres de requête obligatoires des frais.';
        foreach ([true, false] as $search) {
            Cache::flush();
            $this->providers($search);
            $answer = $this->postJson('/api/mistral-raw/ask', ['question' => $question, 'conversation_id' => $reference])->assertOk()->json('answer');
            foreach (['accountOperationCode', 'transactionType', 'amount', 'operatorCode'] as $field) {
                $this->assertStringContainsString($field, $answer);
            }
            $this->assertStringNotContainsString('X-Secret', $answer);
            $this->assertStringNotContainsString('code-url-fees', $answer);
        }
    }

    public function test_negative_labeled_step_keeps_the_cursor_and_explain_selects_the_last_step(): void
    {
        $this->providers();
        $conversation = Conversation::create(['reference' => 'conv_nineteenth_plan', 'statut' => 'active', 'contexte' => ['topic_question' => 'Plan Sandbox', 'last_question' => 'Plan Sandbox', 'plan_subject' => 'sandbox', 'plan_steps' => ['Un', 'Deux', 'Trois', 'Quatre', 'Cinq', 'Six'], 'plan_index' => 3, 'plan_sources' => []]]);
        $invalid = $this->postJson('/api/mistral-raw/ask', ['question' => 'Va à l’étape numéro −1 du plan Sandbox.', 'conversation_id' => $conversation->reference])->assertOk();
        $this->assertStringContainsString('entre 1 et 6', $invalid->json('answer'));
        $this->assertSame(3, $conversation->fresh()->contexte['plan_index']);
        foreach (['Peux-tu expliquer la dernière étape du plan Sandbox ?', 'Peux-tu détailler la dernière étape du plan Sandbox ?', 'S’il te plaît, pourrais-tu simplifier la dernière étape du plan Sandbox ?'] as $question) {
            Cache::flush();
            $this->postJson('/api/mistral-raw/ask', ['question' => $question, 'conversation_id' => $conversation->reference])->assertOk()->assertJsonPath('answer', 'Étape 6 : Six');
            $this->assertSame(5, $conversation->fresh()->contexte['plan_index']);
        }
    }

    public function test_avoid_status_never_overwrites_the_documentary_example(): void
    {
        $this->providers();
        $json = "Notification reçue\n```json\n{\"job_id\":\"A\",\"status\":\"READY\"}\n```";
        $conversation = Conversation::create(['reference' => 'conv_nineteenth_json', 'statut' => 'active', 'contexte' => ['topic_question' => 'Notification reporting', 'last_question' => 'Notification reporting', 'last_answer' => $json, 'payload_answer' => $json, 'payload_sources' => []]]);
        $answer = $this->postJson('/api/mistral-raw/ask', ['question' => 'Évite le statut READY. Montre cet exemple JSON uniquement avec un autre statut documenté.', 'conversation_id' => $conversation->reference])->assertOk()->json('answer');
        $this->assertStringContainsString('Aucun exemple JSON vérifié', $answer);
        $this->assertStringNotContainsString('```', $answer);
        Cache::flush();
        $this->postJson('/api/mistral-raw/ask', ['question' => 'Quel statut contient réellement cet exemple ?', 'conversation_id' => $conversation->reference])->assertOk()->assertSee('READY');
        $this->assertSame($json, $conversation->fresh()->contexte['payload_answer']);
    }
}
