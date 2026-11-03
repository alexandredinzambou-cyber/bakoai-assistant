<?php

namespace App\Console\Commands;

use App\Http\Controllers\MistralRawController;
use App\Services\Assistant\AnswerQualityEvaluator;
use App\Services\Assistant\AnswerQualityVerdict;
use App\Services\Assistant\AnswerVerifier;
use App\Services\Assistant\AssistantService;
use App\Services\Assistant\GreetingDetector;
use App\Services\Assistant\PromptInjectionGuard;
use App\Services\Assistant\ResponseGuard;
use App\Services\Evaluation\EvalRunMetadata;
use App\Services\Evaluation\EvalStatistics;
use App\Services\Llm\OpenAiCompatibleLlmClient;
use App\Services\Rag\RetrievalService;
use Illuminate\Console\Command;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use JsonException;
use Throwable;

class QualityEval extends Command
{
    protected $signature = 'assistant:quality-eval
        {--file=database/seeders/eval_quality_questions.json : Quality cases dataset}
        {--target= : Only judge this target (mistral_raw or assistant)}
        {--case= : Only judge this case id (e.g. concision-002)}
        {--format=both : markdown, json or both}
        {--fail-on-target : Return a failing exit code when quality targets are not met}
        {--throttle-ms=3000 : Pause between live LLM calls, to stay under provider rate limits}';

    protected $description = 'Ask live questions to mistral-raw (and /assistant), then judge each answer with the deterministic quality rubric: concision by question shape, expected/forbidden terms, no code, no raw citation marker, official https sources.';

    private const TARGET_PASS_RATE = 90.0;

    private const HYGIENE_VIOLATIONS = ['code_detected', 'raw_citation_marker', 'unofficial_source_link', 'empty_answer'];

    public function handle(
        AnswerQualityEvaluator $evaluator,
        MistralRawController $mistralRaw,
        AssistantService $assistant,
        RetrievalService $retrieval,
        OpenAiCompatibleLlmClient $llm,
        PromptInjectionGuard $promptInjectionGuard,
        ResponseGuard $responseGuard,
        GreetingDetector $greetingDetector,
        AnswerVerifier $verifier,
        EvalRunMetadata $metadata,
    ): int {
        $file = base_path((string) $this->option('file'));
        $format = (string) $this->option('format');

        if (! File::exists($file) || ! in_array($format, ['markdown', 'json', 'both'], true)) {
            $this->error(! File::exists($file) ? "Quality eval file not found: {$file}" : 'Format must be markdown, json or both.');

            return self::INVALID;
        }

        try {
            $payload = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Invalid quality eval JSON: '.$exception->getMessage());

            return self::INVALID;
        }

        $defaultTarget = (string) ($payload['default_target'] ?? 'mistral_raw');
        $targetFilter = (string) $this->option('target');
        $caseFilter = (string) $this->option('case');
        $cases = array_values(array_filter(
            (array) $payload['cases'],
            static fn (array $case): bool => ($targetFilter === '' || (string) ($case['target'] ?? $defaultTarget) === $targetFilter)
                && ($caseFilter === '' || (string) ($case['id'] ?? '') === $caseFilter),
        ));

        if ($cases === []) {
            $this->error('No quality cases matched the requested target.');

            return self::INVALID;
        }

        $targets = array_map(static fn (array $case): string => (string) ($case['target'] ?? $defaultTarget), $cases);
        $mistralConfigured = filled(config('llm.providers.mistral.api_key'))
            && filled(config('llm.providers.mistral.model'));
        $assistantConfigured = filled(config('llm.providers.'.config('llm.default_provider', 'ngrok').'.api_key'));

        if (in_array('mistral_raw', $targets, true) && ! $mistralConfigured) {
            $this->error('MISTRAL_API_KEY / MISTRAL_MODEL are not configured; cannot run live mistral_raw cases.');

            return self::FAILURE;
        }

        if (in_array('assistant', $targets, true) && ! $assistantConfigured) {
            $this->error('The default LLM provider is not configured; cannot run live assistant cases.');

            return self::FAILURE;
        }

        $throttleMs = max(0, (int) $this->option('throttle-ms'));
        $rows = [];

        foreach ($cases as $index => $case) {
            $target = (string) ($case['target'] ?? $defaultTarget);
            $this->line(sprintf('(%d/%d) [%s] %s', $index + 1, count($cases), $target, $case['question']));

            $rows[] = $target === 'assistant'
                ? $this->judgeAssistant($evaluator, $assistant, $case)
                : $this->judgeMistralRaw($evaluator, $mistralRaw, $retrieval, $llm, $promptInjectionGuard, $responseGuard, $greetingDetector, $verifier, $case);

            // The mistral-raw controller sets a 120 s limit meant for one web request; on
            // Windows it counts wall time for the whole CLI run, throttling included.
            set_time_limit(0);
            $judged = end($rows);
            $this->line($judged['passed'] ? '  <fg=green>PASS</>' : '  <fg=red>FAIL</> '.implode(', ', $judged['violations']));

            if ($throttleMs > 0 && $index < count($cases) - 1) {
                usleep($throttleMs * 1000);
            }
        }

        // A case lost to an outage (provider down, rate limit) says nothing about answer quality:
        // it is left out of the pass rate, and more than 5% of them invalidates the whole run.
        $infrastructure = array_count_values(array_values(array_filter(array_column($rows, 'infrastructure'))));
        $validity = EvalRunMetadata::validity(count($rows), $infrastructure);
        $scored = array_values(array_filter($rows, static fn (array $row): bool => $row['infrastructure'] === null));
        $total = count($scored);
        $passed = count(array_filter($scored, static fn (array $row): bool => $row['passed']));
        $passRate = $total > 0 ? round($passed / $total * 100, 2) : 0.0;
        $hygieneFailures = count(array_filter($scored, static fn (array $row): bool => count(array_intersect(
            $row['violations'],
            self::HYGIENE_VIOLATIONS,
        )) > 0));
        $targetsMet = $validity['valid'] && $passRate >= self::TARGET_PASS_RATE && $hygieneFailures === 0;
        $interval = EvalStatistics::wilson($passed, $total);

        $report = [
            'generated_at' => now()->toIso8601String(),
            'metadata' => $metadata->collect($file, ['target' => $targetFilter ?: null, 'case' => $caseFilter ?: null]),
            'validity' => $validity,
            'dataset_note' => $payload['note'] ?? null,
            'total' => $total,
            'excluded_infrastructure' => count($rows) - $total,
            'passed' => $passed,
            'pass_rate' => $passRate,
            'pass_rate_ci95' => $interval === null ? null : [round($interval['low'] * 100, 1), round($interval['high'] * 100, 1)],
            'target_pass_rate' => self::TARGET_PASS_RATE,
            'hygiene_failures' => $hygieneFailures,
            'targets_met' => $targetsMet,
            'rows' => $rows,
        ];

        File::ensureDirectoryExists(storage_path('app/eval-reports'));
        $baseTarget = storage_path('app/eval-reports/quality-eval-'.now()->format('Ymd-His'));
        $written = [];

        if (in_array($format, ['json', 'both'], true)) {
            $written[] = $path = $baseTarget.'.json';
            File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        if (in_array($format, ['markdown', 'both'], true)) {
            $written[] = $path = $baseTarget.'.md';
            File::put($path, $this->markdown($report));
        }

        foreach ($written as $path) {
            $this->info("Report written to {$path}");
        }

        if (! $validity['valid']) {
            $this->error('INVALID run: '.json_encode($infrastructure).' infrastructure failures out of '.count($rows).' cases.');
        }

        $this->line("Quality pass rate: {$passRate}% ({$passed}/{$total})"
            .($interval === null ? '' : sprintf(' [95%% CI %.1f-%.1f%%]', $interval['low'] * 100, $interval['high'] * 100)));
        $this->line('Targets met: '.($targetsMet ? 'yes' : 'no'));

        return $this->option('fail-on-target') && ! $targetsMet ? self::FAILURE : self::SUCCESS;
    }

    private function judgeMistralRaw(
        AnswerQualityEvaluator $evaluator,
        MistralRawController $mistralRaw,
        RetrievalService $retrieval,
        OpenAiCompatibleLlmClient $llm,
        PromptInjectionGuard $promptInjectionGuard,
        ResponseGuard $responseGuard,
        GreetingDetector $greetingDetector,
        AnswerVerifier $verifier,
        array $case,
    ): array {
        $body = json_encode([
            'question' => (string) $case['question'],
            'moyen_paiement' => $case['moyen_paiement'] ?? null,
            'operation' => $case['operation'] ?? null,
        ], JSON_THROW_ON_ERROR);

        $request = Request::create('/api/mistral-raw/ask', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);

        try {
            /** @var JsonResponse $response */
            $response = $mistralRaw->ask($request, $retrieval, $llm, $promptInjectionGuard, $responseGuard, $greetingDetector, $verifier);
            $payload = $response->getData(true);
        } catch (Throwable $exception) {
            return $this->transportError('mistral_raw', $case, 'controller_exception: '.$exception->getMessage());
        }

        if ($response->status() === 502 || $response->status() === 429) {
            // The generator never answered (provider down, rate limit, bad key): an outage, not
            // a refusal to judge.
            $error = (string) ($payload['error'] ?? '');

            return $this->transportError('mistral_raw', $case, 'http_'.$response->status().': '.$error, $this->infrastructureReason($error, $response->status()));
        }

        if ($response->status() !== 200) {
            // A 422 refusal (injection refused, no retrieved context) is a legitimate judged
            // outcome: its message is evaluated like any other answer, as a refusal with no
            // expected sources. A transport error only has no error payload at all.
            $error = (string) ($payload['error'] ?? '');

            if ($error !== '') {
                $verdict = $this->judge($evaluator, $case, $error, [], true);

                return $this->verdictRow('mistral_raw', $case, $verdict, 'http_'.$response->status(), $error);
            }

            return $this->transportError('mistral_raw', $case, 'http_status_'.$response->status());
        }

        $answer = (string) ($payload['answer'] ?? '');
        // A code-guard refusal carries code_filtered and no citation_filtered key.
        $refused = ($payload['citation_filtered'] ?? false) === true
            || (($payload['code_filtered'] ?? false) === true && ! array_key_exists('citation_filtered', $payload));
        $verdict = $this->judge($evaluator, $case, $answer, $payload['sources'] ?? [], $refused);

        return $this->verdictRow('mistral_raw', $case, $verdict, (string) ($payload['model'] ?? ''), $answer);
    }

    private function judgeAssistant(AnswerQualityEvaluator $evaluator, AssistantService $assistant, array $case): array
    {
        try {
            $result = $assistant->ask((string) $case['question'], null, [
                'moyen_paiement' => $case['moyen_paiement'] ?? null,
                'operation' => $case['operation'] ?? null,
            ], persist: false);
        } catch (Throwable $exception) {
            return $this->transportError('assistant', $case, 'service_exception: '.$exception->getMessage());
        }

        if (in_array($result['reason'] ?? null, ['llm_unavailable', 'retrieval_unavailable'], true)) {
            return $this->transportError('assistant', $case, (string) $result['reason'], (string) $result['reason']);
        }

        $answer = (string) ($result['answer'] ?? '');
        $verdict = $this->judge($evaluator, $case, $answer, $result['sources'] ?? [], ($result['status'] ?? 'answered') !== 'answered');

        return $this->verdictRow('assistant', $case, $verdict, (string) ($result['status'] ?? ''), $answer);
    }

    private function judge(AnswerQualityEvaluator $evaluator, array $case, string $answer, array $sources, ?bool $refused = null): AnswerQualityVerdict
    {
        // The case type states the expected behavior: an answerable case that comes back as a
        // refusal fails ("unexpected_refusal") instead of passing as a hygiene-only refusal, and
        // a refusal/policy case that gets answered fails too.
        $type = (string) ($case['type'] ?? 'answerable');
        $expectedBehavior = array_key_exists('expected_behavior', $case)
            ? $case['expected_behavior']
            : match ($type) {
                'answerable' => 'answer',
                'refusal', 'policy' => 'decline',
                default => null,
            };

        return $evaluator->evaluate(
            (string) $case['question'],
            $answer,
            $this->normalizeSources($sources),
            (array) ($case['must_include'] ?? []),
            (array) ($case['must_not_include'] ?? []),
            (bool) ($case['expects_sources'] ?? true),
            isset($case['max_words']) ? (int) $case['max_words'] : null,
            $expectedBehavior,
            $refused,
        );
    }

    private function normalizeSources(array $sources): array
    {
        return array_map(
            static fn ($source): array => ['url' => (string) ($source['url'] ?? '')],
            array_values(array_filter($sources, static fn ($source): bool => is_array($source))),
        );
    }

    private function verdictRow(string $target, array $case, AnswerQualityVerdict $verdict, string $meta, string $answer): array
    {
        return [
            'id' => (string) ($case['id'] ?? $case['question']),
            'target' => $target,
            'type' => (string) ($case['type'] ?? 'answerable'),
            'question' => (string) $case['question'],
            'passed' => $verdict->passed,
            'violations' => $verdict->violations,
            'stats' => $verdict->stats,
            'meta' => $meta,
            'infrastructure' => null,
            'answer_preview' => mb_substr(trim($answer), 0, 240),
        ];
    }

    private function infrastructureReason(string $error, int $status): string
    {
        $message = strtolower($error);

        return match (true) {
            $status === 429 || str_contains($message, '429') => 'llm_rate_limited',
            str_contains($message, '401') || str_contains($message, '403') => 'llm_auth',
            default => 'llm_unavailable',
        };
    }

    private function transportError(string $target, array $case, string $reason, string $infrastructure = 'transport_error'): array
    {
        return [
            'id' => (string) ($case['id'] ?? $case['question']),
            'target' => $target,
            'type' => (string) ($case['type'] ?? 'answerable'),
            'question' => (string) $case['question'],
            'passed' => false,
            'violations' => ['transport_error:'.$reason],
            'stats' => ['words' => 0, 'max_words' => null, 'sources' => 0],
            'meta' => '',
            'infrastructure' => $infrastructure,
            'answer_preview' => '',
        ];
    }

    private function markdown(array $report): string
    {
        $status = $report['targets_met'] ? 'PASS' : 'NOT MET';
        $lines = [
            '# Answer quality evaluation report',
            '',
            'Generated: '.$report['generated_at'],
            '',
            'Status: **'.$status.'**'.($report['validity']['valid'] ? '' : ' -- **INVALID run** ('
                .json_encode($report['validity']['infrastructure_failures']).' infrastructure failures)'),
            '',
            'Commit '.substr((string) ($report['metadata']['git']['commit'] ?? '?'), 0, 10)
                .' ('.($report['metadata']['git']['dirty_files'] ?? '?').' modified files), generator '
                .$report['metadata']['generator']['model'].', top_k '.$report['metadata']['retrieval']['top_k']
                .', knowledge base '.($report['metadata']['knowledge_base']['fingerprint'] ?? '?'),
            '',
            '| Metric | Value | Target |',
            '|---|---:|---:|',
            '| Quality pass rate | '.$report['pass_rate'].'%'
                .($report['pass_rate_ci95'] === null ? '' : ' (95% CI '.$report['pass_rate_ci95'][0].'-'.$report['pass_rate_ci95'][1].'%)')
                .' on '.$report['total'].' cases | >= '.$report['target_pass_rate'].'% |',
            '| Cases excluded (outage) | '.$report['excluded_infrastructure'].' | 0 |',
            '| Hygiene failures | '.$report['hygiene_failures'].' | 0 |',
            '',
            '| Case | Target | Type | Result | Words/Cap | Violations |',
            '|---|---|---|---|---|---|',
        ];

        foreach ($report['rows'] as $row) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s/%s | %s |',
                $row['id'],
                $row['target'],
                $row['type'],
                $row['passed'] ? 'PASS' : 'FAIL',
                $row['stats']['words'],
                $row['stats']['max_words'] ?? '-',
                implode(', ', $row['violations']) ?: '-',
            );
        }

        return implode("\n", $lines)."\n";
    }
}
