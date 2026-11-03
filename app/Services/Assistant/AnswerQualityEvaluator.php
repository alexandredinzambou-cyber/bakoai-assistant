<?php

namespace App\Services\Assistant;

use Illuminate\Support\Str;

/**
 * Deterministic judge of a single assistant answer, built for the mistral-raw comparison page.
 * It encodes the quality bar the product owner complained about -- "a simple question gets a
 * long-winded answer" -- as a measurable rubric, alongside the invariants the guards already
 * enforce (no application source code, no raw [SOURCE:n] marker, official-only links).
 *
 * Every criterion is deterministic (string/regex/count checks) so the same (question, answer,
 * sources) triple always receives the same verdict: no LLM judge, no flaky scoring. The
 * evaluator never decides by itself whether a fact is true -- factual grounding is
 * AnswerVerifier's job; this class judges form, concision, scope and hygiene.
 */
class AnswerQualityEvaluator
{
    // Thresholds are config-driven so a report can be tightened without a code change.
    // Default cap: a short/closed question must be answerable in ~150 words -- enough for the
    // fact plus the explanations that make it usable (field meanings, header names, concrete
    // values) without rewarding a rambling answer; a diagnostic or multi-step integration
    // question earns a longer, structured answer that carries its documented steps and
    // examples end to end.
    private const SHORT_CAP = 150;

    private const LONG_CAP = 400;

    // Question-shape classifiers, mirroring the lookup/diagnostic vocabulary used by
    // AssistantService::shouldUseAssistiveSynthesis() and inferOperation().
    private const SHORT_QUESTION_PATTERN = '/^(?:que\s+(?:signifie|veut\s+dire|represente|indique)|c\s+est\s+quoi|qu\s?est-ce\s+que|quel(?:le)?\s+est\b|quels?\s+(?:sont|champs?|parametres?|codes?)\b|ou\s+trouve\b|est-ce\s+que\b|qui\s+peut\b)/iu';

    private const OPEN_QUESTION_PATTERN = '/\b(?:comment|pourquoi|etapes?|procedure|diagnostiquer|diagnostic|resoudre|integrer|integration|difference|explique|forcer|echoue|echec|erreur|erreurs|erreur\s+500|verification)\b/iu';

    // A refusal/escalation is a legitimate answer -- it is judged as hygiene-only (no code,
    // no fabricated links), not against the concision or expected-terms rubric, because the
    // exact refusal wording is a copy concern, not a quality failure.
    private const REFUSAL_MARKERS = [
        'je ne peux pas',
        "je n'ai pas pu produire",
        "je n'ai pas trouvé de passage",
        "je n'ai pas trouve de passage",
        'ouvrir un ticket',
        'ouvrez un ticket',
        'reformulez votre question',
        'reformulez en precisant',
    ];

    /**
     * @param string $question The question exactly as the user asked it.
     * @param string $answer The final displayed answer (citations already rendered).
     * @param list<array{document?: mixed, section?: mixed, url?: mixed}> $sources Server-attached sources for the answer.
     * @param list<string> $mustInclude Terms (case/accent-insensitive) that must appear in the answer.
     * @param list<string> $mustNotInclude Terms that must never appear in the answer.
     * @param bool $expectsSources Whether a grounded answer is expected to cite sources.
     * @param int|null $maxWords Override of the automatic concision cap (explicit per-case budget).
     * @param string|null $expectedBehavior 'answer' or 'decline' when the case states it: a refusal
     *        on a question the documentation answers is a failure, not a pass exempt from the rubric,
     *        and an answer to a question that had to be declined is one too.
     * @param bool|null $refused Refusal as reported by the pipeline itself (citation_filtered, a
     *        422); when null it is detected from the wording.
     */
    public function evaluate(
        string $question,
        string $answer,
        array $sources = [],
        array $mustInclude = [],
        array $mustNotInclude = [],
        bool $expectsSources = true,
        ?int $maxWords = null,
        ?string $expectedBehavior = null,
        ?bool $refused = null,
    ): AnswerQualityVerdict {
        $violations = [];
        $answerWords = $this->wordCount($answer);
        $isRefusal = $refused ?? $this->isRefusal($answer);

        if (trim($answer) === '') {
            return new AnswerQualityVerdict(false, ['empty_answer'], [
                'words' => 0,
                'max_words' => null,
                'sources' => count($sources),
            ]);
        }

        if ($expectedBehavior === 'answer' && $isRefusal) {
            $violations[] = 'unexpected_refusal';
        }

        if ($expectedBehavior === 'decline' && ! $isRefusal) {
            $violations[] = 'expected_refusal_missing';
        }

        $cap = $maxWords ?? $this->automaticCap($question);

        // Concision: the complaint that started this evaluator. A refusal is exempt (its
        // length is dictated by policy wording, not by verbosity), and an explicit per-case
        // budget always wins over the automatic classifier.
        if (! $isRefusal && $answerWords > $cap) {
            $violations[] = 'too_long';
        }

        if (! $isRefusal) {
            foreach ($mustInclude as $term) {
                // A term may carry "|"-separated alternatives (the documented field name and
                // its French wording, e.g. "montant|amount"): the answer passes when ANY of
                // them appears. The corpus mixes English identifiers with French prose, so
                // expecting a single spelling would reject a correct, better-worded answer.
                $alternatives = array_map(
                    fn (string $alternative): string => $this->normalize($alternative),
                    explode('|', $term),
                );
                $present = (bool) array_reduce(
                    $alternatives,
                    fn (bool $carry, string $alternative): bool => $carry
                        || str_contains($this->normalize($answer), $alternative),
                    false,
                );

                if (! $present) {
                    $violations[] = 'expected_term_missing:'.$term;

                    break;
                }
            }

            foreach ($mustNotInclude as $term) {
                if (str_contains($this->normalize($answer), $this->normalize($term))) {
                    $violations[] = 'forbidden_term_present:'.$term;

                    break;
                }
            }
        }

        if ($this->containsCodeShape($answer, $question)) {
            $violations[] = 'code_detected';
        }

        // A rendered answer must never leak the raw citation marker the model was asked to
        // produce: AnswerVerifier's rendering should have replaced it with a readable label.
        if (preg_match('/\[SOURCE\s*:\s*\d+\s*\]/iu', $answer) === 1) {
            $violations[] = 'raw_citation_marker';
        }

        // Note: only refusal-type cases declare expects_sources=false in the dataset. An
        // ANSWERABLE case that came back as a refusal (broken retrieval, unexpected 422) must
        // fail here -- a silent refusal must never read as a green answer.
        if ($expectsSources && $sources === []) {
            $violations[] = 'sources_missing';
        }

        foreach ($sources as $source) {
            $url = trim((string) ($source['url'] ?? ''));

            if ($url !== '' && ! $this->isOfficialHttpsLink($url)) {
                $violations[] = 'unofficial_source_link';

                break;
            }
        }

        $passed = $violations === [];

        return new AnswerQualityVerdict($passed, $violations, [
            'words' => $answerWords,
            'max_words' => $cap,
            'sources' => count($sources),
        ]);
    }

    private function automaticCap(string $question): int
    {
        $normalized = $this->normalize($question);
        $words = $this->wordCount($question);

        $isShortShape = preg_match(self::SHORT_QUESTION_PATTERN, $normalized) === 1
            && preg_match(self::OPEN_QUESTION_PATTERN, $normalized) !== 1
            && $words <= 12;

        // A diagnostic/integration question legitimately needs structure and detail; a
        // short, closed question must stay short.
        return $isShortShape ? self::SHORT_CAP : self::LONG_CAP;
    }

    private function isRefusal(string $answer): bool
    {
        $normalized = $this->normalize($answer);

        foreach (self::REFUSAL_MARKERS as $marker) {
            if (str_contains($normalized, $this->normalize($marker))) {
                return true;
            }
        }

        return false;
    }

    private function wordCount(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', Str::ascii(mb_strtolower($text))));
    }

    private function containsCodeShape(string $text, string $question): bool
    {
        // Deliberately narrower than ResponseGuard::containsSourceCode(): the answer has
        // already passed that guard by the time it is displayed. This only catches the
        // shapes that would make the *displayed* answer unusable (an unstripped fenced
        // block, an SQL statement, a function signature), not every code-like pattern.
        if (preg_match('/```|~~~/u', $text) === 1) {
            // A fenced block tagged json/curl/http is the DOCUMENTED API example the synthesis
            // prompt (rule 8) explicitly invites for "how-to" questions; flagging it would
            // punish the completeness users ask for ("show the example", not "see the docs").
            // Any other fence (python, untagged) stays a violation, and so does an API example
            // tacked onto a short closed question -- the concision contract there is prose, not
            // a payload dump.
            $isApiExampleFence = preg_match('/```\s*(?:json|curl|http)\b/iu', $text) === 1;
            $isOpenQuestion = preg_match(self::OPEN_QUESTION_PATTERN, $question) === 1;

            if (! ($isApiExampleFence && $isOpenQuestion)) {
                return true;
            }
        }

        return preg_match('/\b(?:SELECT|INSERT\s+INTO|UPDATE|DELETE\s+FROM)\b[^;\r\n]*;/iu', $text) === 1
            || preg_match('/^\s*(?:function|class|def|public\s+function|private\s+function)\s+\w+\s*\(/imu', $text) === 1;
    }

    private function isOfficialHttpsLink(string $url): bool
    {
        $parts = parse_url($url);
        $allowedHosts = array_map('strtolower', (array) config('rag.allowed_source_hosts', []));

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && in_array(strtolower((string) ($parts['host'] ?? '')), $allowedHosts, true);
    }
}
