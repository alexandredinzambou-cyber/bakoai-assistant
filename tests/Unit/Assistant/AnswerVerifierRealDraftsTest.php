<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\AnswerVerifier;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression suite on real drafts of open-mistral-nemo (gold set, dev split, 2026-09-30), each
 * with the exact extracts it was generated from (tests/Fixtures/answer_verifier_real_drafts.json).
 *
 * Every expectation was checked by hand against the documentation pages. The first group are
 * errors the verifier must remove; the second are correct answers CitationGuard used to cut or
 * refuse (uncited steps, a uncited opening, an adapted example, a unit conversion) and that
 * must now come through untouched.
 */
class AnswerVerifierRealDraftsTest extends TestCase
{
    /**
     * @return array<string, array{0: bool, 1: list<string>, 2: list<string>, 3: list<string>}>
     *                                                                                          acceptable, removal reasons, kept text, removed text
     */
    public static function drafts(): array
    {
        return [
            // Errors, removed.
            'g4-02: "strictement JSON" while XML is offered elsewhere' => [false, ['unsupported_qualifier'], [], ['strictement']],
            'g4-03: an invented signature algorithm' => [true, ['fabricated_identifier'], ['horodatage'], ['HMAC-SHA256']],
            'g4-05: an invented code, a guessed menu and a made-up example' => [true, ['code_block', 'fabricated_identifier', 'fabricated_label', 'speculation'], ['callback_url_code'], ['CALLBACK123', 'généralement']],
            'g4-06: "asynchronous" said of refunds' => [true, ['unsupported_qualifier'], [], ['asynchrone']],
            'neg-undocumented: the reporting webhook retries told of the status webhook' => [false, ['misattributed_detail', 'misattributed_detail'], [], ['5 fois', 'téléchargement']],
            'neg-script: invented account code and a library the docs never mention' => [true, ['fabricated_identifier', 'invalid_example'], [], ['ACC_12345', '`requests`']],
            'g3-03: a curl example with an invented beneficiary reference' => [true, ['invalid_example'], ['sharing_type'], ['BEN654321']],
            'cb-url: a guessed interface variant, cut out of its step' => [true, ['speculation'], ['Paramétrages', 'Callback'], ['selon la version']],

            // Correct answers, kept as generated.
            'g1-08: uncited steps of the forgotten-password flow' => [true, [], ['mot de passe oublié', 'suivant'], []],
            'g1-09: an uncited opening answering the question' => [true, [], ["n'est pas le même"], []],
            'g2-01: the repatriation steps' => [true, [], ['rapatriement'], []],
            'g2-08: "le client n a pas encore paye"' => [true, [], ['pas encore payé'], []],
            'g3-02: "le statut peut etre AMBIGUOUS" is not a hedge' => [true, [], ['AMBIGUOUS'], []],
            'g3-04: the 3-minute delay of the integration guide' => [true, [], ['3 minutes'], []],
            'g3-08: "inferieur a 3" exports' => [true, [], ['inférieur à 3'], []],
            'g4-04: an adapted download curl and a local file name' => [true, [], ['/download', 'report.csv'], []],
            'g4-08: the fees endpoint and its parameters' => [true, [], ['get-fees'], []],
            'x-secret-def: a documented key prefix' => [true, [], ['sk_live_87e1f459'], []],
            'secret-renew: "3600 secondes, soit 1 heure" and a --data-urlencode curl' => [true, [], ['1 heure', '--data-urlencode'], []],
            'register-otp: the OTP sent by e-mail' => [true, [], ['OTP'], []],

            // A second recorded run: other drafts, other shapes.
            'g1-07b: citations that quote a title before the id' => [true, [], ['rapatriement', 'paramètres'], []],
            'g3-02b: "(optionnel mais recommande)" on a step the documentation requires' => [true, ['contradiction'], ['Check Status'], ['optionnel mais recommandé']],
            'g4-02b: a gap about XML is no false gap on the JSON it does document' => [true, [], ['X-Callback-MediaType'], []],
            'g4-05b: invented codes, a guessed menu and generic advice' => [true, ['code_block', 'fabricated_identifier', 'fabricated_identifier', 'fabricated_label', 'fabricated_value'], ['callback_url_code'], ['CALLBACK123', 'CALLBACK_SANDBOX']],
            'g4-06b: "not required for a refund" while the refund API requires it' => [true, ['contradiction'], ['remboursement'], ['ne sont pas requis']],
            'cb-urlb: an example domain is no fabricated link' => [true, ['speculation'], ['votre-domaine.com'], ['selon la version']],
            'neg-scriptb: per-field modality, a conditional and invented values' => [true, ['code_block', 'fabricated_identifier', 'invalid_example'], ['`X-Secret`', 'si requis'], ['ACC_12345', 'import requests']],
        ];
    }

    #[DataProvider('drafts')]
    public function test_a_real_draft_is_verified_as_checked_by_hand(bool $acceptable, array $reasons, array $kept, array $removed): void
    {
        $id = explode(':', $this->dataName())[0];
        $case = $this->fixture()[$id];
        $chunks = new Collection(array_map(static fn (array $chunk): object => (object) $chunk, $case['chunks']));

        $verified = (new AnswerVerifier)->verify($case['draft'], $chunks, $case['question']);
        $actualReasons = array_column($verified->removed, 'reason');
        sort($actualReasons);

        $this->assertSame($reasons, $actualReasons, json_encode($verified->removed, JSON_UNESCAPED_UNICODE));
        $this->assertSame($acceptable, $verified->acceptable, (string) $verified->reason);

        foreach ($kept as $text) {
            $this->assertStringContainsString($text, $verified->answer);
        }

        foreach ($removed as $text) {
            $this->assertStringNotContainsString($text, $verified->answer);
        }

        if ($reasons === []) {
            // Untouched up to layout: citation markers are normalized ("*[SOURCE:1, SOURCE:2]*"
            // becomes "[SOURCE:1][SOURCE:2]") and code fences get a blank line around them.
            $content = static fn (string $text): string => trim((string) preg_replace(
                '/\s+/u',
                ' ',
                (string) preg_replace('/[*_]{0,2}\[[^\]
]*SOURCE[^\]
]*\][*_]{0,2}/iu', '', $text),
            ));

            $this->assertSame($content($case['draft']), $content($verified->answer), 'A correct draft comes through untouched.');
        }
    }

    /**
     * @return array<string, array{question: string, draft: string, chunks: list<array<string, mixed>>}>
     */
    private function fixture(): array
    {
        static $cases = null;

        return $cases ??= json_decode((string) file_get_contents(base_path('tests/Fixtures/answer_verifier_real_drafts.json')), true)['cases'];
    }
}
