<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\FollowUpDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FollowUpDetectorTest extends TestCase
{
    private const PRESTASHOP = 'donnes moi le guide complet pour accepter les paiements sur ma boutique prestashop';

    #[DataProvider('followUps')]
    public function test_a_question_that_points_back_is_a_follow_up(string $question, string $previous): void
    {
        $this->assertTrue((new FollowUpDetector)->isFollowUp($question, $previous));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function followUps(): array
    {
        return [
            'short "et" question' => ['Et pour Moov Money ?', 'Comment fonctionne le callback Airtel Money ?'],
            'pronoun validity' => ['Combien de temps reste-t-il valide ?', 'Comment renouveler le secret API PVIT ?'],
            'pronoun duration variant' => ['Il dure combien de temps ?', 'Comment renouveler le secret API PVIT ?'],
            'export availability' => ['Il reste disponible combien de temps ?', 'Comment télécharger un export ?'],
            'summary of the answer' => ['Peux-tu résumer cela en trois points, sans code ?', 'Et si je ne reçois rien après le paiement ?'],
            'recall the steps' => ['Peux-tu rappeler les étapes dans l’ordre, sans exemple de code ?', 'Comment configurer le callback ?'],
            'nominal summary' => ['Fais un résumé de cette réponse en une phrase.', 'Comment confirmer le callback ?'],
            'beginner explanation' => ['Explique la même chose à un débutant, sans jargon.', 'Comment confirmer le callback ?'],
            'first step of the current plan' => ['Quelle est la première étape ?', 'Comment tester les paiements Airtel ?'],
            'continuation of the plan' => ['Je l’ai faite. Et ensuite ?', 'Quelle est la première étape ?'],
            'implicit simplification' => ['En français plus simple, s’il te plaît.', 'Différence entre X-Secret et code callback ?'],
            'generic procedure summary' => ['Merci, résume la procédure en trois étapes courtes.', 'Comment confirmer le callback ?'],
            'endpoint synonym' => ['Et si je veux seulement la méthode HTTP et le chemin, sans explication ?', 'Paramètres de l’API de frais'],
            'support for the problem' => ['Peux-tu ouvrir un ticket de support pour ce problème ?', 'Erreur HTTP 401'],
            'current API call' => ['Que signifie une erreur HTTP 401 lors de cet appel ?', 'Comment obtenir les frais PVIT ?'],
            'demonstrative' => ['cette clé expire quand ?', 'Comment renouveler le secret PVIT ?'],
            'comparison' => ['Quelle est la différence avec le lien de paiement ?', 'Comment initier un paiement ?'],
            'pareil' => ["C'est pareil pour Moov ?", 'Comment configurer le callback Airtel ?'],
            'subjectless "ça"' => ['Ça coûte combien ?', 'Comment activer le compte GIMAC ?'],
            'subjectless "c\'est"' => ["C'est valable combien de temps ?", 'Comment générer une clé secrète ?'],
            '"ça" on the same subject' => ['Comment ça se passe pour les remboursements ?', 'Comment initier un remboursement ?'],
        ];
    }

    #[DataProvider('newQuestions')]
    public function test_a_question_that_names_its_own_subject_is_not_a_follow_up(string $question, string $previous): void
    {
        $this->assertFalse((new FollowUpDetector)->isFollowUp($question, $previous));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function newQuestions(): array
    {
        return [
            // Real conversation: the PrestaShop guide came back as the answer to this question.
            'definition after a guide' => ["pvit c'est quoi", self::PRESTASHOP],
            'definition of a new term' => ["C'est quoi un callback ?", self::PRESTASHOP],
            '"ça" with a new subject' => ['Comment ça se passe pour les remboursements ?', 'Comment initier un paiement ?'],
            'no reference at all' => ['Comment renouveler le secret PVIT ?', self::PRESTASHOP],
            'explicit topic change' => ['Autre question : comment obtenir les frais PVIT ?', 'Comment renouveler le secret ?'],
            'explicit autre sujet' => ['Autre sujet : mon export a expiré.', 'Comment confirmer le callback ?'],
            'explicit correction' => ['Non, je parle de la clé secrète PVIT.', 'Comment configurer le callback ?'],
            'summary of a named new subject' => ['Peux-tu résumer l’API de remboursement PVIT en trois points ?', 'Que signifie HTTP 401 ?'],
            'format precedes the named subject' => ['Résume en trois points l’API de remboursement.', 'Que signifie HTTP 401 ?'],
        ];
    }

    public function test_without_a_previous_question_a_named_subject_stands_alone(): void
    {
        $this->assertFalse((new FollowUpDetector)->isFollowUp("pvit c'est quoi"));
        $this->assertTrue((new FollowUpDetector)->isFollowUp('Et pour Moov Money ?'));
    }

    public function test_successive_follow_ups_keep_the_resolved_subject_and_a_new_topic_resets_it(): void
    {
        $detector = new FollowUpDetector;
        $initial = 'Comment configurer le callback Airtel Money ?';
        $first = $detector->resolve('Et pour Moov Money ?', ['last_question' => $initial]);
        $second = $detector->resolve('Et si je ne le reçois pas ?', [
            'last_question' => 'Et pour Moov Money ?', 'resolved_question' => $first,
        ]);
        $this->assertStringContainsString('callback', $second);
        $this->assertStringContainsString('Moov Money', $second);
        $this->assertSame('Autre question : comment obtenir les frais PVIT ?', $detector->resolve(
            'Autre question : comment obtenir les frais PVIT ?', ['last_question' => 'Et si je ne le reçois pas ?', 'resolved_question' => $second]
        ));
    }
}
