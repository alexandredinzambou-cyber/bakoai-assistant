<?php

namespace App\Services\Assistant;

use App\Models\Conversation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Durable memory with an explicit, versioned temporary fallback when writes fail. */
class ConversationMemoryStore
{
    public function key(string $reference): string
    {
        return 'mistral-raw:conversation:'.sha1($reference);
    }

    private function cached(string $reference): array
    {
        try {
            $value = Cache::get($this->key($reference));

            return is_array($value) ? $value : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function status(string $status): void
    {
        if (app()->bound('request')) {
            request()->attributes->set('conversation_memory_status', $status);
        }
    }

    private function logFailure(string $message, array $context): void
    {
        try {
            Log::warning($message, $context);
        } catch (Throwable) {
            // A full or unavailable log volume must not interrupt memory fallback.
        }
    }

    public function hasTemporary(string $reference): bool
    {
        $cached = $this->cached($reference);

        return ($cached['__conversation_cache'] ?? false) === true && ($cached['status'] ?? null) === 'temporary' && is_array($cached['memory'] ?? null);
    }

    public function read(?Conversation $conversation, ?string $reference): array
    {
        if ($reference === null) {
            return [];
        }
        $db = is_array($conversation?->contexte) ? $conversation->contexte : [];
        $cached = $this->cached($reference);
        if (($cached['__conversation_cache'] ?? false) === true && is_array($cached['memory'] ?? null) && in_array($cached['status'] ?? null, ['temporary', 'durable'], true)) {
            $memory = $cached['memory'];
            if (($cached['status'] === 'temporary' && (float) ($memory['memory_revision'] ?? 0) > (float) ($db['memory_revision'] ?? 0)) || $db === []) {
                $this->status($cached['status']);

                return $memory;
            }
        }
        if ($db !== []) {
            return $db;
        }

        return isset($cached['__conversation_cache']) ? [] : $cached;
    }

    protected function persist(?Conversation $conversation, string $reference, array $memory): void
    {
        if ($conversation === null) {
            $created = Conversation::create(['developpeur_id' => null, 'reference' => $reference, 'contexte' => $memory, 'langue' => 'fr', 'statut' => 'active']);
            if (! $created->exists) {
                throw new \RuntimeException('Conversation creation was not saved.');
            }

            return;
        }
        if (! $conversation->forceFill(['contexte' => array_merge(is_array($conversation->contexte) ? $conversation->contexte : [], $memory)])->save()) {
            throw new \RuntimeException('Conversation update was not saved.');
        }
    }

    public function write(?Conversation $conversation, string $reference, array $memory): array
    {
        $memory['memory_revision'] = microtime(true);
        $durable = false;
        $cached = false;
        try {
            $this->persist($conversation, $reference, $memory);
            $durable = true;
        } catch (Throwable $error) {
            $this->logFailure('Mistral raw conversation memory could not be persisted.', ['reason' => 'conversation_memory_write_failed', 'exception' => $error::class, 'code' => (string) $error->getCode()]);
        }
        $status = $durable ? 'durable' : 'temporary';
        try {
            $cached = Cache::put($this->key($reference), ['__conversation_cache' => true, 'status' => $status, 'memory' => $memory], now()->addMinutes(120)) !== false;
        } catch (Throwable $error) {
            $this->logFailure('Conversation memory cache write failed.', ['exception' => $error::class]);
        }
        if (! $durable && ! $cached) {
            $status = 'unavailable';
        }
        $this->status($status);

        return ['status' => $status, 'memory' => $memory];
    }
}
