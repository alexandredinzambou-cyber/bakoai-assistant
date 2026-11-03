<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\ConversationActions;
use PHPUnit\Framework\TestCase;

class ConversationActionsTest extends TestCase
{
    public function test_a_summary_keeps_three_verified_points_without_code_or_filler(): void
    {
        $actions = new ConversationActions;
        $answer = "1. Attendez trois minutes avant de consulter le statut [1].\n2. Utilisez Check Status avec la référence de transaction [2].\n```bash\ncurl example.invalid\n```\n3. Vérifiez les notifications dans les logs du serveur [3].\n4. Ne supposez pas un échec sans confirmation du statut [4].";
        $summary = $actions->summarize($answer, 3);
        $this->assertSame(3, preg_match_all('/^\d+\. /m', $summary));
        $this->assertStringNotContainsString('curl', $summary);
        $this->assertStringNotContainsString('Ne supposez', $summary);
        $this->assertSame(3, $actions->summaryPoints('Peux-tu résumer cela en trois points, sans code ?'));
    }

    public function test_a_short_answer_is_not_padded_with_invented_points(): void
    {
        $summary = (new ConversationActions)->summarize('La clé secrète reste valide pendant 3600 secondes [1].', 3);
        $this->assertStringContainsString('seulement 1 point', $summary);
        $this->assertSame(1, preg_match_all('/^\d+\. /m', $summary));
    }

    public function test_a_three_point_summary_covers_three_paragraphs_before_repeating_one(): void
    {
        $answer = "Vérifiez que l’URL callback est publique et accessible [1]. Vérifiez aussi sa configuration dans PVIT [1].\n\nUtilisez Check Status pour consulter le résultat de la transaction [2]. Attendez le délai documenté [2].\n\nRenvoyez un accusé de réception dynamique avec les champs reçus [3].";
        $summary = (new ConversationActions)->summarize($answer, 3);
        $this->assertStringContainsString('accusé de réception dynamique', $summary);
        $this->assertStringNotContainsString('Vérifiez aussi', $summary);
    }

    public function test_only_an_action_request_is_handled_as_a_support_request(): void
    {
        $actions = new ConversationActions;
        $this->assertTrue($actions->requestsSupport('Peux-tu ouvrir un ticket de support pour ce problème ?'));
        $this->assertFalse($actions->requestsSupport('Comment ouvrir un ticket de support ?'));
        $this->assertFalse($actions->requestsSupport('Ne crée pas de ticket de support.'));
    }

    public function test_support_negations_are_respected_with_apostrophes_and_aucun(): void
    {
        $actions = new ConversationActions;
        foreach (['N’ouvre aucun ticket pour le moment.', "N'ouvre pas de ticket.", 'Pas de nouveau ticket de support.', 'Ne transmets jamais ce problème au support.'] as $question) {
            $this->assertTrue($actions->declinesSupport($question), $question);
            $this->assertFalse($actions->requestsSupport($question), $question);
        }
    }

    public function test_reformulation_format_and_named_subject_are_separate(): void
    {
        $actions = new ConversationActions;
        $this->assertTrue($actions->refersToPreviousAnswer('Reformule ta dernière réponse en une seule phrase, sans liste ni code.'));
        $this->assertFalse($actions->refersToPreviousAnswer('Peux-tu résumer l’API de remboursement PVIT en trois points ?'));
        $this->assertFalse($actions->refersToPreviousAnswer('Résume en trois points l’API de remboursement.'));
        $this->assertSame(6, $actions->summaryPoints('Récapitule cela en six points, sans code.'));
        $answer = "1. Votre serveur reçoit une notification de paiement [1].\n2. Il renvoie les valeurs du message reçu [1].";
        $formatted = $actions->formatAnswer($answer, 'Reformule ta dernière réponse en une seule phrase, sans liste ni code.');
        $this->assertStringStartsWith('Votre serveur', $formatted);
        $this->assertStringNotContainsString("\n", $formatted);
        $this->assertStringNotContainsString('Il renvoie', $formatted);
    }

    public function test_only_requested_not_excluded_http_codes_trigger_diagnosis(): void
    {
        $actions = new ConversationActions;
        $question = 'Non, je parle d’un remboursement, pas de l’erreur HTTP 401.';
        $this->assertNull($actions->requestedHttpError($question));
        $this->assertStringContainsString('remboursement', $actions->withoutNegatedErrors($question));
        $this->assertStringNotContainsString('401', $actions->withoutNegatedErrors($question));
        $this->assertSame('401', $actions->requestedHttpError('Que signifie une erreur HTTP 401 lors de cet appel ?'));
        $this->assertSame('500', $actions->requestedHttpError("Pas de l'erreur HTTP 401, mais HTTP 500."));
    }

    public function test_information_request_survives_a_declined_support_directive(): void
    {
        $actions = new ConversationActions;
        $this->assertSame('explique uniquement les paramètres de cette API.', $actions->withoutSupportDirective('Ne crée pas de ticket : explique uniquement les paramètres de cette API.'));
        $this->assertSame('', $actions->withoutSupportDirective('N’ouvre aucun ticket pour le moment.'));
        $this->assertTrue($actions->requestsSupport('Peux-tu ouvrir un ticket sans envoyer de données confidentielles ?'));
        $this->assertFalse($actions->requestsSupport('Ne transmets pas de données confidentielles au support.'));
    }

    public function test_recall_and_nominal_summary_are_references_not_new_topics(): void
    {
        $actions = new ConversationActions;
        foreach (['Peux-tu rappeler les étapes dans l’ordre, sans exemple de code ?', 'Fais un résumé de cette réponse en une phrase.', 'Explique la même chose à un débutant, sans jargon.'] as $request) {
            $this->assertNotNull($actions->formatRequest($request), $request);
            $this->assertTrue($actions->refersToPreviousAnswer($request), $request);
        }
        $this->assertFalse($actions->refersToPreviousAnswer('Fais un résumé de l’API de remboursement en trois points.'));
    }

    public function test_two_sentence_summary_keeps_a_numeric_fact_before_a_colon(): void
    {
        $answer = "Non, les durées de la clé et de l’export ne sont pas identiques.\n\nLa clé secrète expire après **3600 secondes (1 heure)** comme indiqué dans la réponse de renouvellement : { [1]\n\n*Certains points ont été retirés.*";
        $summary = (new ConversationActions)->formatAnswer($answer, 'Résume cela en deux phrases, sans puces.');
        $this->assertStringContainsString('3600 secondes', $summary);
        $this->assertStringNotContainsString('{', $summary);
        $this->assertStringNotContainsString('point(s)', $summary);
        $this->assertSame(2, preg_match_all('/[.!?](?:\s|$)/u', $summary));
        $this->assertDoesNotMatchRegularExpression('/^\d+\./', $summary);
    }

    public function test_a_summary_never_copies_legacy_json_payload_lines_as_prose(): void
    {
        $answer = "Le code reçu doit être repris sans le fixer à 200.\nLa valeur reste dynamique et dépend de la notification.\n{\n\"transactionId\": \"PAY23102400001\",\n\"responseCode\": 200\n}";
        $summary = (new ConversationActions)->formatAnswer($answer, 'Explique la même chose à un débutant, sans jargon.');
        $this->assertStringNotContainsString('PAY23102400001', $summary);
        $this->assertStringNotContainsString('"responseCode"', $summary);
        $this->assertStringContainsString('dynamique', $summary);
    }
}
