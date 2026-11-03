<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Services\Llm\OpenAiCompatibleLlmClient;
use App\Services\Rag\RetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class CallbackStepConversationTest extends TestCase
{
    use RefreshDatabase;

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
