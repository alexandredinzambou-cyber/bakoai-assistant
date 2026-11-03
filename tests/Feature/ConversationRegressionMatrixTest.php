<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Services\Assistant\ConversationMemory;
use App\Services\Llm\OpenAiCompatibleLlmClient;
use App\Services\Rag\RetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConversationRegressionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function noProviders(): void
    {
        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldNotReceive('search');
        $this->instance(RetrievalService::class, $retrieval);
        $this->noModel();
    }

    private function noModel(): void
    {
        $model = Mockery::mock(OpenAiCompatibleLlmClient::class);
        $model->shouldNotReceive('completeWithProvider');
        $model->shouldNotReceive('completeWithImage');
        $this->instance(OpenAiCompatibleLlmClient::class, $model);
    }

    public function test_numbered_navigation_bounds_and_database_reload_preserve_the_plan(): void
    {
        $steps = ['Identifiants de test.', 'Configurez le callback.', 'Simulez un succès.', 'Accusez réception.', 'Simulez un échec.', 'Validez KYC et Check Status.'];
        $conversation = Conversation::create(['reference' => 'conv_matrix_plan', 'statut' => 'active', 'contexte' => [
            'topic_question' => 'Tester un paiement Sandbox', 'last_question' => 'Donne le plan Sandbox', 'last_answer' => 'Plan vérifié.',
            'plan_steps' => $steps, 'plan_index' => 1, 'plan_sources' => [],
        ]]);
        $this->noProviders();
        foreach ([['Va directement à l’étape 3.', 2], ['Reviens à l’étape précédente.', 1], ['Affiche l’étape numéro 999.', 1], ['Va à l’étape 0.', 1], ['Va à l’étape -1.', 1], ['Donne-moi la dernière étape.', 5], ['Et ensuite ?', 6], ['Reviens à l’étape précédente.', 5]] as [$question,$cursor]) {
            Cache::flush();
            $response = $this->postJson('/api/mistral-raw/ask', ['question' => $question, 'conversation_id' => $conversation->reference])->assertOk()->assertJsonPath('should_escalate', false);
            $this->assertSame($cursor, $conversation->fresh()->contexte['plan_index']);
            $this->assertSame($steps, $conversation->fresh()->contexte['plan_steps']);
            $this->assertStringNotContainsString('production', $response->json('answer'));
        }
    }

    public static function parameterSummaries(): array
    {
        return [
            ['Revenons aux frais : résume les paramètres obligatoires en trois points, sans nouvel exemple.'],
            ['Résume les paramètres de frais en trois points, sans exemple JSON.'],
            ['Pour les frais, résume ces paramètres en trois points.'],
        ];
    }

    #[DataProvider('parameterSummaries')]
    public function test_topic_restore_uses_saved_parameters_instead_of_an_example(string $question): void
    {
        $parameters = "- `accountOperationCode` identifie le compte marchand [1].\n- `transactionType` (type, ex. PAYMENT)\n- `amount` (montant de la transaction)\n- `operatorCode` (opérateur, ex. AIRTEL_MONEY)";
        $service = new ConversationMemory;
        $fees = $service->retain([], ['topic_question' => 'API de frais', 'last_question' => 'Quels champs pour les frais ?', 'last_answer' => $parameters, 'last_sources' => [], 'parameter_answer' => $parameters, 'parameter_sources' => []], true, false);
        $memory = $service->retain($fees, ['topic_question' => 'Clé secrète', 'last_question' => 'Quelle durée pour la clé ?', 'last_answer' => '3600 secondes [1].', 'last_sources' => []], true, false);
        $conversation = Conversation::create(['reference' => 'conv_matrix_parameters', 'statut' => 'active', 'contexte' => $memory]);
        Cache::flush();
        $this->noProviders();
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => $question, 'conversation_id' => $conversation->reference])->assertOk();
        foreach (['accountOperationCode', 'transactionType', 'amount', 'operatorCode', 'PAYMENT', 'AIRTEL_MONEY'] as $field) {
            $this->assertStringContainsString($field, $response->json('answer'));
        }
        $this->assertSame(3, preg_match_all('/^\d+\. /m', $response->json('answer')));
        $this->assertStringNotContainsString('```', $response->json('answer'));
        $this->assertSame('API de frais', $conversation->fresh()->contexte['topic_question']);
    }

    public function test_json_fields_survive_an_intermediate_presentation_and_database_reload(): void
    {
        $json = "Répondez avec un écho dynamique [1].\n```json\n{\"transactionId\":\"PAY123\",\"responseCode\":200}\n```";
        $conversation = Conversation::create(['reference' => 'conv_matrix_fields', 'statut' => 'active', 'contexte' => [
            'topic_question' => 'Accusé de réception du callback', 'last_question' => 'Quel JSON pour le callback ?',
            'last_answer' => 'Une réponse JSON est attendue [1].', 'last_verified_answer' => $json, 'payload_answer' => $json, 'payload_sources' => [['chunk_id' => 647]],
        ]]);
        Cache::flush();
        $this->noProviders();
        $this->postJson('/api/mistral-raw/ask', ['question' => 'Sans exemple JSON, indique seulement les noms des champs à renvoyer.', 'conversation_id' => $conversation->reference])->assertOk()->assertJsonPath('answer', '`transactionId`, `responseCode`. [1]');
        Cache::flush();
        $this->postJson('/api/mistral-raw/ask', ['question' => 'Juste les noms des champs, merci.', 'conversation_id' => $conversation->reference])->assertOk()->assertJsonPath('answer', '`transactionId`, `responseCode`. [1]');
        $this->assertSame($json, $conversation->fresh()->contexte['payload_answer']);
    }

    public function test_a_new_chat_does_not_invent_a_plan_for_an_ambiguous_step(): void
    {
        $this->noProviders();
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Va directement à l’étape 3.'])->assertOk();
        $this->assertStringContainsString('pas encore de plan vérifié', $response->json('answer'));
        $this->assertNull($response->json('conversation_id'));
        $this->assertDatabaseCount('conversations', 0);
    }

    public static function unavailableContexts(): array
    {
        return [['offline', 503], ['empty', 422], ['undocumented', 200]];
    }

    #[DataProvider('unavailableContexts')]
    public function test_support_decline_applies_to_static_errors_and_refusals(string $mode, int $http): void
    {
        $retrieval = Mockery::mock(RetrievalService::class);
        if ($mode === 'offline') {
            $retrieval->shouldReceive('search')->once()->andThrow(new \RuntimeException('offline'));
        } else {
            $retrieval->shouldReceive('search')->once()->andReturn($mode === 'empty' ? collect() : collect([(object) ['chunk_id' => 1, 'contenu' => 'La clé reste temporaire.', 'document_titre' => 'Clé secrète', 'section' => 'Clé', 'lien_officiel' => 'https://docs.mypvit.pro/fr/secret']]));
        }
        $this->instance(RetrievalService::class, $retrieval);
        $this->noModel();
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Aucun ticket de support : que signifie HTTP 429 pour cet appel ?'])->assertStatus($http)->assertJsonPath('should_escalate', false);
        $text = $response->json('answer') ?? $response->json('error');
        $this->assertStringNotContainsString('ticket', $text);
        $this->assertStringNotContainsString('de support', $text);
        $this->assertDatabaseCount('tickets', 0);
    }
}
