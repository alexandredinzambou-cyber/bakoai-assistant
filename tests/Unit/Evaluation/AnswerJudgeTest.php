<?php

namespace Tests\Unit\Evaluation;

use App\Services\Evaluation\AnswerJudge;
use App\Services\Llm\OpenAiCompatibleLlmClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnswerJudgeTest extends TestCase
{
    public function test_a_valid_verdict_is_parsed_into_scores(): void
    {
        $raw = "```json\n".json_encode([
            'claims' => [
                ['claim' => 'Le montant est obligatoire', 'verdict' => 'supported', 'evidence' => 'amount (Obligatoire)'],
                ['claim' => 'HTTPS est obligatoire', 'verdict' => 'contradicted', 'evidence' => 'HTTPS recommandé'],
                ['claim' => 'Le secret expire', 'verdict' => 'unsupported', 'evidence' => ''],
                ['claim' => 'La documentation ne précise pas le délai', 'verdict' => 'unsupported'],
                ['claim' => 'sans verdict', 'verdict' => 'maybe'],
            ],
            'key_facts' => [['n' => 1, 'covered' => true], ['n' => 2, 'covered' => false], ['n' => 3, 'covered' => true]],
            'forbidden' => [['n' => 1, 'present' => false], ['n' => 2, 'present' => true]],
            'behavior' => 'answers',
            'relevance' => 4,
            'concision' => 3,
            'comment' => 'Bonne réponse, un point exagéré.',
        ])."\n```";

        $verdict = AnswerJudge::parse($raw, 3, 2);

        $this->assertNull($verdict['error']);
        $this->assertSame(3, $verdict['claims_total']);
        $this->assertSame([1, 1, 1], [$verdict['supported'], $verdict['unsupported'], $verdict['contradicted']]);
        $this->assertEqualsWithDelta(0.3333, $verdict['faithfulness'], 0.0001);
        $this->assertSame([1, 3], $verdict['key_facts_covered']);
        $this->assertEqualsWithDelta(0.6667, $verdict['key_fact_recall'], 0.0001);
        $this->assertSame([2], $verdict['forbidden_present']);
        $this->assertSame('answers', $verdict['behavior']);
        $this->assertSame(4, $verdict['relevance']);
        $this->assertSame(3, $verdict['concision']);
        // The gap statement is dropped, the claim without a valid verdict too; both are reported.
        $this->assertContains('gap_statement_dropped', $verdict['warnings']);
        $this->assertContains('claim_without_valid_verdict', $verdict['warnings']);
    }

    public function test_omissions_count_against_the_answer_and_bad_json_is_an_error(): void
    {
        $partial = AnswerJudge::parse('{"claims":[],"key_facts":[{"n":1,"covered":true}],"forbidden":[],"behavior":"hedges","relevance":9}', 2, 1);

        $this->assertNull($partial['error']);
        $this->assertSame([1], $partial['key_facts_covered']);
        $this->assertSame(0.5, $partial['key_fact_recall']);
        $this->assertNull($partial['faithfulness']);
        $this->assertNull($partial['behavior']);
        $this->assertNull($partial['relevance']);
        $this->assertContains('key_fact_missing', $partial['warnings']);
        $this->assertContains('forbidden_missing', $partial['warnings']);
        $this->assertContains('invalid_behavior', $partial['warnings']);
        $this->assertContains('invalid_score', $partial['warnings']);

        $this->assertSame('invalid_json', AnswerJudge::parse('{"claims": [}', 1, 0)['error']);
        $this->assertSame('no_json_object', AnswerJudge::parse('Je ne peux pas juger.', 1, 0)['error']);
    }

    public function test_gap_statements_are_told_apart_from_negative_claims_about_the_api(): void
    {
        $this->assertTrue(AnswerJudge::isGapStatement('La documentation PVIT ne précise pas le nombre de renvois.'));
        $this->assertTrue(AnswerJudge::isGapStatement("Ce point n'est pas documenté."));
        $this->assertTrue(AnswerJudge::isGapStatement('The documentation does not specify the retry count.'));
        $this->assertTrue(AnswerJudge::isGapStatement('The PVIT documentation does not explicitly specify steps to refund a client.'));
        $this->assertFalse(AnswerJudge::isGapStatement('La notification report.completed ne contient pas le fichier.'));
        $this->assertFalse(AnswerJudge::isGapStatement("Le remboursement ne nécessite pas d'attendre un callback."));
    }

    public function test_prose_words_exclude_code_blocks_citation_markers_and_pipeline_notices(): void
    {
        $answer = "Appelez l'API de statut [1].\n\n```bash\ncurl -X GET \"https://api.mypvit.pro/v2/x/status\"\n```\n\n"
            ."*Certains points de la reponse generee n'ont pas pu etre verifies dans la documentation PVIT et ont ete retires.*\n"
            ."*Les valeurs d'exemple reprises ici (x) sont celles de la documentation officielle PVIT : remplacez-les par vos propres valeurs.*";

        $this->assertSame(4, AnswerJudge::proseWordCount($answer));
        $this->assertStringNotContainsString('Certains points', AnswerJudge::withoutPipelineNotices($answer));
        $this->assertStringContainsString('curl -X GET', AnswerJudge::withoutPipelineNotices($answer));
    }

    public function test_the_judge_calls_its_own_model_deterministically_and_in_json_mode(): void
    {
        config()->set([
            'llm.retry_attempts' => 1,
            'llm.judge' => ['provider' => 'groq', 'model_key' => 'judge_model', 'seed' => 42],
            'llm.providers.groq' => [
                'base_url' => 'https://groq.test/openai/v1',
                'api_key' => 'test-key',
                'model' => 'generator-model',
                'judge_model' => 'judge-model',
                'timeout' => 5,
            ],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://groq.test/openai/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
                'claims' => [['claim' => 'Le statut se lit avec GET /status', 'verdict' => 'supported', 'evidence' => 'GET /{code-url-status}/status']],
                'key_facts' => [['n' => 1, 'covered' => true]],
                'forbidden' => [['n' => 1, 'present' => false]],
                'behavior' => 'answers',
                'relevance' => 5,
                'concision' => 5,
                'comment' => 'ok',
            ])]]]]),
        ]);

        $judge = new AnswerJudge(new OpenAiCompatibleLlmClient);
        $verdict = $judge->judge(
            'Comment connaitre le statut ?',
            '[SOURCE:1] Endpoint : GET /{code-url-status}/status',
            'Utilisez GET /status [1].',
            [['fact' => 'Le statut se lit par GET /status']],
            ['Le statut se lit par POST'],
        );

        $this->assertSame('judge-model', $judge->model());
        $this->assertSame(1.0, $verdict['faithfulness']);
        $this->assertSame(1.0, $verdict['key_fact_recall']);
        Http::assertSent(static function (Request $request): bool {
            $user = $request['messages'][1]['content'];

            return $request['model'] === 'judge-model'
                && $request['temperature'] === 0.0
                && $request['seed'] === 42
                && ! isset($request['random_seed'])
                && $request['response_format'] === ['type' => 'json_object']
                && str_contains($user, "CONTEXT:\n[SOURCE:1] Endpoint")
                && str_contains($user, "KEY FACTS:\n1. Le statut se lit par GET /status")
                && str_contains($user, "FORBIDDEN:\n1. Le statut se lit par POST");
        });
    }

    public function test_mistral_receives_its_own_seed_parameter(): void
    {
        config()->set([
            'llm.retry_attempts' => 1,
            'llm.providers.mistral' => [
                'base_url' => 'https://mistral.test/v1',
                'api_key' => 'test-key',
                'model' => 'open-mistral-nemo',
                'judge_model' => 'ministral-14b-latest',
                'timeout' => 5,
            ],
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://mistral.test/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => '{}']]]])]);

        (new OpenAiCompatibleLlmClient)->completeMessages('mistral', 'judge_model', [['role' => 'user', 'content' => 'x']], ['seed' => 7, 'temperature' => 0.0]);

        Http::assertSent(static fn (Request $request): bool => $request['model'] === 'ministral-14b-latest'
            && $request['random_seed'] === 7
            && ! isset($request['seed'])
            && $request['temperature'] === 0.0);
    }
}
