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

class CallbackStepConversationTest extends TestCase
{
    use RefreshDatabase;

    public static function otherSubjects(): array
    {
        return [['payment', 'Comment initier un paiement ?'], ['refund', 'Comment effectuer un remboursement ?']];
    }

    #[DataProvider('otherSubjects')]
    public function test_expansion_also_preserves_other_procedures(string $subject, string $topic): void
    {
        $source = (object) ['chunk_id' => 1503, 'document_titre' => 'Procédure documentée', 'section' => 'Traitement', 'lien_officiel' => 'https://docs.mypvit.pro/fr/api/operation', 'contenu' => 'Votre serveur transmet les données documentées. La réponse contient le statut final.'];
        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldReceive('search')->once()->withArgs(fn ($query) => str_contains($query, $topic) && str_contains($query, 'Étape 2'))->andReturn(collect([$source]));
        $this->instance(RetrievalService::class, $retrieval);
        $llm = Mockery::mock(OpenAiCompatibleLlmClient::class);
        $llm->shouldReceive('completeWithProvider')->once()->andReturn('Votre serveur transmet les données documentées. La réponse contient le statut final [SOURCE:1503].');
        $this->instance(OpenAiCompatibleLlmClient::class, $llm);
        $steps = ['Préparez les données.', 'Transmettez les données documentées.', 'Traitez la réponse.'];
        $conversation = Conversation::create(['reference' => 'conv_expansion_'.$subject, 'statut' => 'active', 'contexte' => [
            'topic_question' => $topic, 'last_question' => $topic, 'plan_subject' => $subject, 'plan_steps' => $steps, 'plan_sources' => [], 'plan_index' => 0,
        ]]);
        $this->postJson('/api/mistral-raw/ask', ['question' => 'Explique le 2', 'conversation_id' => $conversation->reference])->assertOk()->assertJsonPath('answer_type', 'step');
        Cache::flush();
        $this->postJson('/api/mistral-raw/ask', ['question' => 'plus en détail', 'conversation_id' => $conversation->reference])->assertOk()->assertJsonPath('answer_type', 'expansion')->assertJsonPath('should_escalate', false);
        $this->assertSame($steps, $conversation->fresh()->contexte['plan_steps']);
        $this->assertSame($subject, $conversation->fresh()->contexte['plan_subject']);
        Cache::flush();
        $this->postJson('/api/mistral-raw/ask', ['question' => 'Étape suivante', 'conversation_id' => $conversation->reference])->assertOk()->assertJsonPath('answer', 'Étape 3 : Traitez la réponse.');
    }

    public function test_expansion_keeps_the_selected_step_and_parent_plan_after_database_reload(): void
    {
        $source = (object) ['chunk_id' => 1501, 'document_titre' => 'Webhook de notification de statut', 'section' => 'Réception', 'lien_officiel' => 'https://docs.mypvit.pro/fr/webhooks/status', 'contenu' => 'PVit envoie une requête POST à votre URL publique avec le statut final SUCCESS ou FAILED. Le corps contient transactionId et amount.'];
        $report = (object) ['chunk_id' => 1502, 'document_titre' => 'Webhook de reporting', 'section' => 'Signature', 'lien_officiel' => 'https://docs.mypvit.pro/fr/webhooks/reports', 'contenu' => 'La signature utilise X-PVit-Signature-Timestamp.'];
        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldReceive('search')->twice()->withArgs(function ($query) {
            $this->assertStringContainsString('comment recevoir les callbacks', $query);
            $this->assertStringContainsString('Étape 3 : Recevoir la notification POST', $query);
            $this->assertStringNotContainsString('Ancien sujet reporting', $query);

            return true;
        })->andReturn(collect([$source, $report]));
        $this->instance(RetrievalService::class, $retrieval);
        $llm = Mockery::mock(OpenAiCompatibleLlmClient::class);
        $llm->shouldReceive('completeWithProvider')->twice()->withArgs(function ($provider, $prompt, $context, $question) {
            $this->assertStringContainsString('Ne repete pas simplement', $prompt);
            $this->assertStringContainsString('Étape 3 : Recevoir la notification POST', $question);
            $this->assertStringNotContainsString('X-PVit-Signature-Timestamp', $context);

            return true;
        })->andReturn("1. PVit envoie une requête POST à votre URL publique avec le statut final SUCCESS ou FAILED [SOURCE:1501].\n\n2. Le corps contient transactionId et amount [SOURCE:1501].");
        $this->instance(OpenAiCompatibleLlmClient::class, $llm);
        $steps = ['Configurer l’URL publique.', 'Accepter POST.', 'Recevoir la notification POST.', 'Renvoyer l’accusé de réception.', 'Traiter le statut final.'];
        $conversation = Conversation::create(['reference' => 'conv_callback_expansion', 'statut' => 'active', 'contexte' => [
            'topic_question' => 'comment recevoir les callbacks', 'last_question' => 'comment recevoir les callbacks',
            'plan_subject' => 'callback', 'plan_steps' => $steps, 'plan_sources' => [], 'plan_index' => 0,
            'recent_questions' => ['Ancien sujet reporting'],
        ]]);
        $reference = $conversation->reference;
        $this->postJson('/api/mistral-raw/ask', ['question' => 'expliques moi le 3', 'conversation_id' => $reference])->assertOk()->assertJsonPath('answer_type', 'step');
        foreach (['plus en détail', 'Développe cette étape'] as $question) {
            Cache::flush();
            $response = $this->postJson('/api/mistral-raw/ask', ['question' => $question, 'conversation_id' => $reference])->assertOk()->assertJsonPath('answer_type', 'expansion')->assertJsonPath('should_escalate', false);
            $this->assertStringContainsString('transactionId', $response->json('answer'));
            $memory = $conversation->fresh()->contexte;
            $this->assertSame($steps, $memory['plan_steps']);
            $this->assertSame(2, $memory['plan_index']);
            $this->assertTrue($memory['plan_selected']);
            $this->assertSame('callback', $memory['plan_subject']);
            $this->assertSame('comment recevoir les callbacks', $memory['topic_question']);
            $this->assertSame($steps, $memory['subjects']['callback']['plan_steps']);
        }
        Cache::flush();
        $this->postJson('/api/mistral-raw/ask', ['question' => 'Résume cette étape en deux points.', 'conversation_id' => $reference])->assertOk()->assertJsonPath('answer_type', 'summary');
        Cache::flush();
        $this->postJson('/api/mistral-raw/ask', ['question' => 'Étape suivante', 'conversation_id' => $reference])->assertOk()->assertJsonPath('answer', 'Étape 4 : Renvoyer l’accusé de réception.');
    }

    public function test_expansion_without_verified_context_requests_a_complete_question(): void
    {
        $this->noProviders();
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'plus en détail'])->assertOk();
        $this->assertStringContainsString('pas encore de réponse vérifiée', $response->json('answer'));
    }

    public function test_a_new_fact_on_the_same_topic_releases_the_previous_step_selection(): void
    {
        $source = (object) ['chunk_id' => 1504, 'document_titre' => 'Webhook de notification de statut', 'section' => 'Accusé de réception attendu de votre serveur', 'lien_officiel' => 'https://docs.mypvit.pro/fr/webhooks/status', 'contenu' => "## Accusé de réception attendu de votre serveur\nRépondez HTTP 200 avec un écho dynamique du transactionId et du code reçus.\n```json\n{\"transactionId\":\"A\",\"responseCode\":200}\n```"];
        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldReceive('search')->once()->andReturn(collect([$source]));
        $this->instance(RetrievalService::class, $retrieval);
        $llm = Mockery::mock(OpenAiCompatibleLlmClient::class);
        $llm->shouldNotReceive('completeWithProvider');
        $this->instance(OpenAiCompatibleLlmClient::class, $llm);
        $conversation = Conversation::create(['reference' => 'conv_callback_new_fact', 'statut' => 'active', 'contexte' => [
            'topic_question' => 'comment recevoir les callbacks', 'last_question' => 'expliques moi le 3',
            'last_answer' => 'Étape 3 : Recevoir la notification.', 'plan_selected' => true,
            'plan_subject' => 'callback', 'plan_steps' => ['Configurer.', 'POST.', 'Recevoir la notification.'], 'plan_index' => 2,
        ]]);
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Quel JSON pour accuser réception du callback de paiement ?', 'conversation_id' => $conversation->reference])->assertOk()->assertJsonPath('answer_type', 'fact');
        $memory = $conversation->fresh()->contexte;
        $this->assertFalse($memory['plan_selected'], json_encode([$memory, $response->json()], JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('responseCode', $memory['last_verified_answer']);
    }

    private function noProviders(): void
    {
        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldNotReceive('search');
        $this->instance(RetrievalService::class, $retrieval);
        $llm = Mockery::mock(OpenAiCompatibleLlmClient::class);
        $llm->shouldNotReceive('completeWithProvider');
        $this->instance(OpenAiCompatibleLlmClient::class, $llm);
    }

    public function test_number_without_step_word_selects_the_saved_callback_after_cache_reload(): void
    {
        $this->noProviders();
        $conversation = Conversation::create(['reference' => 'conv_callback_bare_reference', 'statut' => 'active', 'contexte' => [
            'topic_question' => 'comment recevoir les callbacks', 'last_question' => 'comment recevoir les callbacks',
            'plan_subject' => 'callback', 'plan_steps' => ['Configurez l’URL dans Urls et choisissez Callback.', 'Recevez les notifications POST.'],
            'plan_index' => 0, 'plan_sources' => [],
        ]]);
        Cache::flush();
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'expliques moi le 1 en détail', 'conversation_id' => $conversation->reference])->assertOk()->assertJsonPath('answer_type', 'step');
        $this->assertStringContainsString('Étape 1 : Configurez', $response->json('answer'));
        $this->assertStringNotContainsString('initier', $response->json('answer'));
        $memory = $conversation->fresh()->contexte;
        $this->assertSame('comment recevoir les callbacks', $memory['topic_question']);
        $this->assertSame('callback', $memory['plan_subject']);
        $this->assertCount(2, $memory['plan_steps']);
        Cache::flush();
        $this->postJson('/api/mistral-raw/ask', ['question' => 'Détaille le 2.', 'conversation_id' => $conversation->reference])->assertOk()->assertJsonPath('answer_type', 'step')->assertJsonPath('answer', 'Étape 2 : Recevez les notifications POST.');
        $beforeSummary = $conversation->fresh()->contexte;
        Cache::flush();
        $this->postJson('/api/mistral-raw/ask', ['question' => 'Résume cette étape en deux points.', 'conversation_id' => $conversation->reference])->assertOk()->assertJsonPath('answer_type', 'summary');
        $afterSummary = $conversation->fresh()->contexte;
        $this->assertSame($beforeSummary['plan_steps'], $afterSummary['plan_steps']);
        $this->assertSame($beforeSummary['plan_index'], $afterSummary['plan_index']);
        $this->assertSame($beforeSummary['topic_question'], $afterSummary['topic_question']);
    }

    public function test_generated_callback_procedure_is_saved_then_reused_without_another_model_call(): void
    {
        $draft = "1. **Configurer le callback** :\n   - Dans Urls, choisissez Callback pour enregistrer une URL publique [SOURCE:1500].\n\n2. **Recevoir la notification** :\n   - Acceptez les notifications POST de PVIT [SOURCE:1500].";
        $source = (object) ['chunk_id' => 1500, 'document_titre' => 'Recevoir les callbacks', 'section' => 'Configuration', 'contenu' => 'Dans Urls, choisissez Callback pour enregistrer une URL publique. Acceptez les notifications POST de PVIT.', 'lien_officiel' => 'https://docs.mypvit.pro/fr/tutoriels/callback'];
        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldReceive('search')->once()->andReturn(collect([$source]));
        $this->instance(RetrievalService::class, $retrieval);
        $llm = Mockery::mock(OpenAiCompatibleLlmClient::class);
        $llm->shouldReceive('completeWithProvider')->once()->andReturn($draft);
        $this->instance(OpenAiCompatibleLlmClient::class, $llm);
        $first = $this->postJson('/api/mistral-raw/ask', ['question' => 'comment recevoir les callbacks'])->assertOk()->assertJsonPath('should_escalate', false);
        $reference = $first->json('conversation_id');
        $conversation = Conversation::where('reference', $reference)->firstOrFail();
        $this->assertSame('callback', $conversation->contexte['plan_subject']);
        $this->assertArrayHasKey('callback', $conversation->contexte['subjects']);
        $this->assertCount(2, $conversation->contexte['plan_steps']);
        Cache::flush();
        $second = $this->postJson('/api/mistral-raw/ask', ['question' => 'expliques moi le 1 en détail', 'conversation_id' => $reference])->assertOk()->assertJsonPath('answer_type', 'step');
        $this->assertStringContainsString('Urls', $second->json('answer'));
        $this->assertSame('comment recevoir les callbacks', $conversation->fresh()->contexte['topic_question']);
    }

    public function test_a_number_without_a_verified_plan_never_starts_an_unrelated_generation(): void
    {
        $this->noProviders();
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'expliques moi le 1 en détail'])->assertOk();
        $this->assertStringContainsString('pas encore de plan vérifié', $response->json('answer'));
    }

    public function test_callback_snapshot_can_be_restored_from_a_different_active_subject(): void
    {
        $this->noProviders();
        $callback = ['topic_question' => 'Comment recevoir les callbacks ?', 'last_question' => 'Comment recevoir les callbacks ?', 'plan_subject' => 'callback', 'plan_steps' => ['Configurez Callback dans Urls.', 'Recevez POST.'], 'plan_sources' => []];
        $conversation = Conversation::create(['reference' => 'conv_callback_restore', 'statut' => 'active', 'contexte' => [
            'topic_question' => 'Initier un paiement', 'last_question' => 'Initier un paiement', 'plan_subject' => 'payment',
            'plan_steps' => ['Préparez amount.', 'Initiez le paiement.'], 'subjects' => ['callback' => $callback],
        ]]);
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Explique l’étape 1 des callbacks.', 'conversation_id' => $conversation->reference])->assertOk()->assertJsonPath('answer_type', 'step');
        $this->assertStringContainsString('Configurez Callback', $response->json('answer'));
        $this->assertSame('callback', $conversation->fresh()->contexte['plan_subject']);
    }
}
