<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Services\Llm\OpenAiCompatibleLlmClient;
use App\Services\Rag\RetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TwentyFirstAuditConversationTest extends TestCase
{
    use RefreshDatabase;

    private function providers(bool $search = true): void
    {
        $retrieval = Mockery::mock(RetrievalService::class);
        if ($search) {
            $body = "## Corps de la requête\n- amount (number, Obligatoire) — Montant documenté.\n- reference (string, Obligatoire) — Référence documentée.\n- agent (string) — Agent documenté (optionnel).\n- product (string) — Produit documenté (optionnel).\n- free_info (string) — Information libre (optionnel).";
            $source = static fn ($id, $title, $text) => (object) ['chunk_id' => $id, 'document_titre' => $title, 'section' => 'Contrat', 'contenu' => $text, 'lien_officiel' => 'https://docs.mypvit.pro/fr/api/payment'];
            $retrieval->shouldReceive('search')->andReturn(collect([$source(1300, 'Initier un paiement', $body), $source(1301, 'Initier un remboursement', $body), $source(1302, 'Obtenir les frais', "## En-têtes\n- X-Secret (string, Obligatoire) — Authentification documentée.\n## Paramètres de requête\n- amount (number, Obligatoire) — Montant documenté.\n- operatorCode (string, Obligatoire) — Opérateur documenté.")]));
        } else {
            $retrieval->shouldNotReceive('search');
        }
        $this->instance(RetrievalService::class, $retrieval);
        $llm = Mockery::mock(OpenAiCompatibleLlmClient::class);
        $llm->shouldNotReceive('completeWithProvider');
        $this->instance(OpenAiCompatibleLlmClient::class, $llm);
    }

    public function test_negative_kind_selection_is_correct_through_storage_reload_and_summary(): void
    {
        foreach (['paiement', 'remboursement'] as $subject) {
            $this->providers();
            $first = $this->postJson('/api/mistral-raw/ask', ['question' => 'Liste les paramètres optionnels du corps pour le '.$subject.', pas les obligatoires.'])->assertOk();
            $reference = $first->json('conversation_id');
            $this->assertStringContainsString('agent', $first->json('answer'));
            $this->assertStringNotContainsString('amount', $first->json('answer'));
            Cache::flush();
            $this->providers(false);
            $answer = $this->postJson('/api/mistral-raw/ask', ['question' => 'Résume les paramètres facultatifs du corps en deux points.', 'conversation_id' => $reference])->assertOk()->json('answer');
            foreach (['agent', 'product', 'free_info'] as $field) {
                $this->assertStringContainsString($field, $answer);
            }
            $this->assertStringNotContainsString('amount', $answer);
            $set = Conversation::where('reference', $reference)->firstOrFail()->contexte['parameter_sets']['optional:body'];
            $this->assertSame('optional', $set['contract_kind']);
            $this->assertStringNotContainsString('amount', $set['answer']);
        }
    }

    public static function repairedQuestions(): array
    {
        return [['Résume les paramètres facultatifs du corps du remboursement en deux points.'], ['Résume ces paramètres en deux points.']];
    }

    #[DataProvider('repairedQuestions')]
    public function test_preexisting_bad_classification_is_repaired_and_retrieved_again(string $question): void
    {
        $this->providers();
        $bad = ['answer' => "Corps de la requête — champs obligatoires :\n- `amount` : Montant documenté.", 'sources' => [], 'scope' => 'body'];
        $conversation = Conversation::create(['reference' => 'conv_twentyfirst_bad', 'statut' => 'active', 'contexte' => ['topic_question' => 'Paramètres du remboursement', 'last_question' => 'Paramètres facultatifs du corps du remboursement', 'last_answer' => $bad['answer'], 'parameter_answer' => $bad['answer'], 'active_parameter_kind' => 'optional', 'active_parameter_scope' => 'body', 'parameter_sets' => ['optional' => $bad, 'optional:body' => $bad], 'subjects' => ['secret' => ['topic_question' => 'Clé secrète', 'duration_fact' => ['value' => 3600, 'unit' => 'seconde']]]]]);
        Cache::flush();
        $answer = $this->postJson('/api/mistral-raw/ask', ['question' => $question, 'conversation_id' => $conversation->reference])->assertOk()->json('answer');
        $this->assertStringContainsString('agent', $answer);
        $this->assertStringNotContainsString('amount', $answer);
        $fixed = $conversation->fresh()->contexte;
        $this->assertSame('optional', $fixed['parameter_sets']['optional:body']['contract_kind']);
        $this->assertFalse($fixed['parameter_memory_invalidated']);
        $this->assertSame(3600, $fixed['subjects']['secret']['duration_fact']['value']);
    }

    public function test_inactive_bad_snapshot_is_repaired_without_losing_the_current_duration(): void
    {
        $this->providers(false);
        $bad = ['answer' => "Corps — champs obligatoires :\n- `amount` : Montant documenté.", 'sources' => []];
        $conversation = Conversation::create(['reference' => 'conv_twentyfirst_inactive', 'statut' => 'active', 'contexte' => ['topic_question' => 'Disponibilité du fichier d’export', 'last_question' => 'Disponibilité du fichier d’export', 'duration_fact' => ['value' => 7, 'unit' => 'jour'], 'subjects' => ['refund' => ['topic_question' => 'Remboursement', 'parameter_sets' => ['optional:body' => $bad]]]]]);
        $this->postJson('/api/mistral-raw/ask', ['question' => 'En heures.', 'conversation_id' => $conversation->reference])->assertOk()->assertSee('168 heures');
        $saved = $conversation->fresh()->contexte;
        $this->assertSame(['value' => 7, 'unit' => 'jour'], $saved['duration_fact']);
        $this->assertSame([], $saved['subjects']['refund']['parameter_sets']);
        $this->assertTrue($saved['subjects']['refund']['parameter_memory_invalidated']);
    }

    public function test_courtesy_and_explicit_unsupported_units_preserve_the_fact_without_a_model(): void
    {
        $this->providers(false);
        $conversation = Conversation::create(['reference' => 'conv_twentyfirst_units', 'statut' => 'active', 'contexte' => ['topic_question' => 'Disponibilité de l’export', 'last_question' => 'Disponibilité de l’export', 'duration_fact' => ['value' => 7, 'unit' => 'jour']]]);
        $answer = $this->postJson('/api/mistral-raw/ask', ['question' => 'En heures et en minutes, s’il te plaît.', 'conversation_id' => $conversation->reference])->assertOk()->json('answer');
        $this->assertStringContainsString('168 heures', $answer);
        $this->assertStringContainsString('10080 minutes', $answer);
        $before = $conversation->fresh()->contexte;
        Cache::flush();
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Convertis cette durée en heures et en semaines.', 'conversation_id' => $conversation->reference])->assertOk()->assertJsonPath('should_escalate', false);
        $this->assertStringContainsString('Précisez les unités', $response->json('answer'));
        $this->assertSame($before, $conversation->fresh()->contexte);
    }

    public function test_query_article_changes_scope_instead_of_reusing_headers(): void
    {
        $this->providers();
        $reference = $this->postJson('/api/mistral-raw/ask', ['question' => 'Liste uniquement les en-têtes obligatoires des frais.'])->assertOk()->json('conversation_id');
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Résume uniquement les paramètres de la requête obligatoires des frais.', 'conversation_id' => $reference])->assertOk();
        $this->assertStringContainsString('operatorCode', $response->json('answer'));
        $this->assertStringNotContainsString('X-Secret', $response->json('answer'));
        $this->assertSame('query', Conversation::where('reference', $reference)->firstOrFail()->contexte['active_parameter_scope']);
    }

    public function test_spoken_negative_keeps_the_saved_cursor(): void
    {
        $this->providers(false);
        $conversation = Conversation::create(['reference' => 'conv_twentyfirst_plan', 'statut' => 'active', 'contexte' => ['topic_question' => 'Plan Sandbox', 'last_question' => 'Plan Sandbox', 'plan_subject' => 'sandbox', 'plan_steps' => ['Un', 'Deux', 'Trois', 'Quatre'], 'plan_index' => 3, 'plan_sources' => []]]);
        $answer = $this->postJson('/api/mistral-raw/ask', ['question' => 'Va à l’étape numéro moins un du plan Sandbox.', 'conversation_id' => $conversation->reference])->assertOk()->json('answer');
        $this->assertStringContainsString('entre 1 et 4', $answer);
        $this->assertSame(3, $conversation->fresh()->contexte['plan_index']);
    }

    public function test_except_status_does_not_replace_the_original_payload(): void
    {
        $this->providers(false);
        $json = "Notification reçue\n```json\n{\"job_id\":\"A\",\"status\":\"READY\"}\n```";
        $conversation = Conversation::create(['reference' => 'conv_twentyfirst_json', 'statut' => 'active', 'contexte' => ['topic_question' => 'Notification reporting', 'last_question' => 'Notification reporting', 'last_answer' => $json, 'payload_answer' => $json, 'payload_sources' => []]]);
        $answer = $this->postJson('/api/mistral-raw/ask', ['question' => 'Tout sauf le statut READY : montre cet exemple JSON.', 'conversation_id' => $conversation->reference])->assertOk()->json('answer');
        $this->assertStringNotContainsString('```', $answer);
        Cache::flush();
        $this->postJson('/api/mistral-raw/ask', ['question' => 'Quel statut contient réellement cet exemple ?', 'conversation_id' => $conversation->reference])->assertOk()->assertSee('READY');
        $this->assertSame($json, $conversation->fresh()->contexte['payload_answer']);
    }
}
