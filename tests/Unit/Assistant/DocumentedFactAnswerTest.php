<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\DocumentedFactAnswer;
use PHPUnit\Framework\TestCase;

class DocumentedFactAnswerTest extends TestCase
{
    private function chunk(int $days = 7): object
    {
        return (object) ['chunk_id' => $days, 'document_titre' => 'Télécharger un export', 'contenu' => 'Le fichier reste disponible **'.$days.' jours** après la demande.'];
    }

    public function test_export_duration_is_extracted_with_its_real_anchor_and_citation(): void
    {
        $fact = (new DocumentedFactAnswer)->exportAvailability('Pendant combien de temps puis-je le télécharger ?', 'Comment télécharger un export PVIT ?', collect([$this->chunk()]));
        $this->assertSame('Le fichier d’export reste disponible 7 jours après la demande [SOURCE:7].', $fact['answer']);
    }

    public function test_credential_comparisons_and_unrelated_questions_do_not_use_an_export_fact(): void
    {
        $service = new DocumentedFactAnswer;
        $this->assertNull($service->exportAvailability('Cette durée est-elle celle de la clé secrète ?', 'Export PVIT', collect([$this->chunk()])));
        $this->assertNull($service->exportAvailability('Comment le télécharger ?', 'Export PVIT', collect([$this->chunk()])));
        $this->assertNull($service->exportAvailability('Pendant combien de temps ?', 'Callback PVIT', collect([$this->chunk()])));
    }

    public function test_conflicting_or_missing_facts_are_never_invented(): void
    {
        $service = new DocumentedFactAnswer;
        $this->assertNull($service->exportAvailability('Combien de temps ?', 'Export PVIT', collect([$this->chunk(7), $this->chunk(5)])));
        $this->assertNull($service->exportAvailability('Combien de temps ?', 'Export PVIT', collect()));
    }

    public function test_endpoint_and_method_come_from_the_matching_document_not_a_neighbour(): void
    {
        $chunks = collect([
            (object) ['chunk_id' => 1, 'document_titre' => 'Paiement fractionné', 'contenu' => 'Endpoint : POST /split'],
            (object) ['chunk_id' => 575, 'document_titre' => 'Obtenir les frais d’une transaction', 'contenu' => "Base URL : https://api.mypvit.pro/v2\nEndpoint : GET /{code-url-fees}/get-fees"],
        ]);
        $result = (new DocumentedFactAnswer)->endpoint('Donne juste le nom de l’endpoint et sa méthode HTTP.', 'Comprendre les champs de l’API de frais', $chunks);
        $this->assertSame('GET /{code-url-fees}/get-fees [SOURCE:575].', $result['answer']);
    }

    public function test_conflicting_endpoints_and_an_unspecified_subject_are_not_guessed(): void
    {
        $service = new DocumentedFactAnswer;
        $chunks = collect([
            (object) ['chunk_id' => 1, 'document_titre' => 'API de frais', 'contenu' => 'Endpoint : GET /fees'],
            (object) ['chunk_id' => 2, 'document_titre' => 'API de frais', 'contenu' => 'Endpoint : POST /fees'],
        ]);
        $this->assertNull($service->endpoint('Quel endpoint et méthode HTTP ?', 'API de frais', $chunks));
        $this->assertNull($service->endpoint('Quel endpoint et méthode HTTP ?', 'API PVIT', $chunks));
    }
}
