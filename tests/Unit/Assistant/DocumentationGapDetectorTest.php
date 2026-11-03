<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\DocumentationGapDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DocumentationGapDetectorTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function gapAnswers(): array
    {
        return [
            'documentation ne precise pas' => ['La documentation PVIT ne précise pas le délai de versement des fonds sur un compte bancaire.'],
            'n est pas documente' => ["Le délai de versement n'est pas documenté dans les extraits fournis."],
            'aucune information' => ['Les extraits ne contiennent aucune information sur les paiements en cryptomonnaie.'],
            'negation scoped to the corpus' => ["Non, **PVit ne propose pas d'API pour les paiements par cryptomonnaie** dans son corpus documentaire officiel."],
            'scoped first with d apres' => ["D'après le contexte documentaire fourni, **PVit ne propose pas d'API pour les paiements par cryptomonnaie**. Les services documentés concernent : - Mobile Money"],
            'scoped first with selon, il n existe pas' => ["Selon la documentation, il n'existe pas d'API de paiement en cryptomonnaie."],
            'after a heading and a separator' => ["## Réponse\n\n---\n\nLa documentation ne mentionne pas de frais spécifiques au Cameroun. [Frais / Section principale]"],
        ];
    }

    #[DataProvider('gapAnswers')]
    public function test_an_answer_whose_main_point_is_a_documentation_gap_is_detected(string $answer): void
    {
        $this->assertTrue((new DocumentationGapDetector)->isGapAnswer($answer));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function regularAnswers(): array
    {
        return [
            'documented procedure with a trailing caveat' => ["Ajoutez votre adresse IP dans le menu Paramétrages > Adresses IPs. [Ajouter une adresse IP]\n\nLa documentation ne précise pas le délai de prise en compte."],
            'negative fact about the API, not the documentation' => ['La notification ne contient pas le fichier du rapport : il faut le télécharger avec son identifiant.'],
            'documented prohibition scoped by selon' => ['Selon la documentation, il ne faut pas exposer la clé secrète côté client (web ou mobile).'],
            'documented constraint scoped by d apres' => ["D'après la documentation, l'URL de callback ne doit pas pointer vers localhost ou 127.0.0.1."],
            'plain answer' => ['Le secret doit être renouvelé avant expiration.'],
            'empty answer' => [''],
            'resolved comparison of two durations' => ["Non, la documentation ne précise pas que la durée d’un export (7 jours) est identique à celle de la clé secrète.\n\nLa clé secrète a une durée de vie de 3600 secondes [1]."],
        ];
    }

    #[DataProvider('regularAnswers')]
    public function test_an_answer_that_gives_documented_content_first_is_not_a_gap(string $answer): void
    {
        $this->assertFalse((new DocumentationGapDetector)->isGapAnswer($answer));
    }
}
