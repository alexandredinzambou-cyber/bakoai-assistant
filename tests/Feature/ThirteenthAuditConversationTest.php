<?php

namespace Tests\Feature;

use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\DocumentApi;
use App\Services\Assistant\ConversationEvidence;
use App\Services\Llm\OpenAiCompatibleLlmClient;
use App\Services\Rag\RetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ThirteenthAuditConversationTest extends TestCase
{
    use RefreshDatabase;

    public static function freshDurationQuestions(): array
    {
        return [['Pendant combien de jours le fichier d’export reste-t-il disponible ?', '7 jours après la demande'], ['Quelle est la durée de disponibilité du fichier d’export en heures ?', '168 heures']];
    }

    #[DataProvider('freshDurationQuestions')]
    public function test_fresh_duration_answers_keep_the_start_event(string $question, string $expected): void
    {
        $this->providers([(object) ['chunk_id' => 901, 'document_titre' => 'Télécharger un export', 'section' => 'Disponibilité', 'contenu' => 'Le fichier reste disponible 7 jours après la demande.', 'lien_officiel' => 'https://docs.mypvit.pro/fr/api/report-download']]);
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => $question])->assertOk();
        $this->assertStringContainsString('7 jours après la demande', $response->json('answer'));
        $this->assertStringContainsString($expected, $response->json('answer'));
    }

    private function providers(array $chunks, bool $search = true): void
    {
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

    private function feeSource(): object
    {
        return (object) ['chunk_id' => 900, 'document_titre' => 'Obtenir les frais', 'section' => 'Paramètres de requête', 'lien_officiel' => 'https://docs.mypvit.pro/fr/api/fees', 'contenu' => "## Paramètres de requête\n- accountOperationCode (Obligatoire) — Compte marchand.\n- transactionType (Obligatoire) — Type de transaction.\n- amount (Obligatoire) — Montant.\n- operatorCode (Obligatoire) — Opérateur.\n- trace (Optionnel) — Trace de diagnostic."];
    }

    public function test_required_and_optional_summaries_remain_separate_after_reload_and_topic_return(): void
    {
        $this->providers([$this->feeSource()]);
        $first = $this->postJson('/api/mistral-raw/ask', ['question' => 'Quels paramètres sont obligatoires pour consulter les frais ?'])->assertOk();
        $reference = $first->json('conversation_id');
        $optional = $this->postJson('/api/mistral-raw/ask', ['question' => 'Et les paramètres optionnels ?', 'conversation_id' => $reference])->assertOk();
        $this->assertStringContainsString('trace', $optional->json('answer'));
        foreach (['Résume les paramètres obligatoires de cet appel en deux points.', 'Présente ces paramètres en deux points.'] as $question) {
            Cache::flush();
            $response = $this->postJson('/api/mistral-raw/ask', ['question' => $question, 'conversation_id' => $reference])->assertOk();
            foreach (['accountOperationCode', 'transactionType', 'amount', 'operatorCode'] as $field) {
                $this->assertStringContainsString($field, $response->json('answer'));
            }
            $this->assertStringNotContainsString('trace', $response->json('answer'));
            $this->assertSame(2, preg_match_all('/^\d+\. /mu', $response->json('answer')));
        }
        $optional = $this->postJson('/api/mistral-raw/ask', ['question' => 'Résume les paramètres optionnels en deux points.', 'conversation_id' => $reference])->assertOk();
        $this->assertStringContainsString('trace', $optional->json('answer'));
        $this->assertStringNotContainsString('accountOperationCode', $optional->json('answer'));
        $saved = Conversation::where('reference', $reference)->firstOrFail();
        $memory = $saved->contexte;
        $memory['topic_question'] = 'Clé secrète';
        $memory['last_question'] = 'Clé secrète';
        $memory['last_answer'] = '3600 secondes.';
        $saved->update(['contexte' => $memory]);
        Cache::flush();
        $returned = $this->postJson('/api/mistral-raw/ask', ['question' => 'Revenons aux frais : résume les paramètres obligatoires en deux points.', 'conversation_id' => $reference])->assertOk();
        $this->assertStringContainsString('accountOperationCode', $returned->json('answer'));
        $this->assertStringNotContainsString('trace', $returned->json('answer'));
    }

    public function test_legacy_optional_memory_retrieves_required_fields_instead_of_reusing_it(): void
    {
        $this->providers([$this->feeSource()]);
        $legacy = ['topic_question' => 'Frais', 'last_question' => 'Quels paramètres optionnels pour les frais ?', 'last_answer' => '- `trace` : optionnel.', 'parameter_answer' => '- `trace` : optionnel.'];
        $conversation = Conversation::create(['reference' => 'conv_thirteenth_legacy', 'statut' => 'active', 'contexte' => ['topic_question' => 'Clé secrète', 'last_question' => 'Quelle durée pour la clé ?', 'last_answer' => '3600 secondes.', 'subjects' => ['fees' => $legacy]]]);
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Revenons aux frais : résume les paramètres obligatoires en deux points.', 'conversation_id' => $conversation->reference])->assertOk();
        $this->assertStringContainsString('accountOperationCode', $response->json('answer'));
        $this->assertStringNotContainsString('trace', $response->json('answer'));
    }

    public function test_saved_ordinal_navigation_does_not_search_or_consume_another_plan(): void
    {
        $this->providers([], false);
        $conversation = Conversation::create(['reference' => 'conv_thirteenth_plan', 'statut' => 'active', 'contexte' => ['topic_question' => 'Plan Sandbox', 'last_question' => 'Plan Sandbox', 'plan_subject' => 'sandbox', 'plan_steps' => ['Un', 'Deux', 'Trois', 'Écho dynamique'], 'plan_index' => 0, 'plan_sources' => []]]);
        $this->postJson('/api/mistral-raw/ask', ['question' => 'Explique simplement la quatrième étape du plan Sandbox.', 'conversation_id' => $conversation->reference])->assertOk()->assertJsonPath('answer', 'Étape 4 : Écho dynamique');
        $before = $conversation->fresh()->contexte;
        $different = $this->postJson('/api/mistral-raw/ask', ['question' => 'Passe à l’étape suivante de la procédure de remboursement.', 'conversation_id' => $conversation->reference])->assertOk();
        $this->assertStringContainsString('autre procédure', $different->json('answer'));
        $this->assertSame($before, $conversation->fresh()->contexte);
    }

    public function test_notification_examples_and_status_constraints_reuse_verified_json_without_a_model(): void
    {
        $this->providers([], false);
        $json = "Notification reçue\n```json\n{\"job_id\":\"J\",\"status\":\"SUCCESS\"}\n```";
        $conversation = Conversation::create(['reference' => 'conv_thirteenth_json', 'statut' => 'active', 'contexte' => ['topic_question' => 'Notification reporting', 'last_question' => 'Liste les champs de la notification de reporting', 'last_answer' => '`job_id`, `status`.', 'payload_answer' => $json, 'payload_sources' => []]]);
        $this->postJson('/api/mistral-raw/ask', ['question' => 'Montre un exemple JSON de cette notification.', 'conversation_id' => $conversation->reference])->assertOk()->assertSee('SUCCESS');
        Cache::flush();
        $different = $this->postJson('/api/mistral-raw/ask', ['question' => 'Montre cet exemple JSON avec le statut FAILED, sans inventer de valeurs.', 'conversation_id' => $conversation->reference])->assertOk();
        $this->assertStringContainsString('Aucun exemple JSON vérifié', $different->json('answer'));
        $this->assertStringNotContainsString('```', $different->json('answer'));
        $this->assertSame($json, $conversation->fresh()->contexte['payload_answer']);
        $status = $this->postJson('/api/mistral-raw/ask', ['question' => 'Quel statut contient réellement cet exemple ?', 'conversation_id' => $conversation->reference])->assertOk();
        $this->assertStringContainsString('SUCCESS', $status->json('answer'));
        $this->assertStringNotContainsString('FAILED', $status->json('answer'));
    }

    public function test_all_parameter_evidence_is_invalidated_when_an_older_required_source_changes(): void
    {
        $doc = DocumentApi::create(['titre' => 'Obtenir les frais', 'actif' => true, 'lien_officiel' => 'https://docs.mypvit.pro/fr/api/fees', 'link_verified_at' => now()]);
        $required = Chunk::create(['document_id' => $doc->id, 'contenu' => 'Champ requis initial.', 'position' => 0, 'status' => 'active']);
        $optional = Chunk::create(['document_id' => $doc->id, 'contenu' => "- amount (Obligatoire) — Montant.\n- trace (Optionnel) — Diagnostic.", 'position' => 1, 'status' => 'active']);
        $sources = [['chunk_id' => $required->id]];
        $conversation = Conversation::create(['reference' => 'conv_thirteenth_evidence', 'statut' => 'active', 'contexte' => ['topic_question' => 'Frais', 'last_question' => 'Quels paramètres obligatoires pour les frais ?', 'last_answer' => '- `amount` : Montant.', 'parameter_sets' => ['required' => ['answer' => '- `amount` : Montant.', 'sources' => $sources]], 'evidence_stamp' => (new ConversationEvidence)->capture($sources)]]);
        $this->providers([(object) ['chunk_id' => $optional->id, 'document_titre' => $doc->titre, 'section' => 'Paramètres', 'contenu' => $optional->contenu, 'lien_officiel' => $doc->lien_officiel]]);
        $this->postJson('/api/mistral-raw/ask', ['question' => 'Et les paramètres optionnels ?', 'conversation_id' => $conversation->reference])->assertOk();
        $this->assertContains($required->id, $conversation->fresh()->contexte['evidence_stamp']['ids']);
        $required->update(['contenu' => 'Nouvelle définition.']);
        Cache::flush();
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Résume les paramètres obligatoires en deux points.', 'conversation_id' => $conversation->reference])->assertOk();
        $this->assertStringContainsString('documentation utilisée dans cette conversation a changé', $response->json('answer'));
        $this->assertSame([], $conversation->fresh()->contexte['parameter_sets']);
    }
}
