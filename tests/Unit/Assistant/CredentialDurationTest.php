<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\AnswerVerifier;
use Illuminate\Support\Collection;
use Tests\TestCase;

class CredentialDurationTest extends TestCase
{
    private function chunks(): Collection
    {
        return collect([
            (object) ['chunk_id' => 588, 'document_titre' => 'Génération de la clé secrète', 'section' => 'Clé secrète',
                'contenu' => 'La clé secrète a une durée de vie de 3600 secondes.'],
            (object) ['chunk_id' => 592, 'document_titre' => 'Télécharger un export', 'section' => 'Télécharger un export',
                'contenu' => 'Le fichier reste disponible 7 jours après la demande. X-Secret est obligatoire.'],
        ]);
    }

    public function test_export_retention_cannot_be_used_as_token_validity(): void
    {
        $result = (new AnswerVerifier)->verify(
            "Le token d'API (clé secrète) reste valide 7 jours après sa création, comme le précise la durée de disponibilité des exports [SOURCE:592].",
            $this->chunks(), 'Combien de temps la clé secrète est-elle valide ?'
        );
        $this->assertFalse($result->acceptable);
        $this->assertContains('misattributed_detail', $result->removedReasons());
    }

    public function test_documented_key_duration_is_kept(): void
    {
        $result = (new AnswerVerifier)->verify(
            'La clé secrète a une durée de vie de 3600 secondes [SOURCE:588].', $this->chunks()
        );
        $this->assertTrue($result->acceptable);
        $this->assertSame([], $result->removed);
    }

    public function test_a_correct_duration_for_an_export_is_kept(): void
    {
        $result = (new AnswerVerifier)->verify(
            'Le fichier reste disponible 7 jours après la demande [SOURCE:592].', $this->chunks()
        );
        $this->assertTrue($result->acceptable);
    }

    public function test_general_api_advice_cannot_be_kept_as_documented_diagnosis(): void
    {
        $result = (new AnswerVerifier)->verify(
            "La documentation ne précise pas cette erreur.\n\nCependant, selon les règles générales des API sécurisées :\n- Vérifiez X-Secret.",
            $this->chunks(), 'Que signifie une erreur HTTP 401 lors de cet appel ?'
        );
        $this->assertFalse($result->acceptable);
        $this->assertSame('unsupported_general_advice', $result->reason);
    }

    public function test_callback_codes_cannot_be_described_as_authentication_credentials(): void
    {
        $chunks = collect([(object) ['chunk_id' => 607, 'document_titre' => 'Guide Sandbox', 'section' => 'Callback',
            'contenu' => 'Récupérez le code généré associé à cette URL de callback. Générez votre clé d’accès ; incluez-la dans X-Secret pour authentifier vos requêtes.']]);
        $result = (new AnswerVerifier)->verify(
            'Récupérez le code généré associé à cette URL de callback, nécessaire pour authentifier les requêtes futures [SOURCE:607].', $chunks
        );
        $this->assertFalse($result->acceptable);
        $this->assertContains('protocol_contradiction', $result->removedReasons());
    }

    public function test_a_fixed_acknowledgement_code_is_rejected_even_when_other_sentences_are_valid(): void
    {
        $chunks = collect([(object) ['chunk_id' => 648, 'document_titre' => 'Webhook', 'section' => 'Accusé de réception',
            'contenu' => 'Echo dynamique : extraire transactionId et code du payload reçu. Le champ responseCode doit reprendre le code reçu. Exemple : responseCode 200.']]);
        $result = (new AnswerVerifier)->verify('Extraire transactionId du payload reçu [SOURCE:648]. Un champ supplémentaire responseCode avec la valeur 200 confirme la réception [SOURCE:648].', $chunks);
        $this->assertFalse($result->acceptable);
        $this->assertSame('protocol_contradiction', $result->reason);
    }

    public function test_export_retention_cannot_start_at_generation_when_the_source_says_request(): void
    {
        $result = (new AnswerVerifier)->verify('Le fichier d’export reste disponible 7 jours après sa génération [SOURCE:592].', $this->chunks());
        $this->assertFalse($result->acceptable);
        $this->assertSame('protocol_contradiction', $result->reason);
    }

    public function test_a_routing_code_cannot_be_put_in_headers_across_two_sentences(): void
    {
        $chunks = collect([(object) ['chunk_id' => 607, 'document_titre' => 'Guide Sandbox', 'section' => 'Callback',
            'contenu' => 'PVIT génère un code URL associé au callback. X-Secret porte la clé secrète dans les en-têtes.']]);
        $result = (new AnswerVerifier)->verify('PVIT génère un code URL associé à cette configuration. Notez-le pour l’utiliser dans les en-têtes des requêtes [SOURCE:607].', $chunks);
        $this->assertFalse($result->acceptable);
        $this->assertSame('protocol_contradiction', $result->reason);
        $correct = (new AnswerVerifier)->verify('X-Secret porte la clé secrète dans les en-têtes [SOURCE:607].', $chunks);
        $this->assertTrue($correct->acceptable);
    }
}
