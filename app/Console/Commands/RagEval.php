<?php

namespace App\Console\Commands;

use App\Services\Assistant\AssistantService;
use App\Services\Rag\EvalDatasetExpander;
use App\Services\Rag\PaymentScopeResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JsonException;

class RagEval extends Command
{
    protected $signature = 'assistant:eval
        {--file=database/seeders/eval_questions.json : Reference dataset}
        {--format=both : markdown, json or both}
        {--routing-only : Evaluate gateway scope routing without invoking the assistant or an LLM}
        {--fail-on-target : Return a failing exit code when quality targets are not met}
        {--throttle-ms=3000 : Pause between assistant calls, to stay under LLM provider burst rate limits}';

    protected $aliases = ['rag:eval'];

    protected $description = 'Evaluate grounded answers, invalid-link errors, code safety and correct escalation.';

    public function handle(
        AssistantService $assistant,
        PaymentScopeResolver $paymentScopeResolver,
        EvalDatasetExpander $expander,
    ): int {
        $file = base_path((string) $this->option('file'));
        $format = (string) $this->option('format');

        if (! File::exists($file) || ! in_array($format, ['markdown', 'json', 'both'], true)) {
            $this->error(! File::exists($file) ? "Eval file not found: {$file}" : 'Format must be markdown, json or both.');

            return self::INVALID;
        }

        try {
            $payload = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Invalid evaluation JSON: '.$exception->getMessage());

            return self::INVALID;
        }

        $items = $expander->expand($payload);

        if ($this->option('routing-only')) {
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => ($item['category'] ?? null) === 'gateway_routing',
            ));
        }
        $answerableTotal = 0;
        $truthful = 0;
        $answerErrors = 0;
        $expectedEscalations = 0;
        $correctEscalations = 0;
        $policyTotal = 0;
        $policyPassed = 0;
        $codeViolations = 0;
        $officialLinkPasses = 0;
        $officialLinkChecks = 0;
        $invalidLinkResponses = 0;
        $documentationCorpus = (string) ($payload['documentation_corpus_payment_method']
            ?? config('rag.documentation_corpus_payment_method', 'PVIT'));
        $requiredGatewayContexts = array_values(array_unique(
            $payload['required_gateway_payment_contexts']
                ?? config('rag.gateway_payment_contexts', []),
        ));
        $coveredGatewayContexts = [];
        $corpusGroundingChecks = 0;
        $corpusGroundingPasses = 0;
        $gatewayRoutingTotal = 0;
        $gatewayRoutingPassed = 0;
        $rows = [];
        $throttleMs = max(0, (int) $this->option('throttle-ms'));

        foreach ($items as $index => $item) {
            $category = $item['category'] ?? ((bool) ($item['should_escalate'] ?? false) ? 'escalation' : 'answerable');
            $requestedPaymentMethod = $item['moyen_paiement'] ?? null;
            $expectedCorpus = (string) ($item['expected_documentation_corpus_payment_method'] ?? $documentationCorpus);

            if (is_string($requestedPaymentMethod)
                && in_array($requestedPaymentMethod, $requiredGatewayContexts, true)) {
                $coveredGatewayContexts[] = $requestedPaymentMethod;
            }

            if ($category === 'gateway_routing') {
                $gatewayRoutingTotal++;
                $scope = $paymentScopeResolver->resolve(
                    (string) $item['question'],
                    is_string($requestedPaymentMethod) ? $requestedPaymentMethod : null,
                );
                $truthy = $scope['error'] === null
                    && $scope['requested_payment_method'] === $requestedPaymentMethod
                    && $scope['corpus_payment_method'] === $expectedCorpus;

                if ($truthy) {
                    $gatewayRoutingPassed++;
                }

                $rows[] = [
                    'id' => $item['id'] ?? $index + 1,
                    'category' => $category,
                    'passed' => $truthy,
                    'confidence' => null,
                    'status' => $truthy ? 'scope_resolved' : 'scope_rejected',
                    'reason' => $scope['error'],
                    'should_escalate' => false,
                    'requested_payment_method' => $requestedPaymentMethod,
                    'expected_documentation_corpus_payment_method' => $expectedCorpus,
                    'corpus_grounded' => $scope['corpus_payment_method'] === $expectedCorpus,
                    'failures' => $truthy ? [] : ['gateway_scope_mismatch'],
                ];

                continue;
            }

            $result = $assistant->ask($item['question'], null, [
                'moyen_paiement' => $item['moyen_paiement'] ?? null,
                'operation' => $item['operation'] ?? null,
            ], persist: false);

            if ($throttleMs > 0) {
                // The default (groq) and fallback (gemini) providers share a tight burst rate
                // limit; calling the assistant back-to-back for a whole dataset reliably trips
                // both at once mid-run, which then reads as a truth-rate regression when it is
                // really just LLM unavailability. Pacing calls keeps the eval measuring answer
                // quality instead of provider throughput.
                usleep($throttleMs * 1000);
            }

            $answer = $this->normalizeForEvaluation((string) $result['answer']);
            $failures = [];
            $noCode = ! (bool) $result['contains_code'];
            $linksAreValid = $result['links'] === [] || $this->hasOnlyOfficialLinks($result['links']);
            $hasExpectedCorpus = null;

            if ($result['sources'] !== []) {
                $corpusGroundingChecks++;
                $hasExpectedCorpus = collect($result['sources'])->every(
                    fn (array $source): bool => Str::lower((string) ($source['documentation_corpus_payment_method'] ?? ''))
                        === Str::lower($expectedCorpus),
                );

                if ($hasExpectedCorpus) {
                    $corpusGroundingPasses++;
                } else {
                    $failures[] = 'unexpected_documentation_corpus';
                }
            }

            if (! $linksAreValid) {
                $invalidLinkResponses++;
                $failures[] = 'invalid_official_link';
            }

            if (! $noCode) {
                $codeViolations++;
                $failures[] = 'code_detected';
            }

            $mustInclude = $item['must_include'] ?? [];
            $hasExpectedTerms = collect($mustInclude)->every(
                fn (string $term): bool => str_contains($answer, $this->normalizeForEvaluation($term)),
            );
            $hasForbiddenTerms = collect($item['must_not_include'] ?? [])->contains(
                fn (string $term): bool => str_contains($answer, $this->normalizeForEvaluation($term)),
            );

            if (! $hasExpectedTerms) {
                $failures[] = 'expected_terms_missing';
            }

            if ($hasForbiddenTerms) {
                $failures[] = 'forbidden_term_present';
            }

            if ($category === 'answerable') {
                $answerableTotal++;
                $officialLinkChecks++;
                $hasSources = $result['sources'] !== [];
                $hasOfficialLinks = $this->hasOnlyOfficialLinks($result['links']);
                // Listing more than one document means the docs site itself now covers the same
                // topic from more than one page (e.g. an installation tutorial and a plugin
                // reference) -- either being cited is a correct answer, not a requirement to cite
                // both at once.
                $expectedDocuments = $item['expected_source_documents'] ?? [];
                $hasExpectedDocuments = $expectedDocuments === [] || collect($expectedDocuments)->contains(
                    fn (string $document): bool => collect($result['sources'])->contains(
                        fn (array $source): bool => Str::lower($source['document']) === Str::lower($document),
                    ),
                );
                $truthy = $hasExpectedTerms
                    && ! $hasForbiddenTerms
                    && $noCode
                    && ($result['status'] ?? null) === 'answered'
                    && ($result['reason'] ?? null) === 'grounded_answer'
                    && ! $result['should_escalate']
                    && $hasSources
                    && $hasOfficialLinks
                    && $hasExpectedDocuments
                    && $hasExpectedCorpus === true;

                if ($hasOfficialLinks) {
                    $officialLinkPasses++;
                } else {
                    $failures[] = 'official_link_missing_or_invalid';
                }

                if (! $hasSources) {
                    $failures[] = 'source_missing';
                }

                if (! $hasExpectedDocuments) {
                    $failures[] = 'expected_source_missing';
                }

                if ($hasExpectedCorpus !== true) {
                    $failures[] = 'expected_documentation_corpus_missing';
                }

                if ($result['should_escalate']) {
                    $failures[] = 'unexpected_escalation';
                }

                $truthy ? $truthful++ : $answerErrors++;
            } elseif ($category === 'policy') {
                $policyTotal++;
                $expectedStatus = (string) ($item['expected_status'] ?? 'refused_code');
                $truthy = $noCode
                    && $linksAreValid
                    && $hasExpectedCorpus !== false
                    && ($result['status'] ?? null) === $expectedStatus
                    && $hasExpectedTerms
                    && ! $hasForbiddenTerms;
                $truthy ? $policyPassed++ : $failures[] = 'policy_decision_mismatch';
            } else {
                $expectedEscalations++;
                $truthy = $noCode
                    && $linksAreValid
                    && $hasExpectedCorpus !== false
                    && $result['should_escalate']
                    && $hasExpectedTerms
                    && ! $hasForbiddenTerms;

                if ($truthy) {
                    $correctEscalations++;
                } elseif (! $result['should_escalate']) {
                    $failures[] = 'expected_escalation_missing';
                }
            }

            $rows[] = [
                'id' => $item['id'] ?? $index + 1,
                'category' => $category,
                'passed' => $truthy,
                'confidence' => $result['confidence'] ?? null,
                'status' => $result['status'] ?? null,
                'reason' => $result['reason'] ?? null,
                'should_escalate' => $result['should_escalate'],
                'requested_payment_method' => $requestedPaymentMethod,
                'expected_documentation_corpus_payment_method' => $expectedCorpus,
                'corpus_grounded' => $hasExpectedCorpus,
                'failures' => array_values(array_unique($failures)),
            ];
        }

        $truthRate = $answerableTotal > 0 ? round($truthful / $answerableTotal * 100, 2) : null;
        $errorRate = $answerableTotal > 0 ? round($answerErrors / $answerableTotal * 100, 2) : null;
        $escalationRate = $expectedEscalations > 0 ? round($correctEscalations / $expectedEscalations * 100, 2) : null;
        $policyRate = $policyTotal > 0 ? round($policyPassed / $policyTotal * 100, 2) : null;
        $officialLinkCoverage = $officialLinkChecks > 0 ? round($officialLinkPasses / $officialLinkChecks * 100, 2) : null;
        $coveredGatewayContexts = array_values(array_intersect(
            $requiredGatewayContexts,
            array_values(array_unique($coveredGatewayContexts)),
        ));
        $gatewayContextCoverage = $requiredGatewayContexts === []
            ? null
            : round(count($coveredGatewayContexts) / count($requiredGatewayContexts) * 100, 2);
        $corpusGroundingCoverage = $corpusGroundingChecks > 0
            ? round($corpusGroundingPasses / $corpusGroundingChecks * 100, 2)
            : null;
        $gatewayRoutingRate = $gatewayRoutingTotal > 0
            ? round($gatewayRoutingPassed / $gatewayRoutingTotal * 100, 2)
            : null;
        $targetsMet = $truthRate !== null
            && $truthRate >= 98
            && $errorRate < 2
            && ($escalationRate === null || $escalationRate === 100.0)
            && ($policyRate === null || $policyRate === 100.0)
            && $officialLinkCoverage === 100.0
            && ($gatewayContextCoverage === null || $gatewayContextCoverage === 100.0)
            && ($gatewayRoutingRate === null || $gatewayRoutingRate === 100.0)
            && $corpusGroundingCoverage === 100.0
            && $invalidLinkResponses === 0
            && $codeViolations === 0;

        $report = [
            'generated_at' => now()->toIso8601String(),
            'mode' => $this->option('routing-only') ? 'gateway_routing_only' : 'full',
            'dataset_note' => $payload['note'] ?? null,
            'total' => count($items),
            'answerable_total' => $answerableTotal,
            'truth_rate' => $truthRate,
            'error_rate' => $errorRate,
            'expected_escalations' => $expectedEscalations,
            'correct_escalation_rate' => $escalationRate,
            'policy_total' => $policyTotal,
            'policy_pass_rate' => $policyRate,
            'official_link_coverage' => $officialLinkCoverage,
            'invalid_link_responses' => $invalidLinkResponses,
            'documentation_corpus_payment_method' => $documentationCorpus,
            'required_gateway_payment_contexts' => $requiredGatewayContexts,
            'covered_gateway_payment_contexts' => $coveredGatewayContexts,
            'gateway_payment_context_coverage' => $gatewayContextCoverage,
            'gateway_routing_total' => $gatewayRoutingTotal,
            'gateway_routing_pass_rate' => $gatewayRoutingRate,
            'corpus_grounding_checks' => $corpusGroundingChecks,
            'corpus_grounding_coverage' => $corpusGroundingCoverage,
            'code_violations' => $codeViolations,
            'target_truth_rate' => 98,
            'target_error_rate' => 2,
            'targets_met' => $targetsMet,
            'rows' => $rows,
        ];

        File::ensureDirectoryExists(storage_path('app/eval-reports'));
        $baseTarget = storage_path('app/eval-reports/assistant-eval-'.now()->format('Ymd-His'));
        $targets = [];

        if (in_array($format, ['json', 'both'], true)) {
            $targets[] = $target = $baseTarget.'.json';
            File::put($target, json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));
        }

        if (in_array($format, ['markdown', 'both'], true)) {
            $targets[] = $target = $baseTarget.'.md';
            File::put($target, $this->markdown($report));
        }

        foreach ($targets as $target) {
            $this->info("Report written to {$target}");
        }
        $this->line('Truth rate: '.$this->percent($truthRate));
        $this->line('Error rate: '.$this->percent($errorRate));
        $this->line('Correct escalation rate: '.$this->percent($escalationRate));
        $this->line('Targets met: '.($targetsMet ? 'yes' : 'no'));

        return $this->option('fail-on-target') && ! $targetsMet ? self::FAILURE : self::SUCCESS;
    }

    private function hasOnlyOfficialLinks(array $links): bool
    {
        if ($links === []) {
            return false;
        }

        $allowedHosts = array_map('strtolower', (array) config('rag.allowed_source_hosts', []));

        return collect($links)->every(function (string $link) use ($allowedHosts): bool {
            $parts = parse_url($link);

            return filter_var($link, FILTER_VALIDATE_URL) !== false
                && is_array($parts)
                && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
                && ! isset($parts['user'])
                && ! isset($parts['pass'])
                && (! isset($parts['port']) || (int) $parts['port'] === 443)
                && in_array(strtolower((string) ($parts['host'] ?? '')), $allowedHosts, true);
        });
    }

    private function markdown(array $report): string
    {
        $status = $report['targets_met'] ? 'PASS' : 'NOT MET';

        return "# RAG evaluation report\n\n"
            ."Generated: {$report['generated_at']}\n\n"
            ."Status: **{$status}**\n\n"
            ."| Metric | Value | Target |\n"
            ."|---|---:|---:|\n"
            .'| Truth rate | '.$this->percent($report['truth_rate'])." | >= 98% |\n"
            .'| Error rate | '.$this->percent($report['error_rate'])." | < 2% |\n"
            .'| Correct escalation rate | '.$this->percent($report['correct_escalation_rate'])." | 100% |\n"
            .'| Policy pass rate | '.$this->percent($report['policy_pass_rate'])." | 100% |\n"
            .'| Official-link coverage | '.$this->percent($report['official_link_coverage'])." | 100% |\n"
            .'| Invalid links in any response | '.$report['invalid_link_responses']." | 0 |\n"
            .'| Gateway payment-context coverage | '.$this->percent($report['gateway_payment_context_coverage'])." | 100% |\n"
            .'| Gateway routing pass rate | '.$this->percent($report['gateway_routing_pass_rate'])." | 100% |\n"
            .'| Responses grounded in the PVIT corpus | '.$this->percent($report['corpus_grounding_coverage'])." | 100% |\n"
            ."| Code violations | {$report['code_violations']} | 0 |\n\n"
            ."Total questions: {$report['total']}  \n"
            ."Answerable reference questions: {$report['answerable_total']}  \n"
            ."Expected escalations: {$report['expected_escalations']}  \n"
            ."Gateway routing cases (no LLM call): {$report['gateway_routing_total']}  \n"
            ."Policy cases: {$report['policy_total']}\n\n"
            ."> A truth rate is reported only for answerable cases backed by human-reviewed expected facts. Escalation-only placeholders are measured separately.\n";
    }

    private function percent(?float $value): string
    {
        return $value === null ? 'N/A' : $value.'%';
    }

    private function normalizeForEvaluation(string $text): string
    {
        $text = Str::ascii(Str::lower($text));
        $text = (string) preg_replace('/\be[\s-]+mail\b/u', 'email', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

}
