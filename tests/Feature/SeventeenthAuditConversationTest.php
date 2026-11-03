<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Services\Llm\OpenAiCompatibleLlmClient;
use App\Services\Rag\RetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class SeventeenthAuditConversationTest extends TestCase
{
    use RefreshDatabase;

    private function providers(bool $search = true): void
    {
        $source = static fn ($id, $title, $content) => (object) ['chunk_id' => $id, 'document_titre' => $title, 'section' => 'Contrat', 'contenu' => $content, 'lien_officiel' => 'https://docs.mypvit.pro/fr/api/payment'];
        $chunks = [
            $source(990, 'Initier un paiement', "## Corps de la requête\n- amount (number, Obligatoire) — Montant documenté.\n- reference (string, Obligatoire) — Référence documentée.\n- agent (string) — Agent documenté (optionnel).\n## En-têtes\n- X-Secret (string, Obligatoire) — Clé secrète authentifiant l'opération."),
            $source(991, 'Obtenir les frais', "## Paramètres de requête\n- accountOperationCode (string, Obligatoire) — Compte documenté.\n- amount (number, Obligatoire) — Montant documenté.\n## En-têtes\n- X-Secret (string, Obligatoire) — Clé secrète authentifiant l'opération."),
            $source(992, 'Télécharger un export', 'Le fichier reste disponible 7 jours après la demande.'),
        ];
        $retrieval = Mockery::mock(RetrievalService::class);
        if ($search) {
            $retrieval->shouldReceive('search')->andReturn(collect($chunks));
        } else {
            $retrieval->shouldNotReceive('search');
        }
        $this->instance(RetrievalService::class, $retrieval);
        $llm = Mockery::mock(OpenAiCompatibleLlmClient::class);
        $llm->shouldNotReceive('completeWithProvider');
        $this->instance(OpenAiCompatibleLlmClient::class, $llm);
    }

    public function test_body_and_header_memories_survive_reload_and_subject_return(): void
    {
        $this->providers();
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Liste les paramètres obligatoires du corps pour initier un paiement.'])->assertOk();
        $reference = $response->json('conversation_id');
        $this->postJson('/api/mistral-raw/ask', ['question' => 'Résume uniquement les en-têtes obligatoires de ce paiement.', 'conversation_id' => $reference])->assertOk()->assertSee('X-Secret');
        $this->postJson('/api/mistral-raw/ask', ['question' => 'Et les paramètres optionnels du corps pour ce paiement ?', 'conversation_id' => $reference])->assertOk()->assertSee('agent');
        $this->postJson('/api/mistral-raw/ask', ['question' => 'Autre sujet : combien de jours le fichier d’export reste-t-il disponible ?', 'conversation_id' => $reference])->assertOk();
        Cache::flush();
        $this->providers(false);
        foreach (['Revenons au paiement : présente les paramètres obligatoires et optionnels du corps dans un tableau.', 'Résume les deux groupes de paramètres en une seule phrase, sans liste.'] as $question) {
            $answer = $this->postJson('/api/mistral-raw/ask', ['question' => $question, 'conversation_id' => $reference])->assertOk()->json('answer');
            foreach (['amount', 'reference', 'agent'] as $field) {
                $this->assertStringContainsString($field, $answer);
            }
            $this->assertStringNotContainsString('X-Secret', $answer);
            Cache::flush();
        }
        $sets = Conversation::where('reference', $reference)->firstOrFail()->contexte['parameter_sets'];
        $this->assertStringContainsString('amount', $sets['required:body']['answer']);
        $this->assertStringContainsString('X-Secret', $sets['required:headers']['answer']);
    }

    public function test_switching_fee_scope_retrieves_the_missing_contract_then_reuses_it(): void
    {
        $this->providers();
        $reference = $this->postJson('/api/mistral-raw/ask', ['question' => 'Liste les en-têtes obligatoires pour consulter les frais.'])->assertOk()->json('conversation_id');
        Cache::flush();
        $question = 'Résume uniquement les paramètres de requête obligatoires pour les frais en deux points.';
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => $question, 'conversation_id' => $reference])->assertOk();
        $this->assertStringContainsString('accountOperationCode', $response->json('answer'));
        $this->assertStringNotContainsString('X-Secret', $response->json('answer'));
        Cache::flush();
        $this->providers(false);
        $this->postJson('/api/mistral-raw/ask', ['question' => $question, 'conversation_id' => $reference])->assertOk()->assertSee('accountOperationCode');
    }

    public function test_unicode_negative_and_composed_politeness_keep_the_saved_plan(): void
    {
        $this->providers(false);
        $conversation = Conversation::create(['reference' => 'conv_seventeenth_plan', 'statut' => 'active', 'contexte' => ['topic_question' => 'Plan Sandbox', 'last_question' => 'Plan Sandbox', 'plan_subject' => 'sandbox', 'plan_steps' => ['Un', 'Deux', 'Trois'], 'plan_index' => 1, 'plan_sources' => []]]);
        $invalid = $this->postJson('/api/mistral-raw/ask', ['question' => 'Va à l’étape −1 du plan Sandbox.', 'conversation_id' => $conversation->reference])->assertOk();
        $this->assertStringContainsString('entre 1 et 3', $invalid->json('answer'));
        $this->assertSame(1, $conversation->fresh()->contexte['plan_index']);
        Cache::flush();
        $this->postJson('/api/mistral-raw/ask', ['question' => 'S’il te plaît, peux-tu me montrer l’étape précédente du plan Sandbox ?', 'conversation_id' => $conversation->reference])->assertOk()->assertJsonPath('answer', 'Étape 1 : Un');
    }

    public function test_bare_conversion_keeps_the_export_fact_and_start_event_after_reload(): void
    {
        $this->providers();
        $reference = $this->postJson('/api/mistral-raw/ask', ['question' => 'Autre sujet : combien de jours le fichier d’export reste-t-il disponible ?'])->assertOk()->json('conversation_id');
        Cache::flush();
        $this->providers(false);
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'En heures uniquement.', 'conversation_id' => $reference])->assertOk()->assertJsonPath('should_escalate', false);
        $this->assertStringContainsString('168 heures', $response->json('answer'));
        $this->assertStringNotContainsString('clé secrète', $response->json('answer'));
        $this->assertSame(['value' => 7, 'unit' => 'jour'], Conversation::where('reference', $reference)->firstOrFail()->contexte['duration_fact']);
        $this->assertStringContainsString('export', Conversation::where('reference', $reference)->firstOrFail()->contexte['topic_question']);
    }

    public function test_legacy_parameter_memory_with_unknown_scope_is_not_used_as_a_scoped_contract(): void
    {
        $this->providers();
        $conversation = Conversation::create(['reference' => 'conv_seventeenth_legacy', 'statut' => 'active', 'contexte' => ['topic_question' => 'Paiement', 'last_question' => 'Paramètres obligatoires du paiement', 'last_answer' => '`X-Secret` : Clé.', 'parameter_sets' => ['required' => ['answer' => '`X-Secret` : Clé.', 'sources' => []]]]]);
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Résume les paramètres obligatoires du corps du paiement en deux points.', 'conversation_id' => $conversation->reference])->assertOk();
        $this->assertStringContainsString('amount', $response->json('answer'));
        $this->assertStringNotContainsString('X-Secret', $response->json('answer'));
    }

    public function test_scoped_refund_fields_do_not_expand_into_a_full_procedure(): void
    {
        $this->providers(false);
        $base = ['document_titre' => 'Initier un remboursement', 'section' => 'Contrat', 'lien_officiel' => 'https://docs.mypvit.pro/fr/api/refund'];
        $chunks = [(object) ($base + ['chunk_id' => 993, 'contenu' => "## Corps de la requête\n- amount (number, Obligatoire) — Montant documenté.\n- transaction_type (string, Obligatoire) — GIVE_CHANGE.\nLe remboursement est traité de façon synchrone, aucun webhook n’est nécessaire."]), (object) ($base + ['chunk_id' => 994, 'contenu' => "## En-têtes\n- X-Secret (string, Obligatoire) — Authentification.\n```bash\ncurl -H 'X-Secret: example' --data 'GIVE_CHANGE'\n```"])];
        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldReceive('search')->andReturn(collect($chunks));
        $this->instance(RetrievalService::class, $retrieval);
        $answer = $this->postJson('/api/mistral-raw/ask', ['question' => 'Liste uniquement les paramètres obligatoires du corps pour initier un remboursement.'])->assertOk()->json('answer');
        $this->assertStringContainsString('amount', $answer);
        $this->assertStringContainsString('transaction_type', $answer);
        $this->assertStringNotContainsString('X-Secret', $answer);
        $this->assertStringNotContainsString('```', $answer);
    }

    public function test_bare_unit_without_a_duration_asks_for_clarification_and_preserves_memory(): void
    {
        $this->providers();
        $reference = $this->postJson('/api/mistral-raw/ask', ['question' => 'Liste les paramètres obligatoires du corps pour le paiement.'])->assertOk()->json('conversation_id');
        $before = Conversation::where('reference', $reference)->firstOrFail()->contexte;
        Cache::flush();
        $this->providers(false);
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'En heures uniquement.', 'conversation_id' => $reference])->assertOk()->assertJsonPath('should_escalate', false);
        $this->assertStringContainsString('durée unique vérifiée', $response->json('answer'));
        $this->assertSame($before, Conversation::where('reference', $reference)->firstOrFail()->contexte);
    }

    public function test_implicit_combined_scope_retrieves_a_missing_group_instead_of_mixing_locations(): void
    {
        $this->providers();
        $reference = $this->postJson('/api/mistral-raw/ask', ['question' => 'Liste les paramètres obligatoires pour le paiement.'])->assertOk()->json('conversation_id');
        $this->postJson('/api/mistral-raw/ask', ['question' => 'Et les paramètres optionnels du corps pour le paiement ?', 'conversation_id' => $reference])->assertOk();
        Cache::flush();
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Résume les deux groupes de paramètres en deux points, sans exemple.', 'conversation_id' => $reference])->assertOk();
        foreach (['amount', 'reference', 'agent'] as $field) {
            $this->assertStringContainsString($field, $response->json('answer'));
        }
        $this->assertStringNotContainsString('X-Secret', $response->json('answer'));
        $saved = Conversation::where('reference', $reference)->firstOrFail()->contexte;
        $this->assertSame('body', $saved['active_parameter_scope']);
        $this->assertArrayHasKey('both:body', $saved['parameter_sets']);
    }
}
