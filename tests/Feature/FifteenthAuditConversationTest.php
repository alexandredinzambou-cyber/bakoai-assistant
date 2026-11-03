<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Services\Llm\OpenAiCompatibleLlmClient;
use App\Services\Rag\RetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class FifteenthAuditConversationTest extends TestCase
{
    use RefreshDatabase;

    private function providers(bool $search = true): void
    {
        $retrieval = Mockery::mock(RetrievalService::class);
        if ($search) {
            $retrieval->shouldReceive('search')->andReturn(collect([(object) ['chunk_id' => 950, 'document_titre' => 'Initier un paiement', 'section' => 'Contrat', 'lien_officiel' => 'https://docs.mypvit.pro/fr/api/payment', 'contenu' => "## Corps de la requête\n- amount (number, Obligatoire) — Montant de la transaction.\n- reference (string, Obligatoire) — Référence de la transaction.\n- agent (string) — Agent associé (optionnel).\n- product (string) — Produit associé (optionnel).\n- free_info (string) — Information libre (optionnel)."]]));
        } else {
            $retrieval->shouldNotReceive('search');
        }
        $this->instance(RetrievalService::class, $retrieval);
        $llm = Mockery::mock(OpenAiCompatibleLlmClient::class);
        $llm->shouldNotReceive('completeWithProvider');
        $this->instance(OpenAiCompatibleLlmClient::class, $llm);
    }

    public function test_complete_payment_groups_survive_cache_loss_and_repeated_presentations(): void
    {
        $this->providers();
        $first = $this->postJson('/api/mistral-raw/ask', ['question' => 'Quels paramètres sont obligatoires pour initier un paiement ?'])->assertOk();
        $reference = $first->json('conversation_id');
        $optional = $this->postJson('/api/mistral-raw/ask', ['question' => 'Et les paramètres optionnels pour ce paiement ?', 'conversation_id' => $reference])->assertOk();
        $this->assertStringContainsString('agent', $optional->json('answer'));
        $this->providers(false);
        foreach (['Présente les paramètres obligatoires et optionnels de ce paiement côte à côte dans un tableau.', 'Résume ensemble les deux groupes de paramètres en deux points : un pour les obligatoires, un pour les optionnels.', 'Présente les paramètres obligatoires et optionnels dans un tableau.'] as $question) {
            Cache::flush();
            $response = $this->postJson('/api/mistral-raw/ask', ['question' => $question, 'conversation_id' => $reference])->assertOk();
            foreach (['amount', 'reference', 'agent', 'product', 'free_info'] as $field) {
                $this->assertStringContainsString($field, $response->json('answer'));
            }
            $this->assertSame($reference, $response->json('conversation_id'));
        }
        $sets = Conversation::where('reference', $reference)->firstOrFail()->contexte['parameter_sets'];
        $this->assertStringNotContainsString('agent', $sets['required']['answer']);
        $this->assertStringNotContainsString('amount', $sets['optional']['answer']);
    }

    public function test_a_fresh_combined_request_retrieves_both_groups(): void
    {
        $this->providers();
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Présente les paramètres obligatoires et optionnels pour initier un paiement dans un tableau.'])->assertOk();
        foreach (['Obligatoires', 'Optionnels', 'amount', 'agent', 'free_info'] as $field) {
            $this->assertStringContainsString($field, $response->json('answer'));
        }
    }

    public function test_combined_groups_deduplicate_and_renumber_their_sources_after_reload(): void
    {
        $this->providers(false);
        $source = static fn ($id, $number) => ['number' => $number, 'chunk_id' => $id, 'document' => 'Initier un paiement', 'section' => 'Contrat', 'url' => 'https://docs.mypvit.pro/fr/api/payment'];
        $sets = [
            'required' => ['answer' => '- `amount` : Montant documenté [1].', 'sources' => [$source(960, 1)]],
            'optional' => ['answer' => '- `agent` : Agent optionnel [1].\n- `product` : Produit optionnel [2].', 'sources' => [$source(961, 1), $source(960, 2)]],
        ];
        $conversation = Conversation::create(['reference' => 'conv_fifteenth_sources', 'statut' => 'active', 'contexte' => ['topic_question' => 'Paramètres de paiement', 'last_question' => 'Paramètres optionnels de paiement', 'last_answer' => $sets['optional']['answer'], 'parameter_sets' => $sets, 'active_parameter_kind' => 'optional']]);
        Cache::flush();
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Présente les paramètres obligatoires et optionnels dans un tableau.', 'conversation_id' => $conversation->reference])->assertOk();
        $this->assertCount(2, $response->json('sources'));
        $this->assertSame([1, 2], array_column($response->json('sources'), 'number'));
        $this->assertSame([960, 961], array_column($response->json('sources'), 'chunk_id'));
        $this->assertStringContainsString('Montant documenté [1]', $response->json('answer'));
        $this->assertStringContainsString('Agent optionnel [2]', $response->json('answer'));
        $this->assertStringContainsString('Produit optionnel [1]', $response->json('answer'));
    }

    public function test_invalid_ordinal_keeps_the_cursor_and_polite_navigation_uses_it_after_reload(): void
    {
        $this->providers(false);
        $conversation = Conversation::create(['reference' => 'conv_fifteenth_plan', 'statut' => 'active', 'contexte' => ['topic_question' => 'Plan Sandbox', 'last_question' => 'Plan Sandbox', 'plan_subject' => 'sandbox', 'plan_steps' => ['Un', 'Deux', 'Trois'], 'plan_index' => 1, 'plan_sources' => []]]);
        $invalid = $this->postJson('/api/mistral-raw/ask', ['question' => 'Explique la treizième étape du plan Sandbox.', 'conversation_id' => $conversation->reference])->assertOk();
        $this->assertStringContainsString('entre 1 et 3', $invalid->json('answer'));
        $this->assertSame(1, $conversation->fresh()->contexte['plan_index']);
        Cache::flush();
        $next = $this->postJson('/api/mistral-raw/ask', ['question' => 'Peux-tu passer à l’étape suivante du plan Sandbox ?', 'conversation_id' => $conversation->reference])->assertOk();
        $this->assertSame('Étape 3 : Trois', $next->json('answer'));
    }

    public function test_sentence_negation_preserves_the_verified_json_after_reload(): void
    {
        $this->providers(false);
        $json = "Notification reçue\n```json\n{\"job_id\":\"J\",\"status\":\"READY\"}\n```";
        $conversation = Conversation::create(['reference' => 'conv_fifteenth_json', 'statut' => 'active', 'contexte' => ['topic_question' => 'Notification reporting', 'last_question' => 'Notification reporting', 'last_answer' => $json, 'payload_answer' => $json, 'payload_sources' => []]]);
        Cache::flush();
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Je ne veux pas de statut FAILED. Montre cet exemple JSON avec le statut READY.', 'conversation_id' => $conversation->reference])->assertOk();
        $this->assertStringContainsString('```json', $response->json('answer'));
        $this->assertStringContainsString('READY', $response->json('answer'));
        $this->assertStringNotContainsString('FAILED', $response->json('answer'));
    }

    public function test_download_does_not_invent_a_retention_reset_or_require_support(): void
    {
        $this->providers(false);
        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldReceive('search')->andReturn(collect([(object) ['chunk_id' => 951, 'document_titre' => 'Télécharger un export', 'section' => 'Disponibilité', 'contenu' => 'Le fichier reste disponible 7 jours après la demande.', 'lien_officiel' => 'https://docs.mypvit.pro/fr/api/report-download']]));
        $this->instance(RetrievalService::class, $retrieval);
        $conversation = Conversation::create(['reference' => 'conv_fifteenth_retention', 'statut' => 'active', 'contexte' => ['topic_question' => 'Durée du fichier d’export', 'last_question' => 'Durée du fichier d’export', 'last_answer' => '7 jours après la demande.']]);
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'La conservation repart-elle à chaque téléchargement du fichier ?', 'conversation_id' => $conversation->reference])->assertOk()->assertJsonPath('should_escalate', false);
        $this->assertStringContainsString('7 jours après la demande', $response->json('answer'));
        $this->assertStringContainsString('ne décrivent pas de remise à zéro', $response->json('answer'));
    }
}
