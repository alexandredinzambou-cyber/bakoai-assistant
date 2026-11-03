<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConversationReplayCommandTest extends TestCase
{
    public function test_campaign_is_saved_and_resumes_without_replaying_earlier_messages(): void
    {
        $path = storage_path('framework/testing/conversation-replay-command-test.json');
        Http::fake(['127.0.0.1:8765/*' => Http::sequence()
            ->push(['answer' => 'Bonjour', 'should_escalate' => false, 'conversation_id' => null])
            ->push(['answer' => 'KYC et Check Status sont obligatoires', 'should_escalate' => false, 'conversation_id' => 'conv_test'])]);
        try {
            $this->artisan('assistant:conversation-replay', ['--scenario' => 'integration', '--output' => $path, '--limit' => 1, '--throttle-ms' => 0])->assertExitCode(0);
            $this->artisan('assistant:conversation-replay', ['--scenario' => 'integration', '--output' => $path, '--limit' => 1, '--throttle-ms' => 0, '--resume' => true])->assertExitCode(0);
            $run = json_decode(file_get_contents($path), true);
            $this->assertCount(2, $run['rows']);
            $this->assertSame([1, 2], array_column($run['rows'], 'turn'));
            Http::assertSentCount(2);
            Http::assertNotSent(static fn ($request) => str_contains($request->url(), '/api/ticket'));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_remote_servers_are_rejected_before_any_request(): void
    {
        Http::fake();
        $this->artisan('assistant:conversation-replay', ['--base-url' => 'https://example.com'])->assertExitCode(1);
        Http::assertNothingSent();
    }
}
