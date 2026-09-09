<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\DeveloppeurPartenaire;
use App\Models\Question;
use App\Services\Assistant\AssistantService;
use App\Services\Assistant\CitationGuard;
use App\Services\Assistant\ResponseGuard;
use App\Services\Llm\LlmClientInterface;
use App\Services\Rag\PaymentScopeResolver;
use App\Services\Rag\RetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AssistantConversationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_conversation_uses_an_opaque_reference_and_is_reused_safely(): void
    {
        $service = $this->service();

        $first = $service->ask(
            'Bonjour',
            filters: ['conversation_id' => 'caller-selected-reference'],
        );

        $this->assertMatchesRegularExpression('/\Aconv_[a-z0-9]{40}\z/', $first['conversation_id']);
        $this->assertNotSame('caller-selected-reference', $first['conversation_id']);

        $conversation = Conversation::query()
            ->where('reference', $first['conversation_id'])
            ->whereNull('developpeur_id')
            ->firstOrFail();
        $this->assertSame($conversation->id, Question::findOrFail($first['question_id'])->conversation_id);

        $second = $service->ask(
            'Bonjour',
            filters: ['conversation_id' => $first['conversation_id']],
        );

        $this->assertSame($first['conversation_id'], $second['conversation_id']);
        $this->assertSame($conversation->id, Question::findOrFail($second['question_id'])->conversation_id);
        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_a_developer_cannot_attach_to_another_developers_conversation(): void
    {
        $firstDeveloper = DeveloppeurPartenaire::create(['nom' => 'Partenaire A']);
        $secondDeveloper = DeveloppeurPartenaire::create(['nom' => 'Partenaire B']);
        $ownedConversation = Conversation::create([
            'developpeur_id' => $firstDeveloper->id,
            'reference' => 'conv_owned_by_first_developer',
            'contexte' => ['last_payment_method' => 'Airtel Money'],
            'statut' => 'active',
        ]);

        $result = $this->service()->ask(
            'Bonjour',
            $secondDeveloper->id,
            ['conversation_id' => $ownedConversation->reference],
        );

        $this->assertNotSame($ownedConversation->reference, $result['conversation_id']);
        $newConversation = Conversation::where('reference', $result['conversation_id'])->firstOrFail();
        $this->assertSame($secondDeveloper->id, $newConversation->developpeur_id);
        $this->assertSame($newConversation->id, Question::findOrFail($result['question_id'])->conversation_id);
        $this->assertDatabaseCount('conversations', 2);
    }

    public function test_non_persistent_mode_does_not_create_a_conversation_or_question(): void
    {
        $result = $this->service()->ask(
            'Bonjour',
            filters: ['conversation_id' => 'preview-conversation'],
            persist: false,
        );

        $this->assertSame('preview-conversation', $result['conversation_id']);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('questions', 0);
    }

    public function test_a_persisted_turn_updates_database_memory_for_future_requests(): void
    {
        $retrieval = Mockery::mock(RetrievalService::class);
        $llm = Mockery::mock(LlmClientInterface::class);
        $retrieval->shouldReceive('search')->once()->andThrow(new \RuntimeException('offline'));
        $llm->shouldNotReceive('complete');
        $service = new AssistantService(
            $retrieval,
            $llm,
            new ResponseGuard,
            new CitationGuard,
            new PaymentScopeResolver,
        );

        $result = $service->ask('Comment fonctionne le callback Airtel Money ?');

        $conversation = Conversation::where('reference', $result['conversation_id'])->firstOrFail();
        $this->assertSame('Airtel Money', $conversation->contexte['last_payment_method']);
        $this->assertSame('callback', $conversation->contexte['last_operation']);
        $this->assertSame(
            'Comment fonctionne le callback Airtel Money ?',
            $conversation->contexte['last_question'],
        );
    }

    public function test_non_persistent_follow_up_can_read_scoped_database_memory(): void
    {
        $conversation = Conversation::create([
            'reference' => 'conv_database_memory',
            'contexte' => [
                'last_question' => 'Comment fonctionne le callback Airtel Money ?',
                'last_answer' => 'Reponse precedente.',
                'last_payment_method' => 'Airtel Money',
                'last_operation' => 'callback',
            ],
            'statut' => 'active',
        ]);
        $retrieval = Mockery::mock(RetrievalService::class);
        $llm = Mockery::mock(LlmClientInterface::class);
        $retrieval->shouldReceive('search')
            ->once()
            ->withArgs(function (string $question, ?string $payment): bool {
                return str_contains($question, 'Comment fonctionne le callback Airtel Money ?')
                    && str_contains($question, 'Et pour le statut ?')
                    && $payment === 'Airtel Money';
            })
            ->andReturn(collect());
        $llm->shouldNotReceive('complete');
        $service = new AssistantService(
            $retrieval,
            $llm,
            new ResponseGuard,
            new CitationGuard,
            new PaymentScopeResolver,
        );

        $result = $service->ask(
            'Et pour le statut ?',
            filters: ['conversation_id' => $conversation->reference],
            persist: false,
        );

        $this->assertSame('Airtel Money', $result['requested_payment_method']);
        $this->assertSame($conversation->reference, $result['conversation_id']);
        $this->assertDatabaseCount('questions', 0);
    }

    private function service(): AssistantService
    {
        $retrieval = Mockery::mock(RetrievalService::class);
        $llm = Mockery::mock(LlmClientInterface::class);
        $retrieval->shouldNotReceive('search');
        $llm->shouldNotReceive('complete');

        return new AssistantService(
            $retrieval,
            $llm,
            new ResponseGuard,
            new CitationGuard,
            new PaymentScopeResolver,
        );
    }
}
