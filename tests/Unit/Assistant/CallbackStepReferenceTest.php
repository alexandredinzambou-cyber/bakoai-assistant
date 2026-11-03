<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\ConversationActions;
use App\Services\Assistant\ConversationPlan;
use App\Services\Assistant\FollowUpDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CallbackStepReferenceTest extends TestCase
{
    public static function references(): array
    {
        return [
            ['expliques moi le 1 en détail', 'number:1'],
            ['Explique-moi le 1 en détail.', 'number:1'],
            ['Détaille le 2, stp.', 'number:2'],
            ['Expliquez-nous le point 2 en détail.', 'number:2'],
            ['Peux-tu m’expliquer le 1 en détail ?', 'number:1'],
            ['Développe le point numéro 2.', 'number:2'],
            ['Montres-moi le 3, s’il vous plaît.', 'number:3'],
            ['Explique le premier point.', 'first'],
            ['et le point 2', 'number:2'],
            ['Le point 3.', 'number:3'],
            ['Et l’étape 2', 'number:2'],
            ['Et le point 2 en détail', 'number:2'],
            ['Et le point -2', 'number:0'],
            ['Et le point 2,5', 'number:0'],
            ['Et le point 2 ou 3', 'number:0'],
            ['Et l’étape -2', 'number:0'],
            ['Et l’étape 2,5', 'number:0'],
            ['Explique-moi le 0.', 'number:0'],
            ['Explique-moi le -1.', 'number:0'],
            ['Explique-moi le 1,5.', 'number:0'],
            ['Explique-moi le 1 ou le 2.', 'number:0'],
        ];
    }

    #[DataProvider('references')]
    public function test_bare_and_point_references_are_conversational_navigation(string $question, string $expected): void
    {
        $this->assertSame($expected, (new ConversationActions)->stepRequest($question));
        $this->assertTrue((new FollowUpDetector)->isFollowUp($question, 'comment recevoir les callbacks'));
    }

    public function test_source_numbers_and_named_topics_are_not_silently_selected_as_steps(): void
    {
        foreach (['Explique-moi la source 1.', 'Explique le code HTTP 401.', 'Explique le paiement.', 'Combien de callbacks reçoit-on ?'] as $question) {
            $this->assertNull((new ConversationActions)->stepRequest($question));
        }
    }

    public function test_detail_requests_are_distinct_from_navigation_and_explicit_brevity(): void
    {
        $actions = new ConversationActions;
        foreach (['expliques moi le point 3 en détail', 'Détaille le 2.', 'Développe le point 1.', 'Peux-tu détailler la dernière étape ?'] as $question) {
            $this->assertTrue($actions->requestsStepExpansion($question));
        }
        foreach (['Et le point 2', 'Montre le point 3', 'Affiche l’étape 3 sans entrer en détail', 'Montre le point 2 sans détails'] as $question) {
            $this->assertFalse($actions->requestsStepExpansion($question));
        }
    }

    public function test_plural_callbacks_keep_their_own_subject_and_reporting_stays_distinct(): void
    {
        foreach (['comment recevoir les callbacks', 'Configurer les webhooks', 'Les notifications de paiement', 'Les callbacks du remboursement'] as $question) {
            $this->assertSame('callback', (new ConversationPlan)->subject($question));
        }
        $this->assertSame('reporting', (new ConversationPlan)->subject('Les callbacks du reporting'));
    }

    public function test_summarizing_the_current_step_is_a_presentation_followup(): void
    {
        foreach (['Résume cette étape en deux points.', 'Récapitule cette étape.', 'Raccourcis cette étape en une phrase.'] as $question) {
            $actions = new ConversationActions;
            $this->assertTrue($actions->refersToPreviousAnswer($question));
            $this->assertTrue((new FollowUpDetector)->isFollowUp($question, 'Détaille le 2.'));
        }
    }
}
