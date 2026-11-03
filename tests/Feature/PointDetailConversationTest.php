<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Services\Assistant\ConversationPlan;
use App\Services\Llm\OpenAiCompatibleLlmClient;
use App\Services\Rag\RetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PointDetailConversationTest extends TestCase
{
    use RefreshDatabase;

    public static function retryOutcomes(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('retryOutcomes')]
    public function test_an_unrequested_code_draft_gets_one_checked_retry_without_losing_the_plan(bool $recovered): void
    {
        $source = (object) ['chunk_id' => 1601, 'document_titre' => 'Webhook de notification de statut', 'section' => 'Réception', 'lien_officiel' => 'https://docs.mypvit.pro/fr/webhooks/status', 'contenu' => 'Votre serveur accepte POST et reçoit la notification avec le statut final SUCCESS ou FAILED.'];
        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldReceive('search')->once()->andReturn(collect([$source]));
        $this->instance(RetrievalService::class, $retrieval);
        $bad = "```php\n<?php function callback() { return 200; }\n```";
        $llm = Mockery::mock(OpenAiCompatibleLlmClient::class);
        $calls = 0;
        $llm->shouldReceive('completeWithProvider')->twice()->withArgs(function ($provider, $prompt, $context, $question) use (&$calls) {
            $this->assertStringContainsString('Étape 3 : Recevoir la notification', $question);
            $this->assertStringContainsString('Ne repete pas simplement', $prompt);
            if (++$calls === 2) {
                $this->assertStringContainsString('Le brouillon contenait du code applicatif', $prompt);
            }

            return true;
        })->andReturn($bad, $recovered ? 'Votre serveur accepte POST et reçoit la notification avec le statut final SUCCESS ou FAILED [SOURCE:1601].' : $bad);
        $this->instance(OpenAiCompatibleLlmClient::class, $llm);
        $steps = ['Configurer.', 'Vérifier l’URL.', 'Recevoir la notification.'];
        $conversation = Conversation::create(['reference' => 'conv_detail_retry_'.(int) $recovered, 'statut' => 'active', 'contexte' => [
            'topic_question' => 'comment recevoir les callbacks ?', 'last_question' => 'comment recevoir les callbacks ?', 'plan_steps' => $steps, 'plan_subject' => 'callback', 'plan_index' => 0,
        ]]);
        $before = $conversation->contexte;
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'expliques moi le point 3 en détail', 'conversation_id' => $conversation->reference])->assertOk()->assertJsonPath('should_escalate', ! $recovered);
        $this->assertStringNotContainsString('```php', $response->json('answer'));
        if ($recovered) {
            $response->assertJsonPath('answer_type', 'expansion');
            $this->assertSame($steps, $conversation->fresh()->contexte['plan_steps']);
            $this->assertSame(2, $conversation->fresh()->contexte['plan_index']);
        } else {
            $this->assertSame($before, $conversation->fresh()->contexte);
        }
    }

    private function procedure(): string
    {
        return "1. Configurez l’URL publique [SOURCE:1600].\n\n2. Vérifiez la validité de l’URL : elle doit être accessible depuis Internet et accepter POST, sans localhost [SOURCE:1600].\n\n3. Traitez le callback sur votre serveur [SOURCE:1600].\nRépondez HTTP 200 avec un écho dynamique des champs reçus [SOURCE:1600].\nExemple de réponse attendue :\n```json\n{\"transactionId\":\"PAY23102400001\",\"responseCode\":200}\n```\nNe codez pas en dur les valeurs [SOURCE:1600].\n\n4. Traitez le statut SUCCESS ou FAILED [SOURCE:1600].\n\n5. Validez les tests Sandbox [SOURCE:1600].";
    }

    public function test_exact_reported_exchange_expands_then_selects_point_two_after_reload(): void
    {
        $source = (object) ['chunk_id' => 1600, 'document_titre' => 'Webhook de notification de statut', 'section' => 'Réception', 'lien_officiel' => 'https://docs.mypvit.pro/fr/webhooks/status', 'contenu' => "Configurez l’URL publique. Elle doit être accessible depuis Internet et accepter POST, sans localhost. Traitez le callback sur votre serveur. Répondez HTTP 200 avec un écho dynamique des champs reçus. Ne codez pas en dur les valeurs. Traitez le statut SUCCESS ou FAILED. Validez les tests Sandbox.\n```json\n{\"transactionId\":\"PAY23102400001\",\"responseCode\":200}\n```"];
        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldReceive('search')->twice()->andReturn(collect([$source]));
        $this->instance(RetrievalService::class, $retrieval);
        $llm = Mockery::mock(OpenAiCompatibleLlmClient::class);
        $calls = 0;
        $llm->shouldReceive('completeWithProvider')->twice()->withArgs(function ($provider, $prompt, $context, $question) use (&$calls) {
            if (++$calls === 2) {
                $this->assertStringContainsString('Étape 3 :', $question);
                $this->assertStringContainsString('Traitez le callback', $question);
                $this->assertStringContainsString('Ne repete pas simplement', $prompt);
            }

            return true;
        })->andReturn($this->procedure(), "Votre serveur accepte POST. Répondez HTTP 200 avec un écho dynamique des champs reçus. Ne codez pas en dur les valeurs [SOURCE:1600].\n\n```json\n{\"transactionId\":\"PAY23102400001\",\"responseCode\":200}\n```");
        $this->instance(OpenAiCompatibleLlmClient::class, $llm);
        $first = $this->postJson('/api/mistral-raw/ask', ['question' => 'comment recevoir les callbacks ?'])->assertOk()->assertJsonPath('should_escalate', false);
        $reference = $first->json('conversation_id');
        $conversation = Conversation::where('reference', $reference)->firstOrFail();
        $parent = $conversation->contexte['plan_steps'];
        $this->assertStringContainsString('```json', $parent[2]);
        Cache::flush();
        $detail = $this->postJson('/api/mistral-raw/ask', ['question' => 'expliques moi le point 3 en détail', 'conversation_id' => $reference])->assertOk()->assertJsonPath('answer_type', 'expansion')->assertJsonPath('should_escalate', false);
        $this->assertStringContainsString('responseCode', $detail->json('answer'));
        $this->assertSame(2, $conversation->fresh()->contexte['plan_index']);
        Cache::flush();
        $second = $this->postJson('/api/mistral-raw/ask', ['question' => 'et le point 2', 'conversation_id' => $reference])->assertOk()->assertJsonPath('answer_type', 'step');
        $this->assertStringStartsWith('Étape 2 :', $second->json('answer'));
        $this->assertStringContainsString('Internet', $second->json('answer'));
        $this->assertStringNotContainsString('PENDING', $second->json('answer'));
        $this->assertSame($parent, $conversation->fresh()->contexte['plan_steps']);
        Cache::flush();
        $third = $this->postJson('/api/mistral-raw/ask', ['question' => 'le point 3', 'conversation_id' => $reference])->assertOk()->assertJsonPath('answer_type', 'step');
        $this->assertStringContainsString('Exemple de réponse attendue :', $third->json('answer'));
        $this->assertStringContainsString('```json', $third->json('answer'));
        $this->assertStringContainsString('responseCode', $third->json('answer'));
        $this->assertSame($parent, $conversation->fresh()->contexte['subjects']['callback']['plan_steps']);
        Cache::flush();
        $withoutCode = $this->postJson('/api/mistral-raw/ask', ['question' => 'Affiche l’étape 3 sans exemple JSON.', 'conversation_id' => $reference])->assertOk()->assertJsonPath('answer_type', 'step');
        $this->assertStringNotContainsString('```', $withoutCode->json('answer'));
        $this->assertStringNotContainsString('Exemple de réponse attendue', $withoutCode->json('answer'));
        $this->assertStringContainsString('```json', $conversation->fresh()->contexte['plan_steps'][2]);
    }

    public function test_an_old_plan_recovers_its_original_json_without_another_model_call(): void
    {
        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldNotReceive('search');
        $this->instance(RetrievalService::class, $retrieval);
        $llm = Mockery::mock(OpenAiCompatibleLlmClient::class);
        $llm->shouldNotReceive('completeWithProvider');
        $this->instance(OpenAiCompatibleLlmClient::class, $llm);
        $answer = str_replace('[SOURCE:1600]', '[1]', $this->procedure());
        $steps = (new ConversationPlan)->extract($answer);
        $steps[2] = preg_replace('/```.*?```/su', '', $steps[2]);
        $conversation = Conversation::create(['reference' => 'conv_legacy_point_json', 'statut' => 'active', 'contexte' => [
            'topic_question' => 'comment recevoir les callbacks ?', 'last_question' => 'comment recevoir les callbacks ?', 'plan_steps' => $steps, 'plan_subject' => 'callback', 'plan_index' => 0,
            'payload_answer' => $answer, 'payload_sources' => [], 'plan_sources' => [],
        ]]);
        Cache::flush();
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'et le point 3', 'conversation_id' => $conversation->reference])->assertOk()->assertJsonPath('answer_type', 'step');
        $this->assertStringContainsString('```json', $response->json('answer'));
        $this->assertStringContainsString('responseCode', $conversation->fresh()->contexte['plan_steps'][2]);
    }
}
