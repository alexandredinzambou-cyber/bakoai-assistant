<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\DeveloppeurPartenaire;
use App\Services\Assistant\ConversationMemoryStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class NinthAuditMemoryFailureTest extends TestCase
{
    use RefreshDatabase;

    private function failingStore(): ConversationMemoryStore
    {
        $store = new class extends ConversationMemoryStore
        {
            protected function persist(?Conversation $conversation, string $reference, array $memory): void
            {
                throw new \RuntimeException('Simulated durable storage outage');
            }
        };
        $this->instance(ConversationMemoryStore::class, $store);

        return $store;
    }

    public function test_newer_temporary_memory_beats_stale_database_and_recovers_durably(): void
    {
        $conversation = Conversation::create(['reference' => 'conv_ninth_stale', 'statut' => 'active', 'contexte' => ['topic_question' => 'Export', 'memory_revision' => 1]]);
        $store = $this->failingStore();
        $result = $store->write($conversation, $conversation->reference, ['topic_question' => 'Callback']);
        $this->assertSame('temporary', $result['status']);
        $this->assertSame('Export', $conversation->fresh()->contexte['topic_question']);
        $this->assertSame('Callback', $store->read($conversation->fresh(), $conversation->reference)['topic_question']);
        $durable = new ConversationMemoryStore;
        $this->assertSame('durable', $durable->write($conversation->fresh(), $conversation->reference, $result['memory'])['status']);
        Cache::flush();
        $this->assertSame('Callback', $durable->read($conversation->fresh(), $conversation->reference)['topic_question']);
    }

    public function test_temporary_reference_survives_without_a_database_row_and_warns_the_user(): void
    {
        $store = $this->failingStore();
        $reference = 'conv_'.str_repeat('a', 40);
        $store->write(null, $reference, ['topic_question' => 'Durée des exports', 'last_question' => 'Durée des exports', 'last_answer' => 'Le fichier reste disponible 7 jours après la demande.', 'duration_fact' => ['value' => 7, 'unit' => 'jour'], 'last_sources' => []]);
        $this->postJson('/api/mistral-raw/ask', ['question' => 'Convertis cette durée en heures.', 'conversation_id' => $reference])
            ->assertOk()->assertJsonPath('conversation_id', $reference)->assertJsonPath('memory_status', 'temporary')->assertJsonPath('memory_persisted', false)
            ->assertSee('168 heures')->assertSee('temporaire');
        $this->assertDatabaseMissing('conversations', ['reference' => $reference]);
    }

    public function test_cache_failure_does_not_erase_a_successful_durable_write(): void
    {
        Cache::shouldReceive('put')->once()->andThrow(new \RuntimeException('Cache unavailable'));
        $result = (new ConversationMemoryStore)->write(null, 'conv_ninth_durable', ['topic_question' => 'Callback']);
        $this->assertSame('durable', $result['status']);
        $this->assertDatabaseHas('conversations', ['reference' => 'conv_ninth_durable']);
    }

    public function test_total_write_failure_is_explicit(): void
    {
        Cache::shouldReceive('put')->once()->andThrow(new \RuntimeException('Cache unavailable'));
        $this->assertSame('unavailable', $this->failingStore()->write(null, 'conv_ninth_unavailable', ['topic_question' => 'Callback'])['status']);
    }

    public function test_total_write_failure_is_visible_in_the_http_answer(): void
    {
        $conversation = Conversation::create(['reference' => 'conv_ninth_http_unavailable', 'statut' => 'active', 'contexte' => ['topic_question' => 'Durée export', 'last_question' => 'Durée export', 'last_answer' => '7 jours après la demande.', 'last_sources' => []]]);
        $this->failingStore();
        $cacheDriver = Cache::driver();
        Cache::shouldReceive('driver')->andReturn($cacheDriver);
        Cache::shouldReceive('get')->andReturn(null);
        Cache::shouldReceive('put')->once()->andThrow(new \RuntimeException('Cache unavailable'));
        $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Et en heures ?', 'conversation_id' => $conversation->reference])->assertOk()
            ->assertJsonPath('memory_status', 'unavailable')->assertJsonPath('memory_persisted', false);
        $this->assertStringContainsString('168 heures', $response->json('answer'));
        $this->assertStringContainsString('Rappelez le contexte complet', $response->json('answer'));
    }

    public function test_a_false_cache_result_and_unavailable_logger_do_not_claim_temporary_success(): void
    {
        Cache::shouldReceive('put')->once()->andReturn(false);
        Log::shouldReceive('warning')->once()->andThrow(new \RuntimeException('Log volume full'));
        $this->assertSame('unavailable', $this->failingStore()->write(null, 'conv_ninth_false_cache', [])['status']);
    }

    public function test_a_cancelled_save_is_not_reported_as_durable(): void
    {
        $conversation = Conversation::create(['reference' => 'conv_ninth_cancelled', 'statut' => 'active', 'contexte' => ['topic_question' => 'Export']]);
        Conversation::saving(static fn () => false);
        try {
            $this->assertSame('temporary', (new ConversationMemoryStore)->write($conversation, $conversation->reference, ['topic_question' => 'Callback'])['status']);
            $this->assertSame('Export', $conversation->fresh()->contexte['topic_question']);
        } finally {
            Conversation::flushEventListeners();
        }
    }

    public function test_a_corrupt_cache_envelope_does_not_replace_valid_database_context(): void
    {
        $conversation = Conversation::create(['reference' => 'conv_ninth_bad_cache', 'statut' => 'active', 'contexte' => ['topic_question' => 'Callback']]);
        $store = new ConversationMemoryStore;
        Cache::put($store->key($conversation->reference), ['__conversation_cache' => true, 'status' => 'temporary', 'memory' => 'broken']);
        $this->assertFalse($store->hasTemporary($conversation->reference));
        $this->assertSame('Callback', $store->read($conversation, $conversation->reference)['topic_question']);
    }

    public function test_temporary_cache_never_exposes_owned_or_closed_conversations(): void
    {
        $store = $this->failingStore();
        $developer = DeveloppeurPartenaire::create(['nom' => 'Ninth test partner']);
        foreach ([['developpeur_id' => $developer->id], ['date_cloture' => now()]] as $index => $scope) {
            $reference = 'conv_'.str_repeat((string) ($index + 1), 40);
            $conversation = Conversation::create($scope + ['reference' => $reference, 'statut' => 'active', 'contexte' => []]);
            $store->write($conversation, $reference, ['topic_question' => 'Sandbox', 'last_question' => 'Plan Sandbox', 'plan_steps' => ['PRIVATE STEP', 'PRIVATE SECOND STEP']]);
            $response = $this->postJson('/api/mistral-raw/ask', ['question' => 'Va à l’étape 2.', 'conversation_id' => $reference])->assertOk();
            $this->assertNull($response->json('conversation_id'));
            $this->assertStringNotContainsString('PRIVATE', $response->json('answer'));
        }
    }
}
