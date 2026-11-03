<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnswerEvalCommandTest extends TestCase
{
    private string $runId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runId = 'phpunit-'.Str::random(8);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/eval-runs/'.$this->runId));
        parent::tearDown();
    }

    public function test_deterministic_judging_fails_refusals_on_answerable_questions_and_long_answers(): void
    {
        $this->writeRun(valid: true, records: [
            $this->record('ok', 'fact', 'answer', "Le champ amount est obligatoire [1].", refused: false),
            $this->record('refused', 'fact', 'answer', "Je n'ai pas pu produire une reponse suffisamment adossee.", refused: true),
            $this->record('long', 'fact', 'answer', trim(str_repeat('Le montant est obligatoire pour initier un paiement [1]. ', 12)), refused: false),
            $this->record('declined', 'out_of_scope', 'decline', 'Je ne peux pas vous aider sur ce sujet.', refused: true),
            $this->record('outage', 'fact', 'answer', 'LLM provider [mistral] rate limit exceeded (HTTP 429).', refused: null, infrastructure: 'llm_rate_limited'),
        ]);

        $this->artisan('assistant:answer-eval', ['--judge' => true, '--run' => $this->runId, '--no-llm-judge' => true])
            ->expectsOutputToContain('ok pass')
            ->expectsOutputToContain('refused FAIL unexpected_refusal')
            ->expectsOutputToContain('long FAIL too_long')
            ->expectsOutputToContain('declined pass')
            ->expectsOutputToContain('outage EXCLUDED')
            ->assertSuccessful();

        $judgement = json_decode((string) File::get(collect(File::glob(storage_path("app/eval-runs/{$this->runId}/judgement-*.json")))->first()), true);
        $this->assertSame(5, $judgement['summary']['cases']);
        $this->assertSame(4, $judgement['summary']['scored']);
        $this->assertSame(2, $judgement['summary']['pass']);
        $this->assertSame(1, $judgement['summary']['answerable_refusals']);
        $this->assertSame(0.3333, $judgement['summary']['answerable_refusal_rate']);
        $this->assertSame('8b1a5df', substr($judgement['run_metadata']['git']['commit'], 0, 7));
    }

    public function test_draft_judging_replaces_the_guarded_answer_with_the_generator_draft(): void
    {
        $refusedByGuard = $this->record('guarded', 'fact', 'answer', "Je n'ai pas pu produire une reponse suffisamment adossee.", refused: true);
        $refusedByGuard['generator_calls'] = [['output' => 'Le champ amount est obligatoire [SOURCE:1].', 'error' => null]];
        // Like the pipeline, a guard refusal still lists the closest passages as sources.
        $refusedByGuard['sources'] = [['url' => 'https://docs.mypvit.pro/fr/api/payment']];
        $injection = $this->record('injection', 'out_of_scope', 'decline', 'Question refusee.', refused: true);
        $injection['status'] = 422;
        $this->writeRun(valid: true, records: [$refusedByGuard, $injection]);

        $this->artisan('assistant:answer-eval', ['--judge' => true, '--draft' => true, '--run' => $this->runId, '--no-llm-judge' => true])
            ->expectsOutputToContain('guarded pass')
            ->expectsOutputToContain('injection pass')
            ->assertSuccessful();

        $file = collect(File::glob(storage_path("app/eval-runs/{$this->runId}/judgement-draft-*.json")))->first();
        $this->assertNotNull($file, 'A draft judgement is written under its own name.');
        $judgement = json_decode((string) File::get($file), true);
        $this->assertSame('draft', $judgement['variant']);
        $this->assertFalse($judgement['rows'][0]['refused']);
        $this->assertSame(0, $judgement['summary']['answerable_refusals']);
        $this->assertTrue($judgement['rows'][1]['refused'], 'A 422 had no draft: it keeps its refusal.');
    }

    public function test_an_invalid_run_is_not_judged_without_force(): void
    {
        $this->writeRun(valid: false, records: [
            $this->record('ok', 'fact', 'answer', 'Le champ amount est obligatoire [1].', refused: false),
        ]);

        $this->artisan('assistant:answer-eval', ['--judge' => true, '--run' => $this->runId, '--no-llm-judge' => true])
            ->expectsOutputToContain('recorded as invalid')
            ->assertFailed();

        $this->artisan('assistant:answer-eval', ['--judge' => true, '--run' => $this->runId, '--no-llm-judge' => true, '--force' => true])
            ->assertSuccessful();
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function writeRun(bool $valid, array $records): void
    {
        $directory = storage_path('app/eval-runs/'.$this->runId);
        File::ensureDirectoryExists($directory);
        File::put($directory.'/answers.jsonl', implode("\n", array_map(static fn (array $record): string => json_encode($record), $records))."\n");
        File::put($directory.'/meta.json', json_encode([
            'kind' => 'answer-eval-run',
            'run_id' => $this->runId,
            'split' => 'dev',
            'endpoint' => '/api/mistral-raw/ask',
            'git' => ['commit' => '8b1a5dffcb9f81f4f57826cc28c3c781894497f3', 'dirty_files' => 3],
            'dataset' => ['version' => 'test'],
            'generator' => ['model' => 'open-mistral-nemo'],
            'retrieval' => ['top_k' => 10],
            'knowledge_base' => ['fingerprint' => 'abc'],
            'validity' => ['valid' => $valid, 'infrastructure_failures' => $valid ? [] : ['llm_unavailable' => 3]],
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function record(string $id, string $type, string $behavior, string $answer, ?bool $refused, ?string $infrastructure = null): array
    {
        return [
            'id' => $id,
            'split' => 'dev',
            'type' => $type,
            'behavior' => $behavior,
            'question' => 'Quel est le champ obligatoire ?',
            'key_facts' => [['fact' => 'amount est obligatoire', 'quote' => 'amount', 'page' => 'https://docs.mypvit.pro/fr/api/payment']],
            'forbidden_facts' => [],
            'status' => $infrastructure === null ? 200 : 502,
            'answer' => $answer,
            'refused' => $refused,
            'infrastructure_error' => $infrastructure,
            'answer_type' => $type,
            'removed_claims' => 0,
            'sources' => $refused === false ? [['url' => 'https://docs.mypvit.pro/fr/api/payment', 'number' => 1]] : [],
            'context' => '[SOURCE:1] amount (Obligatoire)',
            'generator_calls' => [],
        ];
    }
}
