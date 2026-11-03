<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\AnswerVerifier;
use App\Services\Assistant\ContextAssembler;
use Tests\TestCase;

class WebhookScopeTest extends TestCase
{
    public function test_reporting_signature_does_not_require_receiving_the_secret_in_the_webhook(): void
    {
        $chunks = collect([(object) ['chunk_id' => 2, 'document_titre' => 'Webhook de reporting', 'section' => 'Signature', 'contenu' => 'La clé de signature est la clé secrète du compte d’opération, celle que vous envoyez dans X-Secret. Comparez les signatures en temps constant.']]);
        $wrong = (new AnswerVerifier)->verify('Récupérez l’en-tête X-Secret qui contient la clé secrète du compte d’opération [SOURCE:2].', $chunks, 'Comment vérifier la signature du webhook de reporting ?');
        $this->assertFalse($wrong->acceptable);
        $this->assertContains('contradiction', $wrong->removedReasons());
        $right = (new AnswerVerifier)->verify('La clé de signature est la clé secrète du compte d’opération, celle que vous envoyez dans X-Secret [SOURCE:2].', $chunks, 'Comment vérifier la signature du webhook de reporting ?');
        $this->assertTrue($right->acceptable);
    }

    public function test_default_json_cannot_be_presented_as_mandatory_when_xml_is_available(): void
    {
        $chunks = collect([(object) ['chunk_id' => 1, 'document_titre' => 'Webhook de notification de statut', 'section' => 'Format', 'contenu' => 'Pour les transactions PAYMENT, le corps suit X-Callback-MediaType : application/json par défaut ou application/xml.']]);
        $wrong = (new AnswerVerifier)->verify('Pour les transactions PAYMENT, le format JSON est obligatoire [SOURCE:1].', $chunks, 'Comment recevoir les callbacks ?');
        $this->assertFalse($wrong->acceptable);
        $this->assertContains('contradiction', $wrong->removedReasons());
        $right = (new AnswerVerifier)->verify('Le corps suit X-Callback-MediaType : application/json par défaut ou application/xml [SOURCE:1].', $chunks, 'Comment recevoir les callbacks ?');
        $this->assertTrue($right->acceptable);
    }

    private function chunks()
    {
        return collect([
            (object) ['chunk_id' => 1, 'document_titre' => 'Webhook de notification de statut', 'section' => 'Réception', 'lien_officiel' => 'https://docs.mypvit.pro/fr/webhooks/status', 'contenu' => 'PVit envoie une notification POST au callback avec le statut final SUCCESS ou FAILED.'],
            (object) ['chunk_id' => 2, 'document_titre' => 'Webhook de reporting', 'section' => 'Vérifier la signature', 'lien_officiel' => 'https://docs.mypvit.pro/fr/webhooks/reports', 'contenu' => 'La signature utilise X-PVit-Signature et X-PVit-Signature-Timestamp. Refusez un horodatage qui s’écarte de plus de 5 minutes.'],
        ]);
    }

    public function test_transaction_context_excludes_reporting_but_explicit_comparison_keeps_both(): void
    {
        $assembler = new ContextAssembler;
        $this->assertSame([1], $assembler->select($this->chunks(), 'Comment recevoir les callbacks ?')->pluck('chunk_id')->all());
        $this->assertSame([2], $assembler->select($this->chunks(), 'Webhook de reporting : vérifier la signature')->pluck('chunk_id')->all());
        $this->assertSame([1, 2], $assembler->select($this->chunks(), 'Compare les callbacks de paiement et le webhook de reporting')->pluck('chunk_id')->all());
    }

    public function test_a_reporting_citation_cannot_validate_transaction_signature_rules(): void
    {
        $verifier = new AnswerVerifier;
        $answer = 'La signature utilise X-PVit-Signature et X-PVit-Signature-Timestamp [SOURCE:2].';
        $wrong = $verifier->verify($answer, $this->chunks(), 'Comment recevoir les callbacks ?');
        $this->assertFalse($wrong->acceptable);
        $this->assertContains('misattributed_detail', $wrong->removedReasons());
        $right = $verifier->verify($answer, $this->chunks(), 'Webhook de reporting : vérifier la signature');
        $this->assertTrue($right->acceptable);
    }
}
