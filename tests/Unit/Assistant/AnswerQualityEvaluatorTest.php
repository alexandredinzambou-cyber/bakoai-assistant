<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\AnswerQualityEvaluator;
use Tests\TestCase;

class AnswerQualityEvaluatorTest extends TestCase
{
    private AnswerQualityEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new AnswerQualityEvaluator;
    }

    public function test_a_short_answer_to_a_short_question_passes(): void
    {
        $verdict = $this->evaluator->evaluate(
            'Quel est le champ obligatoire pour initier un paiement PVIT ?',
            'Le montant est le champ obligatoire principal [Guide PVIT / Initiation].',
            [['url' => 'https://docs.mypvit.pro/fr/payment']],
            mustInclude: ['montant'],
        );

        $this->assertTrue($verdict->passed, implode(', ', $verdict->violations));
        $this->assertSame([], $verdict->violations);
        $this->assertSame(150, $verdict->stats['max_words']);
    }

    public function test_a_long_winded_answer_to_a_simple_question_fails_the_concision_cap(): void
    {
        // The product-owner complaint, encoded: a simple closed question that gets a rambling
        // answer must be caught, even when every fact in it is correct.
        $rambling = trim(str_repeat(
            'Le montant est le champ obligatoire principal, et il faut aussi verifier le compte '
            .'d operation, le numero de telephone du client, l identifiant de la transaction, '
            .'le canal de paiement utilise, la devise attendue, ainsi que l environnement de '
            .'test ou de production avant chaque appel. ',
            5,
        ));

        $verdict = $this->evaluator->evaluate(
            'Quel est le champ obligatoire pour initier un paiement PVIT ?',
            $rambling,
            [['url' => 'https://docs.mypvit.pro/fr/payment']],
        );

        $this->assertFalse($verdict->passed);
        $this->assertContains('too_long', $verdict->violations);
        $this->assertGreaterThan(150, $verdict->stats['words']);
    }

    public function test_a_diagnostic_question_earns_a_longer_cap(): void
    {
        $structured = trim(str_repeat('Verifiez d abord la reception du callback HTTP 200, puis l etat de la transaction. ', 6));

        $verdict = $this->evaluator->evaluate(
            'Comment diagnostiquer une erreur 500 sur le callback PVIT ?',
            $structured,
            [['url' => 'https://docs.mypvit.pro/fr/callback']],
        );

        $this->assertSame(400, $verdict->stats['max_words']);
        $this->assertTrue($verdict->passed, implode(', ', $verdict->violations));
    }

    public function test_an_explicit_per_case_budget_overrides_the_automatic_cap(): void
    {
        $verdict = $this->evaluator->evaluate(
            'Comment diagnostiquer une erreur 500 sur le callback PVIT ?',
            'Verifiez d abord la reception du callback HTTP 200, puis l etat definitif de la transaction avant de conclure.',
            [],
            expectsSources: false,
            maxWords: 10,
        );

        $this->assertSame(10, $verdict->stats['max_words']);
        $this->assertFalse($verdict->passed);
        $this->assertContains('too_long', $verdict->violations);
    }

    public function test_expected_and_forbidden_terms_are_enforced(): void
    {
        $verdict = $this->evaluator->evaluate(
            'Comment renouveler le secret PVIT ?',
            'Rendez-vous dans votre espace marchand, section Securite.',
            [['url' => 'https://docs.mypvit.pro/fr/renew-secret']],
            mustInclude: ['secret'],
            mustNotInclude: ['woocommerce'],
        );

        $this->assertFalse($verdict->passed);
        $this->assertContains('expected_term_missing:secret', $verdict->violations);

        $verdict = $this->evaluator->evaluate(
            'Comment renouveler le secret PVIT ?',
            'Renouvelez le secret depuis la section Securite, ou via le plugin WooCommerce.',
            [['url' => 'https://docs.mypvit.pro/fr/renew-secret']],
            mustInclude: ['secret'],
            mustNotInclude: ['woocommerce'],
        );

        $this->assertFalse($verdict->passed);
        $this->assertContains('forbidden_term_present:woocommerce', $verdict->violations);
    }

    public function test_code_and_raw_citation_markers_are_reported(): void
    {
        $verdict = $this->evaluator->evaluate(
            'Quel est le X-Secret ?',
            "Le X-Secret s'envoie dans l'en-tete.\n\n```json\n{\"X-Secret\": \"...\"}\n```",
            [['url' => 'https://docs.mypvit.pro/fr/auth']],
        );

        $this->assertContains('code_detected', $verdict->violations);

        $verdict = $this->evaluator->evaluate(
            'Quel est le X-Secret ?',
            'Le X-Secret identifie votre marche. [SOURCE:17]',
            [['url' => 'https://docs.mypvit.pro/fr/auth']],
        );

        $this->assertContains('raw_citation_marker', $verdict->violations);
    }

    public function test_missing_sources_and_unofficial_links_are_reported(): void
    {
        $verdict = $this->evaluator->evaluate(
            'Quel est le X-Secret ?',
            'Le X-Secret identifie votre marche.',
            [],
        );

        $this->assertContains('sources_missing', $verdict->violations);

        $verdict = $this->evaluator->evaluate(
            'Quel est le X-Secret ?',
            'Le X-Secret identifie votre marche.',
            [['url' => 'http://docs.mypvit.pro/fr/auth'], ['url' => 'https://blog-exemple.com/pvit']],
        );

        $this->assertContains('unofficial_source_link', $verdict->violations);
    }

    public function test_a_refusal_is_exempt_from_concision_and_term_rules_but_not_from_hygiene(): void
    {
        $refusal = trim(str_repeat('Je ne peux pas generer ce script. ', 40));

        $verdict = $this->evaluator->evaluate('Ecris-moi un script Python complet pour PVIT.', $refusal, [], expectsSources: false);

        $this->assertNotContains('too_long', $verdict->violations);
        $this->assertNotContains('sources_missing', $verdict->violations);

        $verdict = $this->evaluator->evaluate(
            'Ecris-moi un script Python complet pour PVIT.',
            'Je ne peux pas generer ce script. ```python\nprint(1)\n```',
            [],
            expectsSources: false,
        );

        $this->assertContains('code_detected', $verdict->violations);
    }

    public function test_an_empty_answer_fails_immediately(): void
    {
        $verdict = $this->evaluator->evaluate('Quel est le X-Secret ?', '   ', []);

        $this->assertFalse($verdict->passed);
        $this->assertSame(['empty_answer'], $verdict->violations);
    }

    public function test_term_matching_is_accent_and_case_insensitive(): void
    {
        $verdict = $this->evaluator->evaluate(
            'C est quoi le X-Secret ?',
            "Le X-SECRET authentifie chaque requete marchande.",
            [['url' => 'https://docs.mypvit.pro/fr/auth']],
            mustInclude: ['x-secret'],
        );

        $this->assertTrue($verdict->passed, implode(', ', $verdict->violations));
    }

    public function test_a_refusal_on_an_answerable_question_is_a_failure(): void
    {
        $refusal = "Je n'ai pas pu produire une reponse suffisamment adossee a la documentation PVIT pour cette question.";

        $verdict = $this->evaluator->evaluate(
            'Quel est le champ obligatoire pour initier un paiement PVIT ?',
            $refusal,
            [['url' => 'https://docs.mypvit.pro/fr/payment']],
            expectedBehavior: 'answer',
        );

        $this->assertFalse($verdict->passed);
        $this->assertContains('unexpected_refusal', $verdict->violations);

        // Without an expected behaviour the historical rule stands: a refusal is judged on hygiene only.
        $this->assertTrue($this->evaluator->evaluate(
            'Quel est le champ obligatoire pour initier un paiement PVIT ?',
            $refusal,
            [['url' => 'https://docs.mypvit.pro/fr/payment']],
        )->passed);
    }

    public function test_an_answer_to_a_question_that_had_to_be_declined_is_a_failure(): void
    {
        $verdict = $this->evaluator->evaluate(
            'Quelle est la capitale de la France ?',
            'La capitale de la France est Paris.',
            [],
            expectsSources: false,
            expectedBehavior: 'decline',
        );

        $this->assertContains('expected_refusal_missing', $verdict->violations);
    }

    public function test_the_pipeline_refusal_flag_wins_over_the_wording(): void
    {
        // AnswerVerifier refused (citation_filtered) with a wording the marker list does not know.
        $verdict = $this->evaluator->evaluate(
            'Quel est le champ obligatoire pour initier un paiement PVIT ?',
            'Aucune formulation fiable.',
            [['url' => 'https://docs.mypvit.pro/fr/payment']],
            expectedBehavior: 'answer',
            refused: true,
        );

        $this->assertContains('unexpected_refusal', $verdict->violations);
    }
}
