<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\AnswerVerifier;
use App\Services\Assistant\ConversationActions;
use App\Services\Assistant\DocumentedFactAnswer;
use App\Services\Assistant\DurationConversion;
use App\Services\Assistant\JsonExamples;
use App\Services\Assistant\RequestContractAnswer;
use App\Services\Assistant\ResponseGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EighthAuditRegressionTest extends TestCase
{
    public static function unrelatedNegations(): array
    {
        return [['Pour ne pas interrompre votre intégration, '], ['Afin de ne jamais perdre votre référence, '], ['Il ne faut pas attendre, '], ['Sans arrêter vos tests, ']];
    }

    #[DataProvider('unrelatedNegations')]
    public function test_negation_of_another_action_does_not_authorize_cancellation(string $prefix): void
    {
        $chunk = (object) ['chunk_id' => 1, 'document_titre' => 'Statut', 'contenu' => 'Vérifiez le statut de la transaction avec Check Status. Endpoint : GET /status. Une autre API permet d’annuler une vente.'];
        $result = (new AnswerVerifier)->verify($prefix.'annulez la transaction via l’API de statut [SOURCE:1].', collect([$chunk]));
        $this->assertContains('unsupported_capability', $result->removedReasons());
    }

    public function test_a_genuinely_negated_capability_is_not_reported_as_a_positive_action(): void
    {
        $text = 'Check Status ne permet pas d’annuler la transaction. Il vérifie uniquement son statut.';
        $chunk = (object) ['chunk_id' => 1, 'document_titre' => 'Statut', 'contenu' => $text];
        $result = (new AnswerVerifier)->verify($text.' [SOURCE:1].', collect([$chunk]));
        $this->assertTrue($result->acceptable, json_encode($result->removed));
    }

    public static function codeRequests(): array
    {
        return [
            ['Je ne veux pas de code PHP, mais montre l’exemple JSON de cet accusé de réception.', false],
            ['Sans code Python, donne un exemple JSON de réponse.', false],
            ['Pas de code JavaScript, seulement un exemple JSON.', false],
            ['Je ne veux pas de code PHP, mais écris un script Python pour le callback.', true],
            ['Écris une classe PHP qui retourne cet exemple JSON.', true],
        ];
    }

    #[DataProvider('codeRequests')]
    public function test_declined_code_and_positive_artifacts_are_separate(string $question, bool $refused): void
    {
        $this->assertSame($refused, (new ResponseGuard)->requestsSourceCode($question));
    }

    public function test_negative_numbers_in_descriptive_questions_are_not_navigation(): void
    {
        $actions = new ConversationActions;
        $this->assertNull($actions->stepRequest('Autre sujet : que signifie une erreur à l’étape -1 du remboursement ?'));
        $this->assertNull($actions->stepRequest('Pourquoi le journal mentionne-t-il étape 2.5 ?'));
        $this->assertSame('number:0', $actions->stepRequest('Va à l’étape -1.'));
        $this->assertSame('number:0', $actions->stepRequest('Va à l’étape 2.5.'));
    }

    public static function durations(): array
    {
        return [
            ['7 jours après la demande.', 'heures', '168 heures'],
            ['3600 secondes après génération.', 'minutes', '60 minutes'],
            ['1,5 heures après la demande.', 'minutes', '90 minutes'],
            ['60 minutes après génération.', 'secondes', '3600 secondes'],
            ['168 heures après la demande.', 'jours', '7 jours'],
        ];
    }

    #[DataProvider('durations')]
    public function test_conversions_use_the_documented_unit(string $answer, string $target, string $expected): void
    {
        $service = new DurationConversion;
        $this->assertStringContainsString($expected, $service->convert($service->extract($answer), $target));
    }

    public function test_ambiguous_durations_are_not_guessed(): void
    {
        $this->assertNull((new DurationConversion)->extract('La clé dure 3600 secondes et le fichier dure 7 jours.'));
    }

    public function test_json_fields_are_selected_by_role_and_not_merged(): void
    {
        $answer = "Notification reçue :\n```json\n{\"transactionId\":\"PAY1\",\"amount\":150,\"status\":\"SUCCESS\",\"code\":200}\n```\nAccusé à renvoyer :\n```json\n{\"transactionId\":\"PAY1\",\"responseCode\":200}\n```";
        $actions = new ConversationActions;
        $this->assertSame('`transactionId`, `responseCode`.', $actions->formatAnswer($answer, 'Indique les noms des champs à renvoyer.'));
        $this->assertStringNotContainsString('responseCode', $actions->formatAnswer($answer, 'Liste les noms des champs de la notification reçue.'));
        $this->assertStringContainsString('Plusieurs objets JSON', $actions->formatAnswer($answer, 'Indique les noms des champs.'));
        $this->assertCount(2, (new JsonExamples)->extract($answer));
    }

    public function test_explicit_https_rejection_is_not_hidden_by_a_recommendation(): void
    {
        $chunks = collect([(object) ['chunk_id' => 1, 'contenu' => 'HTTPS recommandée pour le callback.'], (object) ['chunk_id' => 2, 'contenu' => 'Pour les URL de callback, HTTP est refusé par le service. HTTPS est obligatoire.']]);
        $fact = (new DocumentedFactAnswer)->httpsPolicy('HTTPS est-il obligatoire ?', $chunks);
        $this->assertStringContainsString('se contredisent', $fact['answer']);
        $this->assertCount(2, $fact['chunks']);
    }

    public function test_a_refund_template_requires_positive_synchronous_and_no_webhook_evidence(): void
    {
        $body = (object) ['chunk_id' => 586, 'document_titre' => 'Initier un remboursement', 'contenu' => "## Corps de la requête\n- amount (number, Obligatoire) — Montant.\nLe remboursement est traité de façon synchrone. Aucun webhook n’est nécessaire."];
        $curl = (object) ['chunk_id' => 587, 'document_titre' => 'Initier un remboursement', 'contenu' => "```bash\ncurl -H 'X-Secret: demo' -d '{\"transaction_type\":\"GIVE_CHANGE\"}'\n```"];
        $service = new RequestContractAnswer;
        $this->assertNotNull($service->refundProcedure('Quelle procédure de remboursement ?', collect([$body, $curl])));
        $body->contenu = str_replace('synchrone', 'asynchrone', $body->contenu);
        $this->assertNull($service->refundProcedure('Quelle procédure de remboursement ?', collect([$body, $curl])));
    }

    public function test_a_negated_https_obligation_is_not_a_conflicting_positive_rule(): void
    {
        $chunks = collect([(object) ['chunk_id' => 1, 'contenu' => 'HTTPS recommandée pour le callback.'], (object) ['chunk_id' => 2, 'contenu' => 'HTTPS n’est pas obligatoire pour cette URL.']]);
        $answer = (new DocumentedFactAnswer)->httpsPolicy('HTTPS est-il obligatoire ?', $chunks)['answer'];
        $this->assertStringNotContainsString('se contredisent', $answer);
    }

    public function test_status_capability_does_not_invent_a_generic_cancellation_or_refund_rule(): void
    {
        $source = (object) ['chunk_id' => 589, 'document_titre' => 'Statut d’une transaction', 'contenu' => 'Récupérez l’état définitif. Endpoint : GET /{code-url-status}/status'];
        $answer = (new DocumentedFactAnswer)->statusCapability('Peux-tu annuler via l’API Check Status ?', 'Peux-tu annuler via l’API Check Status ?', collect([$source]))['answer'];
        $this->assertStringContainsString('consulter le statut', $answer);
        $this->assertStringContainsString('ne peux pas confirmer', $answer);
        $this->assertStringNotContainsString('remboursement', $answer);
    }

    public function test_required_fields_are_complete_and_exclude_optional_fields(): void
    {
        $chunk = (object) ['chunk_id' => 586, 'document_titre' => 'Initier un remboursement', 'contenu' => "## Corps de la requête\n- amount (number, Obligatoire) — Montant.\n- reference (string, Obligatoire) — Référence.\n- transaction_type (string, Obligatoire) — GIVE_CHANGE.\n- free_info (string) — Optionnel."];
        $fact = (new RequestContractAnswer)->requiredFields('Quels champs sont obligatoires ?', 'Initier un remboursement', collect([$chunk]));
        foreach (['amount', 'reference', 'transaction_type'] as $name) {
            $this->assertStringContainsString('`'.$name.'`', $fact['answer']);
        }
        $this->assertStringNotContainsString('free_info', $fact['answer']);
        $other = clone $chunk;
        $other->chunk_id = 587;
        $other->contenu = str_replace('reference (string, Obligatoire)', 'reference (string)', $chunk->contenu);
        $this->assertNull((new RequestContractAnswer)->requiredFields('Quels champs sont obligatoires ?', 'Initier un remboursement', collect([$chunk, $other])));
    }
}
