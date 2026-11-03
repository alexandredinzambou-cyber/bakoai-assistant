<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\AnswerVerifier;
use App\Services\Assistant\ConversationActions;
use App\Services\Assistant\ConversationPlan;
use App\Services\Assistant\DocumentedFactAnswer;
use App\Services\Assistant\FollowUpDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConversationIntentMatrixTest extends TestCase
{
    public static function navigation(): array
    {
        return [
            ['Va directement à l’étape 3.', 'number:3'], ['Affiche l’étape numéro 2.', 'number:2'],
            ['Reprends l’étape trois.', 'number:3'], ['Étape 4', 'number:4'],
            ['Montre-moi l’étape 999.', 'number:999'], ['Va à l’étape 0.', 'number:0'],
            ['Va à l’étape -1.', 'number:0'], ['Va à l’étape 2.5.', 'number:0'],
            ['Donne-moi la dernière étape.', 'last'], ['Reviens à l’étape précédente.', 'previous'],
            ['C’est fait, passe à la suivante.', 'next'], ['Montre-moi seulement la première étape.', 'first'],
        ];
    }

    #[DataProvider('navigation')]
    public function test_navigation_variants(string $question, string $expected): void
    {
        $this->assertSame($expected, (new ConversationActions)->stepRequest($question));
        $this->assertTrue((new FollowUpDetector)->isFollowUp($question, 'Tester les paiements en Sandbox'));
    }

    public static function presentation(): array
    {
        return [
            ['Mets ça sous forme d’un tableau avec une ligne pour chaque élément.', 'table'],
            ['Présente cela dans un tableau.', 'table'], ['Sous forme de tableau, svp.', 'table'],
            ['Sans tableau, explique la même chose en deux phrases.', 'sentences'],
            ['Revenons aux frais : résume les paramètres obligatoires en trois points, sans nouvel exemple.', 'points'],
            ['Résume ces champs en deux points.', 'points'],
            ['Sans exemple JSON, indique seulement les noms des champs à renvoyer.', 'fields'],
            ['Juste les noms des champs, merci.', 'fields'],
            ['Liste des champs de cette réponse JSON.', 'fields'],
        ];
    }

    #[DataProvider('presentation')]
    public function test_presentation_variants(string $question, string $kind): void
    {
        $actions = new ConversationActions;
        $this->assertSame($kind, $actions->formatRequest($question)['kind']);
        $this->assertTrue($actions->refersToPreviousAnswer($question));
    }

    public static function noExamples(): array
    {
        return array_map(static fn ($q) => [$q], ['Sans exemple JSON, donne les noms.', 'Résume les paramètres sans nouvel exemple.', 'Pas d’exemple, juste les champs.', 'Aucun exemple de requête, merci.']);
    }

    #[DataProvider('noExamples')]
    public function test_negated_examples_never_trigger_a_fees_example(string $question): void
    {
        $actions = new ConversationActions;
        $this->assertTrue($actions->declinesExamples($question));
        $this->assertFalse($actions->requestsExample($question));
        $this->assertNull((new DocumentedFactAnswer)->feesQueryExample($question, 'API de frais', collect()));
        $this->assertStringNotContainsString('```', $actions->formatAnswer("Exemple :\n```json\n{}\n```", $question));
    }

    public function test_field_extraction_keeps_only_top_level_names_and_not_values(): void
    {
        $json = '```json'."\n".'{"transactionId":"PAY123","responseCode":200}'."\n```";
        $answer = (new ConversationActions)->formatAnswer($json, 'Indique les noms des champs.');
        $this->assertSame('`transactionId`, `responseCode`.', $answer);
        $this->assertStringNotContainsString('PAY123', $answer);
        $this->assertStringNotContainsString('200', $answer);
    }

    public function test_api_status_does_not_acquire_cancellation_from_another_section(): void
    {
        $chunk = (object) ['chunk_id' => 1, 'document_titre' => 'Vérifier une transaction', 'contenu' => 'Check Status vérifie le statut. Endpoint : GET /status. Autre API : remboursement pour annuler une vente.'];
        $result = (new AnswerVerifier)->verify('Annulez la transaction via l’API de statut [SOURCE:1].', collect([$chunk]));
        $this->assertContains('unsupported_capability', $result->removedReasons());
    }

    public function test_incoming_callback_authentication_is_not_proved_by_outgoing_authentication(): void
    {
        $chunk = (object) ['chunk_id' => 1, 'document_titre' => 'Callback', 'contenu' => 'Le callback reçoit une requête POST avec X-Callback-MediaType. Vos appels aux API PVIT sont authentifiés via X-Secret.'];
        $result = (new AnswerVerifier)->verify('Validez l’en-tête X-Secret fourni par PVit dans les notifications de callback [SOURCE:1].', collect([$chunk]), 'Comment configurer un callback ?');
        $this->assertContains('unsupported_capability', $result->removedReasons());
        $good = (new AnswerVerifier)->verify('Vos appels aux API PVIT sont authentifiés via X-Secret [SOURCE:1].', collect([$chunk]));
        $this->assertTrue($good->acceptable);
    }

    public function test_a_negation_does_not_hide_a_positive_capability_after_mais(): void
    {
        $chunk = (object) ['chunk_id' => 1, 'document_titre' => 'Statut', 'contenu' => 'Check Status vérifie le statut de la transaction. Endpoint : GET /status.'];
        $result = (new AnswerVerifier)->verify('Check Status ne rembourse pas la transaction, mais annulez-la via l’API de statut [SOURCE:1].', collect([$chunk]));
        $this->assertContains('unsupported_capability', $result->removedReasons());
    }

    public function test_negated_subject_does_not_replace_the_requested_subject(): void
    {
        $this->assertSame('secret', (new ConversationPlan)->subject('Rappelle la durée de la clé secrète, pas le délai des exports.'));
    }
}
