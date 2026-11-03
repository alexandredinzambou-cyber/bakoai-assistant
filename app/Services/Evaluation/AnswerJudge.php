<?php

namespace App\Services\Evaluation;

use App\Services\Llm\Exceptions\LlmClientException;
use App\Services\Llm\OpenAiCompatibleLlmClient;
use Illuminate\Support\Str;
use JsonException;

/**
 * LLM judge of one recorded answer: what AnswerVerifier's lexical checks cannot see -- a claim
 * that shares the words of a passage but not its meaning ("HTTPS obligatoire" where the doc says
 * "recommandee"), a passage used out of its context, a reference fact left out.
 *
 * Faithfulness is judged against the context the generator actually received, never against the
 * judge's own knowledge; completeness against the gold key facts; mistakes against the gold
 * forbidden facts. The judge runs at temperature 0 with a fixed seed and must return JSON; an
 * answer it cannot parse is a judge error, never a silent pass.
 */
class AnswerJudge
{
    public const VERDICTS = ['supported', 'unsupported', 'contradicted'];

    public const BEHAVIORS = ['answers', 'declines', 'acknowledges_gap', 'evades'];

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a strict evaluator of answers written by a retrieval-augmented assistant for the PVit payment gateway documentation. You judge; you never answer the question yourself. Reply with one JSON object and nothing else.

Inputs: QUESTION; CONTEXT, the numbered documentation extracts the assistant was given, which are the only admissible evidence; ANSWER, the assistant's final answer, where [n] markers point to its sources; KEY FACTS, reference facts a complete answer states; FORBIDDEN, statements that are false or undocumented.

1. claims: split the ANSWER into atomic factual claims about PVit or its API. Skip greetings, offers of further help, advice to contact support, and statements that something is not documented. For each claim give a verdict:
   - "supported": the CONTEXT states it or it follows directly from it;
   - "contradicted": the CONTEXT states something incompatible (a different value, a negation, "required" for "optional", "mandatory" for "recommended");
   - "unsupported": the CONTEXT does not state it, even if it is plausible or true in general.
   Judge only against the CONTEXT, never against your own knowledge. A value copied from a documented example (URL, code, amount, header) is supported when it appears in the CONTEXT. Merge repetitions; at most 25 claims.
2. key_facts: for each KEY FACT number, covered is true when the ANSWER states its substance (paraphrase allowed; a vague allusion does not count).
3. forbidden: for each FORBIDDEN number, present is true when the ANSWER asserts it or an equivalent. Mentioning it in order to deny it is not asserting it.
4. behavior: "answers" (addresses the question with documented content), "declines" (refuses, or says the request is outside what it can do), "acknowledges_gap" (says the documentation does not cover the point, possibly pointing to what is documented), "evades" (neither answers nor clearly declines).
5. relevance, 1 to 5: 5 answers exactly what was asked; 3 answers partly, or buries the answer in unrelated material; 1 is off-topic.
6. concision, 1 to 5: 5 has nothing superfluous for this question; 3 has noticeable padding or unrequested material; 1 is mostly padding.

JSON format:
{"claims":[{"claim":"...","verdict":"supported|unsupported|contradicted","evidence":"short quote from CONTEXT, or empty"}],"key_facts":[{"n":1,"covered":true}],"forbidden":[{"n":1,"present":false}],"behavior":"answers|declines|acknowledges_gap|evades","relevance":5,"concision":5,"comment":"one short sentence"}
PROMPT;

    public function __construct(private readonly OpenAiCompatibleLlmClient $llm) {}

    public function provider(): string
    {
        return (string) config('llm.judge.provider', 'groq');
    }

    public function model(): string
    {
        $key = (string) config('llm.judge.model_key', 'judge_model');

        return (string) config('llm.providers.'.$this->provider().'.'.$key);
    }

    /**
     * @param  list<array{fact: string}>  $keyFacts
     * @param  list<string>  $forbiddenFacts
     * @return list<array{role: string, content: string}>
     */
    public function messages(string $question, string $context, string $answer, array $keyFacts, array $forbiddenFacts): array
    {
        $numbered = static fn (array $items): string => $items === []
            ? '(none)'
            : implode("\n", array_map(static fn (string $item, int $index): string => ($index + 1).'. '.$item, $items, array_keys($items)));

        return [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user', 'content' => "QUESTION:\n{$question}\n\n"
                ."CONTEXT:\n".($context !== '' ? $context : '(empty)')."\n\n"
                ."ANSWER:\n".self::withoutPipelineNotices($answer)."\n\n"
                ."KEY FACTS:\n".$numbered(array_map(static fn (array $fact): string => (string) $fact['fact'], $keyFacts))."\n\n"
                ."FORBIDDEN:\n".$numbered(array_values($forbiddenFacts))],
        ];
    }

    /**
     * @param  list<array{fact: string}>  $keyFacts
     * @param  list<string>  $forbiddenFacts
     * @return array<string, mixed> Parsed verdict (see parse()) plus the judge's raw output.
     *
     * @throws LlmClientException
     */
    public function judge(string $question, string $context, string $answer, array $keyFacts, array $forbiddenFacts): array
    {
        $raw = $this->llm->completeMessages(
            $this->provider(),
            (string) config('llm.judge.model_key', 'judge_model'),
            $this->messages($question, $context, $answer, $keyFacts, $forbiddenFacts),
            [
                'temperature' => 0.0,
                'seed' => (int) config('llm.judge.seed', 20260930),
                'max_tokens' => 4000,
                'response_format' => ['type' => 'json_object'],
            ],
        );

        return self::parse($raw, count($keyFacts), count($forbiddenFacts)) + ['raw' => $raw];
    }

    /**
     * Validated verdict. A key fact or forbidden statement the judge left out counts as not
     * covered / not present and is listed in warnings; unparseable JSON sets error.
     *
     * @return array{error: ?string, warnings: list<string>, claims: list<array{claim: string, verdict: string, evidence: string}>, claims_total: int, supported: int, unsupported: int, contradicted: int, faithfulness: ?float, key_facts_covered: list<int>, key_fact_recall: ?float, forbidden_present: list<int>, behavior: ?string, relevance: ?int, concision: ?int, comment: string}
     */
    public static function parse(string $raw, int $keyFactCount, int $forbiddenCount): array
    {
        $empty = [
            'error' => null,
            'warnings' => [],
            'claims' => [],
            'claims_total' => 0,
            'supported' => 0,
            'unsupported' => 0,
            'contradicted' => 0,
            'faithfulness' => null,
            'key_facts_covered' => [],
            'key_fact_recall' => $keyFactCount > 0 ? 0.0 : null,
            'forbidden_present' => [],
            'behavior' => null,
            'relevance' => null,
            'concision' => null,
            'comment' => '',
        ];
        $text = trim((string) preg_replace('/\A```(?:json)?\s*|\s*```\z/u', '', trim($raw)));
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            return ['error' => 'no_json_object'] + $empty;
        }

        try {
            $data = json_decode(substr($text, $start, $end - $start + 1), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['error' => 'invalid_json'] + $empty;
        }

        if (! is_array($data)) {
            return ['error' => 'invalid_json'] + $empty;
        }

        $warnings = [];
        $claims = [];

        foreach ((array) ($data['claims'] ?? []) as $claim) {
            $verdict = is_array($claim) ? strtolower(trim((string) ($claim['verdict'] ?? ''))) : '';

            if (! in_array($verdict, self::VERDICTS, true)) {
                $warnings[] = 'claim_without_valid_verdict';

                continue;
            }

            $claimText = trim((string) ($claim['claim'] ?? ''));

            // Saying that the documentation does not cover a point is the behaviour the prompt
            // asks for, not a claim about PVit: the judge is told to skip it, and is overruled
            // when it lists one anyway.
            if (self::isGapStatement($claimText)) {
                $warnings[] = 'gap_statement_dropped';

                continue;
            }

            $claims[] = [
                'claim' => $claimText,
                'verdict' => $verdict,
                'evidence' => trim((string) ($claim['evidence'] ?? '')),
            ];
        }

        $counts = array_count_values(array_column($claims, 'verdict'));
        $covered = self::numberedFlags((array) ($data['key_facts'] ?? []), 'covered', $keyFactCount, 'key_fact', $warnings);
        $present = self::numberedFlags((array) ($data['forbidden'] ?? []), 'present', $forbiddenCount, 'forbidden', $warnings);
        $behavior = strtolower(trim((string) ($data['behavior'] ?? '')));

        if (! in_array($behavior, self::BEHAVIORS, true)) {
            $warnings[] = 'invalid_behavior';
            $behavior = null;
        }

        // Scored before the result is built: a literal evaluates 'warnings' before its later keys.
        $relevance = self::score($data['relevance'] ?? null, $warnings);
        $concision = self::score($data['concision'] ?? null, $warnings);

        return [
            'error' => null,
            'warnings' => array_values(array_unique($warnings)),
            'claims' => $claims,
            'claims_total' => count($claims),
            'supported' => $counts['supported'] ?? 0,
            'unsupported' => $counts['unsupported'] ?? 0,
            'contradicted' => $counts['contradicted'] ?? 0,
            'faithfulness' => $claims === [] ? null : round(($counts['supported'] ?? 0) / count($claims), 4),
            'key_facts_covered' => $covered,
            'key_fact_recall' => $keyFactCount > 0 ? round(count($covered) / $keyFactCount, 4) : null,
            'forbidden_present' => $present,
            'behavior' => $behavior,
            'relevance' => $relevance,
            'concision' => $concision,
            'comment' => trim((string) ($data['comment'] ?? '')),
        ];
    }

    public static function isGapStatement(string $claim): bool
    {
        $text = ' '.(string) preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(mb_strtolower($claim))).' ';

        // "n'est pas documente", "not documented": about the documentation by construction.
        if (preg_match('/ n (?:est|sont) pas (?:documente|mentionne|couvert)| (?:not|never) (?:documented|mentioned|covered) /', $text) === 1) {
            return true;
        }

        // "ne precise pas", "aucune information": only when the subject is the documentation, so
        // "la notification ne contient pas le fichier" stays a claim about the API.
        return preg_match('/ (?:documentation|doc|docs|extraits?|contexte|sources?|pages?|reference) /', $text) === 1
            && preg_match(
                '/ ne? (?:(?:le|la|les|l|en) )?(?:precise|mentionne|documente|couvre|decrit|indique|detaille|traite|contient|fournit|donne|aborde)\w* (?:pas|aucun|rien)'
                .'| n (?:est|sont) pas (?:precise|decrit|indique|detaille)'
                .'| aucune? (?:information|mention|precision|indication|detail) '
                .'| (?:does|do) not (?:[a-z]+ly )?(?:specify|mention|document|cover|describe|provide|contain|detail) /',
                $text,
            ) === 1;
    }

    /**
     * The answer as the reader sees it, minus the two notices the pipeline appends itself (the
     * removal notice and the documentation-example notice): they are not claims of the model.
     */
    public static function withoutPipelineNotices(string $answer): string
    {
        $lines = preg_split('/\R/u', $answer) ?: [];
        $kept = array_filter($lines, static fn (string $line): bool => preg_match(
            '/^\s*\*(?:Certains points de la reponse generee|Les valeurs d.exemple reprises ici)/u',
            $line,
        ) !== 1);

        return trim(implode("\n", $kept));
    }

    /**
     * Words of prose, as the length rules count them: code blocks, citation markers and pipeline
     * notices excluded.
     */
    public static function proseWordCount(string $answer): int
    {
        $prose = (string) preg_replace('/```.*?(?:```|\z)/su', ' ', self::withoutPipelineNotices($answer));
        $prose = (string) preg_replace('/\[\d+\]/u', ' ', $prose);

        // Punctuation left alone by a removed marker ("statut [1].") is not a word.
        return count(array_filter(
            preg_split('/\s+/u', trim($prose), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn (string $token): bool => preg_match('/[\p{L}\p{N}]/u', $token) === 1,
        ));
    }

    /**
     * @param  list<mixed>  $items
     * @param  list<string>  $warnings
     * @return list<int> The 1-based numbers flagged true.
     */
    private static function numberedFlags(array $items, string $flag, int $count, string $label, array &$warnings): array
    {
        $seen = [];
        $flagged = [];

        foreach ($items as $position => $item) {
            if (! is_array($item)) {
                continue;
            }

            $number = (int) ($item['n'] ?? ($position + 1));

            if ($number < 1 || $number > $count) {
                $warnings[] = $label.'_out_of_range';

                continue;
            }

            $seen[$number] = true;

            if (($item[$flag] ?? false) === true) {
                $flagged[$number] = $number;
            }
        }

        if (count($seen) < $count) {
            $warnings[] = $label.'_missing';
        }

        ksort($flagged);

        return array_values($flagged);
    }

    /**
     * @param  list<string>  $warnings
     */
    private static function score(mixed $value, array &$warnings): ?int
    {
        if (! is_numeric($value) || (int) $value < 1 || (int) $value > 5) {
            $warnings[] = 'invalid_score';

            return null;
        }

        return (int) $value;
    }
}
