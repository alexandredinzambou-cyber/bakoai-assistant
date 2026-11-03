<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Question;
use App\Services\Assistant\AnswerQualityEvaluator;
use App\Services\Assistant\GenerationPolicy;
use App\Services\Assistant\ResponseGuard;
use App\Services\Evaluation\AnswerJudge;
use App\Services\Evaluation\CachedQueryEmbeddingService;
use App\Services\Evaluation\EvalRunMetadata;
use App\Services\Evaluation\EvalStatistics;
use App\Services\Evaluation\RecordingLlmClient;
use App\Services\Llm\Exceptions\LlmClientException;
use App\Services\Llm\OpenAiCompatibleLlmClient;
use App\Services\Rag\EmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use JsonException;
use Throwable;

/**
 * End-to-end answer evaluation of /api/mistral-raw/ask on the versioned gold set, in two steps
 * so a run is recorded once and can be re-judged without calling the generator again:
 *
 * --record  sends each question through the real HTTP route (guards, retrieval, generation,
 *           AnswerVerifier) and stores the answer with the exact context the generator read;
 * --judge   scores a recorded run: deterministic checks (expected behaviour, length budget,
 *           hygiene) and an LLM judge (faithfulness to the context, key facts, forbidden facts,
 *           relevance, concision).
 *
 * A run where more than 5% of the cases failed for infrastructure reasons (provider outage, rate
 * limit, embedding fallback, crash) is recorded as invalid and is not judged unless --force.
 */
class AnswerEval extends Command
{
    // The budgets of GenerationPolicy are targets given to the model; an answer is over budget
    // beyond this margin, not at the first extra word.
    private const LENGTH_TOLERANCE = 1.25;

    // An answer covers enough of the reference when it states at least this share of the key facts.
    private const COMPLETENESS_THRESHOLD = 0.5;

    protected $signature = 'assistant:answer-eval
        {--record : Send the questions through /api/mistral-raw/ask and store the answers}
        {--judge : Score a recorded run (the latest one unless --run is given)}
        {--run= : Run id to judge, a folder of storage/app/eval-runs}
        {--file=database/seeders/eval_gold.json : Versioned gold dataset}
        {--split=dev : dev, test or all}
        {--only= : Comma-separated case ids, for a smoke test}
        {--throttle-ms=4000 : Pause between two questions while recording}
        {--judge-tpm=7000 : Token budget per minute of the judge provider}
        {--no-llm-judge : Deterministic checks only}
        {--baseline= : Previous judgement JSON, compared case by case (McNemar)}
        {--force : Judge a run even when it was recorded as invalid}
        {--draft : Judge the first draft of the generator instead of the final answer, as a reader would get it without AnswerVerifier}';

    protected $description = 'Record /api/mistral-raw answers on the gold set, then judge them (faithfulness, completeness, behaviour, length).';

    public function handle(EvalRunMetadata $metadata): int
    {
        if (! $this->option('record') && ! $this->option('judge')) {
            $this->error('Give --record, --judge, or both.');

            return self::INVALID;
        }

        $runId = $this->option('run') ? (string) $this->option('run') : null;

        if ($this->option('record')) {
            $runId = $this->record($metadata);

            if ($runId === null) {
                return self::INVALID;
            }
        }

        return $this->option('judge') ? $this->judgeRun($runId ?? $this->latestRun()) : self::SUCCESS;
    }

    private function record(EvalRunMetadata $metadata): ?string
    {
        $file = base_path((string) $this->option('file'));
        $split = (string) $this->option('split');

        if (! in_array($split, ['dev', 'test', 'all'], true)) {
            $this->error('--split must be dev, test or all.');

            return null;
        }

        try {
            $dataset = json_decode((string) File::get($file), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $this->error('Gold dataset unreadable: '.$exception->getMessage());

            return null;
        }

        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $cases = array_values(array_filter(
            (array) ($dataset['cases'] ?? []),
            static fn (array $case): bool => ($split === 'all' || ($case['split'] ?? null) === $split)
                && ($only === [] || in_array($case['id'], $only, true)),
        ));

        if ($cases === []) {
            $this->error('No case selected.');

            return null;
        }

        $runId = now()->format('Ymd-His').'-'.$split.($only !== [] ? '-subset' : '');
        $directory = storage_path('app/eval-runs/'.$runId);
        File::ensureDirectoryExists($directory);

        $recorder = new RecordingLlmClient;
        app()->instance(OpenAiCompatibleLlmClient::class, $recorder);
        app()->singleton(EmbeddingService::class, CachedQueryEmbeddingService::class);
        /** @var CachedQueryEmbeddingService $embedding */
        $embedding = app(EmbeddingService::class);
        $kernel = app(HttpKernel::class);
        $throttleMs = max(0, (int) $this->option('throttle-ms'));
        $infrastructure = [];
        $lines = [];

        foreach ($cases as $index => $case) {
            if ($index > 0 && $throttleMs > 0) {
                usleep($throttleMs * 1000);
            }

            $fallbacksBefore = count($embedding->fallbackQueries());
            $started = microtime(true);
            $request = Request::create('/api/mistral-raw/ask', 'POST', server: [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
            ], content: json_encode(['question' => $case['question']], JSON_THROW_ON_ERROR));

            try {
                $response = $kernel->handle($request);
                $kernel->terminate($request, $response);
                $status = $response->getStatusCode();
                $body = json_decode((string) $response->getContent(), true) ?: [];
            } catch (Throwable $exception) {
                $status = 500;
                $body = ['error' => $exception->getMessage()];
            }

            // The controller sets a 120 s limit for the web request; on Windows it counts wall
            // time for the whole CLI process, the throttling and the judging included.
            set_time_limit(0);
            $calls = $recorder->takeCalls();
            $error = $this->infrastructureError($status, $body, $calls, count($embedding->fallbackQueries()) > $fallbacksBefore);

            if ($error !== null) {
                $infrastructure[$error] = ($infrastructure[$error] ?? 0) + 1;
            }

            // Every request opens a conversation, and an escalation records its question for the
            // ticket: the rows this run created are its own litter. The question goes first, its
            // conversation cannot be deleted while a question refers to it.
            if (is_int($body['question_id'] ?? null)) {
                Question::query()->whereKey($body['question_id'])->delete();
            }

            if (is_string($body['conversation_id'] ?? null)) {
                Conversation::query()->where('reference', $body['conversation_id'])->whereNull('developpeur_id')->delete();
            }

            $refused = $status === 422
                || ($body['citation_filtered'] ?? false) === true
                || (($body['code_filtered'] ?? false) === true && ! array_key_exists('citation_filtered', $body));
            $record = [
                'id' => $case['id'],
                'split' => $case['split'] ?? null,
                'type' => $case['type'] ?? null,
                'behavior' => $case['behavior'] ?? 'answer',
                'question' => $case['question'],
                'key_facts' => $case['key_facts'] ?? [],
                'forbidden_facts' => $case['forbidden_facts'] ?? [],
                'status' => $status,
                'answer' => (string) ($body['answer'] ?? $body['error'] ?? ''),
                'refused' => $status === 200 || $status === 422 ? $refused : null,
                'infrastructure_error' => $error,
                'citation_filtered' => $body['citation_filtered'] ?? null,
                'code_filtered' => $body['code_filtered'] ?? null,
                'removed_claims' => $body['removed_claims'] ?? null,
                'answer_type' => $body['answer_type'] ?? null,
                'sources' => $body['sources'] ?? [],
                'context' => (string) ($calls[0]['context'] ?? ''),
                'generator_calls' => array_map(static fn (array $call): array => [
                    'system_prompt_sha1' => $call['system_prompt_sha1'],
                    'options' => $call['options'],
                    'output' => $call['output'],
                    'error' => $call['error'],
                    'ms' => $call['ms'],
                ], $calls),
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
            $lines[] = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $this->line(sprintf(
                '[%d/%d] %s status=%d %s%s',
                $index + 1,
                count($cases),
                $case['id'],
                $status,
                $record['refused'] ? 'refused' : 'answered',
                $error !== null ? ' INFRA:'.$error : '',
            ));
        }

        File::put($directory.'/answers.jsonl', implode("\n", $lines)."\n");
        $meta = $metadata->collect($file, [
            'kind' => 'answer-eval-run',
            'run_id' => $runId,
            'split' => $split,
            'only' => $only,
            'endpoint' => '/api/mistral-raw/ask',
            'throttle_ms' => $throttleMs,
            'validity' => EvalRunMetadata::validity(count($cases), $infrastructure),
        ]);
        File::put($directory.'/meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->info("Run {$runId} recorded: ".count($cases).' cases, '
            .($meta['validity']['valid'] ? 'VALID' : 'INVALID ('.json_encode($infrastructure).')'));

        return $runId;
    }

    /**
     * Why a case failed for reasons that say nothing about the assistant, or null.
     *
     * @param  array<string, mixed>  $body
     * @param  list<array<string, mixed>>  $calls
     */
    private function infrastructureError(int $status, array $body, array $calls, bool $embeddingFellBack): ?string
    {
        $message = strtolower((string) ($body['error'] ?? ''));

        return match (true) {
            $status === 502 && str_contains($message, '429') => 'llm_rate_limited',
            $status === 502 && (str_contains($message, '401') || str_contains($message, '403')) => 'llm_auth',
            $status === 502 => 'llm_unavailable',
            $status === 429 => 'route_throttled',
            $status >= 500 => 'server_error',
            $embeddingFellBack => 'embedding_fallback',
            // The draft was answered but its informed retry never reached the model: the case
            // lost its second chance to an outage.
            collect($calls)->contains(static fn (array $call): bool => $call['error'] !== null) => 'llm_retry_failed',
            default => null,
        };
    }

    private function latestRun(): string
    {
        $runs = File::isDirectory(storage_path('app/eval-runs'))
            ? collect(File::directories(storage_path('app/eval-runs')))->map(static fn (string $dir): string => basename($dir))->sort()->values()
            : collect();

        return (string) $runs->last();
    }

    private function judgeRun(string $runId): int
    {
        $directory = storage_path('app/eval-runs/'.$runId);

        if ($runId === '' || ! is_file($directory.'/answers.jsonl') || ! is_file($directory.'/meta.json')) {
            $this->error("Run not found: {$runId}");

            return self::INVALID;
        }

        try {
            $meta = json_decode((string) File::get($directory.'/meta.json'), true, flags: JSON_THROW_ON_ERROR);
            $records = array_map(
                static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                array_values(array_filter(preg_split('/\R/', (string) File::get($directory.'/answers.jsonl')) ?: [])),
            );
        } catch (JsonException $exception) {
            $this->error('Run unreadable: '.$exception->getMessage());

            return self::INVALID;
        }

        if (! ($meta['validity']['valid'] ?? false) && ! $this->option('force')) {
            $this->error("Run {$runId} was recorded as invalid (".json_encode($meta['validity']['infrastructure_failures'] ?? [])
                .'): its rates would measure provider availability. Record a new run, or pass --force.');

            return self::FAILURE;
        }

        set_time_limit(0);
        $variant = $this->option('draft') ? 'draft' : 'final';

        if ($variant === 'draft') {
            $responseGuard = app(ResponseGuard::class);
            $records = array_map(fn (array $record): array => $this->asDraft($record, $responseGuard), $records);
        }

        $useLlm = ! $this->option('no-llm-judge');
        $judge = app(AnswerJudge::class);
        $policy = new GenerationPolicy;
        $evaluator = new AnswerQualityEvaluator;
        $tpm = max(500, (int) $this->option('judge-tpm'));
        $rows = [];
        $judgeErrors = 0;
        $judgeCalls = 0;
        $consecutiveUnavailable = 0;
        $aborted = null;

        foreach ($records as $index => $record) {
            // A judge that stayed unavailable for three cases in a row (typically a daily token
            // quota) will not come back within the run: stop instead of retrying for every case.
            if ($consecutiveUnavailable >= 3) {
                $aborted = sprintf('judge unavailable for 3 consecutive cases; stopped before case %d/%d (%s)', $index + 1, count($records), $record['id']);
                $this->error('Judgement aborted: '.$aborted.'.');

                break;
            }

            $row = $this->deterministic($record, $policy, $evaluator);
            $needsJudge = $useLlm && $record['infrastructure_error'] === null && $record['refused'] === false;
            $verdict = null;

            if ($needsJudge) {
                $judgeCalls++;
                $verdict = $this->callJudge($judge, $record, $tpm);

                if ($verdict['error'] !== null) {
                    $judgeErrors++;
                }

                $consecutiveUnavailable = str_starts_with((string) $verdict['error'], 'judge_unavailable')
                    ? $consecutiveUnavailable + 1
                    : 0;
            }

            $rows[] = $this->verdict($row, $record, $verdict, $useLlm);
            $last = end($rows);
            $this->line(sprintf(
                '[%d/%d] %s %s%s',
                $index + 1,
                count($records),
                $record['id'],
                $last['excluded'] ? 'EXCLUDED' : ($last['pass'] ? 'pass' : 'FAIL '.implode(',', $last['failures'])),
                $verdict !== null && $verdict['error'] !== null ? ' (judge error: '.$verdict['error'].')' : '',
            ));
        }

        $scored = array_values(array_filter($rows, static fn (array $row): bool => ! $row['excluded']));
        $judgeValidity = EvalRunMetadata::validity(max(1, $judgeCalls), $judgeErrors > 0 ? ['judge_error' => $judgeErrors] : []);

        if ($aborted !== null) {
            $judgeValidity['valid'] = false;
            $judgeValidity['aborted'] = $aborted;
        }
        $report = [
            'kind' => 'answer-eval-judgement',
            'run_id' => $runId,
            'run_metadata' => $meta,
            'judged_at' => now()->toIso8601String(),
            'variant' => $variant,
            'judge' => $useLlm ? [
                'provider' => $judge->provider(),
                'model' => $judge->model(),
                'seed' => (int) config('llm.judge.seed'),
                'temperature' => 0.0,
                'calls' => $judgeCalls,
                'validity' => $judgeValidity,
            ] : null,
            'criteria' => [
                'length_tolerance' => self::LENGTH_TOLERANCE,
                'completeness_threshold' => self::COMPLETENESS_THRESHOLD,
                'faithful' => 'no unsupported and no contradicted claim',
                'off_topic' => 'judge relevance <= 2',
                'padded' => 'judge concision <= 2',
            ],
            'summary' => $this->summary($scored, count($rows)),
            'by_type' => collect($scored)->groupBy('type')->map(fn (Collection $group): array => $this->summary($group->all(), $group->count()))->all(),
            'comparison' => null,
            'rows' => $rows,
        ];

        if ($baseline = $this->option('baseline')) {
            $report['comparison'] = $this->compare((string) $baseline, $rows);
        }

        $stamp = now()->format('Ymd-His');
        $stamp = ($variant === 'draft' ? 'draft-' : '').$stamp;
        File::put($directory."/judgement-{$stamp}.json", json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        File::put($directory."/judgement-{$stamp}.md", $this->markdown($report));
        $this->info("Judgement written to {$directory}/judgement-{$stamp}.md");
        $summary = $report['summary'];
        $this->line(sprintf(
            'pass=%s [%s-%s] behaviour=%s answerable_refusals=%s faithful=%s key_fact_recall=%s length_ok=%s relevance=%s concision=%s excluded=%d',
            $this->percent($summary['pass_rate']),
            $this->percent($summary['pass_ci95']['low'] ?? null),
            $this->percent($summary['pass_ci95']['high'] ?? null),
            $this->percent($summary['behavior_ok_rate']),
            $this->percent($summary['answerable_refusal_rate']),
            $this->percent($summary['faithful_rate']),
            $this->percent($summary['key_fact_recall']),
            $this->percent($summary['length_ok_rate']),
            $summary['relevance'] ?? 'N/A',
            $summary['concision'] ?? 'N/A',
            $summary['excluded'],
        ));

        return ! $useLlm || $judgeValidity['valid'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Checks that need no model: expected behaviour against the pipeline's own refusal flag,
     * length against the budget of the case's type, and display hygiene.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    /**
     * The record as it would read without AnswerVerifier: the generator's first draft, still
     * through the code guard (that one stays), with its [SOURCE:n] markers renumbered for display
     * and nothing removed or refused for lack of verification. A case the pipeline refused before
     * any generation (422: injection, no context) keeps its outcome.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function asDraft(array $record, ResponseGuard $responseGuard): array
    {
        $draft = $record['generator_calls'][0]['output'] ?? null;

        if ($record['infrastructure_error'] !== null || ! is_string($draft)) {
            return $record;
        }

        if ($responseGuard->containsSourceCode($draft)) {
            $stripped = $responseGuard->stripCodeLikeSegments($draft);
            $draft = mb_strlen($stripped) >= 20 && ! $responseGuard->containsSourceCode($stripped) ? $stripped : null;
        }

        if ($draft === null) {
            return ['answer' => $responseGuard->refusal(), 'refused' => true, 'removed_claims' => 0] + $record;
        }

        return [
            'answer' => (string) preg_replace('/\[SOURCE\s*:\s*(\d+)\s*\]/iu', '[$1]', $draft),
            'refused' => false,
            'removed_claims' => 0,
        ] + $record;
    }

    private function deterministic(array $record, GenerationPolicy $policy, AnswerQualityEvaluator $evaluator): array
    {
        $type = in_array($record['type'], [GenerationPolicy::FACT, GenerationPolicy::PROCEDURE, GenerationPolicy::DIAGNOSTIC], true)
            ? $record['type']
            : GenerationPolicy::FACT;
        $budget = (int) ceil($policy->wordTarget($type) * self::LENGTH_TOLERANCE);
        $words = AnswerJudge::proseWordCount((string) $record['answer']);
        $answered = $record['refused'] === false;
        $quality = $evaluator->evaluate(
            (string) $record['question'],
            (string) $record['answer'],
            (array) $record['sources'],
            expectsSources: $answered,
            maxWords: PHP_INT_MAX,
            refused: ! $answered,
        );
        // The evaluator's code heuristic predates the cURL/JSON policy; ResponseGuard enforces
        // the code rules on the final answer. Length is measured here on prose only.
        $hygiene = array_values(array_filter(
            $quality->violations,
            static fn (string $violation): bool => ! in_array($violation, ['code_detected', 'too_long'], true),
        ));

        return [
            'id' => $record['id'],
            'type' => $record['type'],
            'behavior' => $record['behavior'],
            'refused' => $record['refused'],
            'infrastructure_error' => $record['infrastructure_error'],
            'answer_type' => $record['answer_type'],
            'words' => $answered ? $words : null,
            'word_budget' => $budget,
            'length_ok' => ! $answered || $words <= $budget,
            'hygiene' => $hygiene,
            'removed_claims' => $record['removed_claims'],
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function callJudge(AnswerJudge $judge, array $record, int $tokensPerMinute): array
    {
        $messages = $judge->messages(
            (string) $record['question'],
            (string) $record['context'],
            (string) $record['answer'],
            (array) $record['key_facts'],
            (array) $record['forbidden_facts'],
        );
        // Rough token estimate (about 3 characters per token in French) plus the completion.
        $estimatedTokens = (int) (mb_strlen(implode("\n", array_column($messages, 'content'))) / 3) + 1500;
        $lastError = 'not_called';

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $started = microtime(true);

            try {
                $verdict = $judge->judge(
                    (string) $record['question'],
                    (string) $record['context'],
                    (string) $record['answer'],
                    (array) $record['key_facts'],
                    (array) $record['forbidden_facts'],
                );
                $this->pace($started, $estimatedTokens, $tokensPerMinute);

                return $verdict;
            } catch (LlmClientException $exception) {
                $lastError = $exception->getMessage();
                $rateLimited = str_contains($lastError, '429') || str_contains(strtolower($lastError), 'rate limit');
                $this->warn("  judge attempt {$attempt} failed: {$lastError}");
                sleep($rateLimited ? 20 * $attempt : 10);
            }
        }

        // The left operand wins with "+": the unavailability must come first, or parse('') would
        // label an outage as an unparseable answer.
        return ['raw' => null, 'error' => 'judge_unavailable: '.$lastError]
            + AnswerJudge::parse('', count((array) $record['key_facts']), count((array) $record['forbidden_facts']));
    }

    private function pace(float $started, int $estimatedTokens, int $tokensPerMinute): void
    {
        $minimumSeconds = $estimatedTokens / $tokensPerMinute * 60;
        $remaining = $minimumSeconds - (microtime(true) - $started);

        if ($remaining > 0) {
            usleep((int) ($remaining * 1_000_000));
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>|null  $verdict
     * @return array<string, mixed>
     */
    private function verdict(array $row, array $record, ?array $verdict, bool $useLlm): array
    {
        $excluded = $record['infrastructure_error'] !== null
            || ($verdict !== null && $verdict['error'] !== null);
        $behavior = $record['behavior'];
        $answered = $record['refused'] === false;
        $judgeBehavior = $verdict['behavior'] ?? null;

        // A refusal is always an acceptable way to decline; an answer that declines, or says the
        // point is not documented, is too. On an answerable question only an answer counts.
        $behaviorOk = match ($behavior) {
            'answer' => $answered && ($judgeBehavior === null || $judgeBehavior === 'answers'),
            default => ! $answered || in_array($judgeBehavior, ['declines', 'acknowledges_gap'], true)
                || ($judgeBehavior === null && ! $useLlm),
        };
        $failures = [];

        if (! $behaviorOk) {
            $failures[] = $behavior === 'answer'
                ? ($answered ? 'does_not_answer:'.$judgeBehavior : 'unexpected_refusal')
                : 'should_have_declined';
        }

        $faithful = null;
        $complete = null;

        if ($verdict !== null && $verdict['error'] === null) {
            $faithful = $verdict['unsupported'] === 0 && $verdict['contradicted'] === 0;

            if (! $faithful) {
                $failures[] = $verdict['contradicted'] > 0 ? 'contradicted_claims' : 'unsupported_claims';
            }

            if ($verdict['forbidden_present'] !== []) {
                $failures[] = 'forbidden_fact';
            }

            // On the judge's 1-5 scales, 2 or less means mostly off-topic or mostly padding: a
            // refusal followed by unrelated documentation is not a good decline.
            if ($verdict['relevance'] !== null && $verdict['relevance'] <= 2) {
                $failures[] = 'off_topic';
            }

            if ($verdict['concision'] !== null && $verdict['concision'] <= 2) {
                $failures[] = 'padded';
            }
        }

        if ($behavior === 'answer') {
            $recall = $answered ? ($verdict['key_fact_recall'] ?? null) : (($record['key_facts'] ?? []) === [] ? null : 0.0);
            $complete = $recall === null ? null : $recall >= self::COMPLETENESS_THRESHOLD;

            if ($complete === false && $answered) {
                $failures[] = 'incomplete';
            }
        } else {
            $recall = null;
        }

        if (! $row['length_ok']) {
            $failures[] = 'too_long';
        }

        foreach ($row['hygiene'] as $violation) {
            $failures[] = 'hygiene:'.$violation;
        }

        return $row + [
            'excluded' => $excluded,
            'pass' => ! $excluded && $failures === [],
            'failures' => $failures,
            'behavior_ok' => $behaviorOk,
            'faithful' => $faithful,
            'faithfulness' => $verdict['faithfulness'] ?? null,
            'claims' => $verdict['claims_total'] ?? null,
            'unsupported' => $verdict['unsupported'] ?? null,
            'contradicted' => $verdict['contradicted'] ?? null,
            'key_fact_recall' => $recall,
            'complete' => $complete,
            'forbidden_present' => $verdict['forbidden_present'] ?? [],
            'judge_behavior' => $judgeBehavior,
            'relevance' => $verdict['relevance'] ?? null,
            'concision' => $verdict['concision'] ?? null,
            'judge_comment' => $verdict['comment'] ?? null,
            'judge_warnings' => $verdict['warnings'] ?? [],
            'judge_error' => $verdict['error'] ?? null,
            'flagged_claims' => array_values(array_filter(
                $verdict['claims'] ?? [],
                static fn (array $claim): bool => $claim['verdict'] !== 'supported',
            )),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows  Scored rows (exclusions removed).
     * @return array<string, mixed>
     */
    private function summary(array $rows, int $total): array
    {
        $n = count($rows);
        $count = static fn (callable $filter): int => count(array_filter($rows, $filter));
        $rate = static fn (int $k, int $of): ?float => $of > 0 ? round($k / $of, 4) : null;
        $pass = $count(static fn (array $row): bool => $row['pass']);
        $behaviorOk = $count(static fn (array $row): bool => $row['behavior_ok']);
        $answerable = array_values(array_filter($rows, static fn (array $row): bool => $row['behavior'] === 'answer'));
        $refusedAnswerable = count(array_filter($answerable, static fn (array $row): bool => $row['refused'] === true));
        $judged = array_values(array_filter($rows, static fn (array $row): bool => $row['faithful'] !== null));
        $faithful = count(array_filter($judged, static fn (array $row): bool => $row['faithful']));
        $answered = array_values(array_filter($rows, static fn (array $row): bool => $row['refused'] === false));
        $lengthOk = count(array_filter($answered, static fn (array $row): bool => $row['length_ok']));
        $typed = array_values(array_filter($answered, static fn (array $row): bool => in_array($row['type'], [GenerationPolicy::FACT, GenerationPolicy::PROCEDURE, GenerationPolicy::DIAGNOSTIC], true)));
        $typeAgreement = count(array_filter($typed, static fn (array $row): bool => $row['answer_type'] === $row['type']));
        $withRecall = array_values(array_filter($answerable, static fn (array $row): bool => $row['key_fact_recall'] !== null));

        return [
            'cases' => $total,
            'scored' => $n,
            'excluded' => $total - $n,
            'pass' => $pass,
            'pass_rate' => $rate($pass, $n),
            'pass_ci95' => EvalStatistics::wilson($pass, $n),
            'behavior_ok_rate' => $rate($behaviorOk, $n),
            'answerable' => count($answerable),
            'answerable_refusals' => $refusedAnswerable,
            'answerable_refusal_rate' => $rate($refusedAnswerable, count($answerable)),
            'answerable_refusal_ci95' => EvalStatistics::wilson($refusedAnswerable, count($answerable)),
            'judged' => count($judged),
            'faithful_rate' => $rate($faithful, count($judged)),
            'faithful_ci95' => EvalStatistics::wilson($faithful, count($judged)),
            'faithfulness' => EvalStatistics::mean(array_values(array_filter(array_column($judged, 'faithfulness'), static fn ($v): bool => $v !== null))),
            'unsupported_claims' => array_sum(array_map(static fn (array $row): int => (int) $row['unsupported'], $judged)),
            'contradicted_claims' => array_sum(array_map(static fn (array $row): int => (int) $row['contradicted'], $judged)),
            'forbidden_fact_cases' => $count(static fn (array $row): bool => $row['forbidden_present'] !== []),
            'key_fact_recall' => EvalStatistics::mean(array_column($withRecall, 'key_fact_recall')),
            'complete_rate' => $rate(count(array_filter($withRecall, static fn (array $row): bool => $row['complete'] === true)), count($withRecall)),
            'length_ok_rate' => $rate($lengthOk, count($answered)),
            'mean_words' => EvalStatistics::mean(array_column($answered, 'words')),
            'relevance' => EvalStatistics::mean(array_values(array_filter(array_column($judged, 'relevance'), static fn ($v): bool => $v !== null))),
            'concision' => EvalStatistics::mean(array_values(array_filter(array_column($judged, 'concision'), static fn ($v): bool => $v !== null))),
            'answer_type_agreement' => $rate($typeAgreement, count($typed)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function compare(string $baselineFile, array $rows): array
    {
        $path = is_file($baselineFile) ? $baselineFile : base_path($baselineFile);
        $baseline = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($baseline) || ($baseline['kind'] ?? null) !== 'answer-eval-judgement') {
            $this->warn("Baseline ignored: {$baselineFile} is not an answer-eval judgement.");

            return ['baseline' => $baselineFile, 'error' => 'not_a_judgement'];
        }

        $pass = static fn (array $list): array => collect($list)
            ->reject(static fn (array $row): bool => $row['excluded'])
            ->mapWithKeys(static fn (array $row): array => [(string) $row['id'] => (bool) $row['pass']])
            ->all();

        return ['baseline' => $baselineFile, 'baseline_run' => $baseline['run_id'] ?? null]
            + EvalStatistics::pairedComparison($pass($baseline['rows'] ?? []), $pass($rows));
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function markdown(array $report): string
    {
        $meta = $report['run_metadata'];
        $s = $report['summary'];
        $judge = $report['judge'];
        $ci = static fn (?array $interval): string => $interval === null ? '' : ' ('.round($interval['low'] * 100, 1).'-'.round($interval['high'] * 100, 1).'%)';
        $lines = [
            '# Answer evaluation',
            '',
            'Answers judged: '.(($report['variant'] ?? 'final') === 'draft' ? '**generator drafts, without AnswerVerifier**' : 'final answers').'  ',
            "Run: {$report['run_id']} (".(($meta['validity']['valid'] ?? false) ? 'valid' : '**invalid**')."), judged {$report['judged_at']}  ",
            'Endpoint: '.($meta['endpoint'] ?? '?').', split '.($meta['split'] ?? '?').', dataset v'.($meta['dataset']['version'] ?? '?')
                .', commit '.substr((string) ($meta['git']['commit'] ?? '?'), 0, 10).' ('.($meta['git']['dirty_files'] ?? '?').' modified files)  ',
            'Generator: '.($meta['generator']['model'] ?? '?').', top_k '.($meta['retrieval']['top_k'] ?? '?')
                .', knowledge base '.($meta['knowledge_base']['fingerprint'] ?? '?').'  ',
            'Judge: '.($judge === null ? 'none (deterministic checks only)' : "{$judge['provider']} {$judge['model']}, temperature 0, seed {$judge['seed']}"
                .(($judge['validity']['valid'] ?? false) ? '' : ' -- **judge run invalid**')),
            '',
            '| Metric | Value |',
            '|---|---:|',
            "| Cases scored | {$s['scored']} / {$s['cases']} ({$s['excluded']} excluded) |",
            '| **Pass** | '.$this->percent($s['pass_rate']).$ci($s['pass_ci95']).' |',
            '| Expected behaviour | '.$this->percent($s['behavior_ok_rate']).' |',
            '| Refusals on answerable questions | '."{$s['answerable_refusals']} / {$s['answerable']} ".$ci($s['answerable_refusal_ci95']).' |',
            '| Faithful answers (no unsupported/contradicted claim) | '.$this->percent($s['faithful_rate']).$ci($s['faithful_ci95'])." of {$s['judged']} judged |",
            '| Mean claim faithfulness | '.$this->percent($s['faithfulness']).' |',
            "| Unsupported / contradicted claims | {$s['unsupported_claims']} / {$s['contradicted_claims']} |",
            "| Cases asserting a forbidden fact | {$s['forbidden_fact_cases']} |",
            '| Key-fact recall (refusals count 0) | '.$this->percent($s['key_fact_recall']).' |',
            '| Complete answers (recall >= '.self::COMPLETENESS_THRESHOLD.') | '.$this->percent($s['complete_rate']).' |',
            '| Within length budget (target x '.self::LENGTH_TOLERANCE.') | '.$this->percent($s['length_ok_rate'])." (mean {$s['mean_words']} words) |",
            '| Relevance / concision (1-5) | '.($s['relevance'] ?? 'N/A').' / '.($s['concision'] ?? 'N/A').' |',
            '| Answer-type classification agreement | '.$this->percent($s['answer_type_agreement']).' |',
            '',
            '## By type',
            '',
            '| Type | Scored | Pass | Refusals (answerable) | Faithful | Key-fact recall | Length ok |',
            '|---|---:|---:|---:|---:|---:|---:|',
        ];

        foreach ($report['by_type'] as $type => $t) {
            $lines[] = "| {$type} | {$t['scored']} | ".$this->percent($t['pass_rate'])." | {$t['answerable_refusals']}/{$t['answerable']} | "
                .$this->percent($t['faithful_rate']).' | '.$this->percent($t['key_fact_recall']).' | '.$this->percent($t['length_ok_rate']).' |';
        }

        $lines[] = '';
        $lines[] = '## Cases';
        $lines[] = '';

        foreach ($report['rows'] as $row) {
            $status = $row['excluded'] ? 'EXCLUDED ('.($row['infrastructure_error'] ?? $row['judge_error']).')' : ($row['pass'] ? 'pass' : 'FAIL: '.implode(', ', $row['failures']));
            $lines[] = "- **{$row['id']}** ({$row['type']}, {$row['behavior']}) {$status}"
                .($row['words'] !== null ? " -- {$row['words']}/{$row['word_budget']} words" : '')
                .($row['key_fact_recall'] !== null ? ', key facts '.$this->percent($row['key_fact_recall']) : '')
                .($row['judge_comment'] ? ' -- '.$row['judge_comment'] : '');

            foreach ($row['flagged_claims'] as $claim) {
                $lines[] = "  - {$claim['verdict']}: {$claim['claim']}";
            }
        }

        if (($comparison = $report['comparison']) !== null && ! isset($comparison['error'])) {
            $lines[] = '';
            $lines[] = '## Comparison with baseline';
            $lines[] = '';
            $lines[] = "Baseline run {$comparison['baseline_run']}: {$comparison['paired']} paired cases; pass only in baseline "
                .json_encode($comparison['only_before']).', only now '.json_encode($comparison['only_after'])
                ."; exact McNemar p = {$comparison['p_value']}.";
        }

        return implode("\n", $lines)."\n";
    }

    private function percent(?float $value): string
    {
        return $value === null ? 'N/A' : round($value * 100, 1).'%';
    }
}
