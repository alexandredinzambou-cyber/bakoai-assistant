<?php

namespace Tests\Unit\Rag;

use App\Services\Rag\ChunkMetadataExtractor;
use PHPUnit\Framework\TestCase;

class ChunkMetadataExtractorTest extends TestCase
{
    public function test_it_extracts_structured_openapi_metadata(): void
    {
        $metadata = (new ChunkMetadataExtractor)->extract(
            "POST /v2/payments/initiate\nInitiation de paiement en environnement sandbox. Statut HTTP 401. Code d'erreur: AUTH_FAILED.",
            'Paiement',
            'PVIT',
            'https://docs.mypvit.pro/openapi/pvit.json',
        );

        $this->assertSame('paiement', $metadata['operation']);
        $this->assertSame('sandbox', $metadata['environment']);
        $this->assertSame('POST', $metadata['http_method']);
        $this->assertSame('/v2/payments/initiate', $metadata['endpoint']);
        $this->assertSame('AUTH_FAILED', $metadata['error_code']);
        $this->assertSame(401, $metadata['http_status']);
        $this->assertSame('PVIT', $metadata['payment_method']);
        $this->assertSame('paiement', $metadata['section_slug']);
        $this->assertSame('error', $metadata['content_type']);
        $this->assertContains('error', $metadata['index_terms']);
        $this->assertStringContainsString('AUTH_FAILED', $metadata['search_text']);
        $this->assertGreaterThan(10, $metadata['token_count']);
    }

    public function test_it_classifies_request_parameters_for_better_indexing(): void
    {
        $metadata = (new ChunkMetadataExtractor)->extract(
            "## Parametres requis\n- Le Code URL (API Secret).\n- Le Code du Compte d'Operation (Test).\n- Le mot de passe de l'API Secret.",
            'Parametres requis',
            'PVIT',
        );

        $this->assertSame('parametres-requis', $metadata['section_slug']);
        $this->assertSame('parameters', $metadata['content_type']);
        // Only the chunk's own structural facts are indexed: no generic synonym lists that
        // would put "donnees" or "mot de passe" in the index of every parameter chunk.
        $this->assertNotContains('donnees', $metadata['index_terms']);
        $this->assertNotContains('transmettre', $metadata['index_terms']);
        $this->assertStringContainsString('parametres requis', mb_strtolower(\Illuminate\Support\Str::ascii($metadata['search_text'])));
    }

    public function test_official_pages_take_their_type_and_operation_from_the_documentation_structure(): void
    {
        $extractor = new ChunkMetadataExtractor;
        $cases = [
            // A webhook payload lists "champs" and would read as 'parameters' by keywords.
            ['https://docs.mypvit.pro/fr/webhooks/status', 'Exemple de notification reçue', 'Les champs du payload : transactionId, code.', 'callback', 'callback'],
            ['https://docs.mypvit.pro/fr/errors/codes', 'Codes d erreur communs', '| 1002 | INVALID_CALLBACK_URL_CODE | This callback URL is invalid | 400 |', 'error', 'gestion des erreurs'],
            ['https://docs.mypvit.pro/fr/api/refund', 'En-têtes', '- X-Callback-MediaType (string) — Type de média du callback.', 'parameters', 'remboursement'],
            ['https://docs.mypvit.pro/fr/api/payment', 'Exemple de requête (cURL)', 'curl -X POST "https://api.mypvit.pro/v2/{{code_url_payment}}/rest"', 'example', 'paiement'],
            ['https://docs.mypvit.pro/fr/intro/integration-guide', '3. Réception du Webhook (Callback)', 'Une fois la transaction traitée, PVit envoie une notification.', 'callback', 'callback'],
            ['https://docs.mypvit.pro/fr/tutoriels/login', 'Étapes pour se connecter', 'Saisissez votre adresse email et votre mot de passe.', 'guide_step', null],
        ];

        foreach ($cases as [$url, $section, $content, $type, $operation]) {
            $metadata = $extractor->extract($content, $section, 'PVIT', $url, 'Titre du document');

            $this->assertSame($type, $metadata['content_type'], $url.' / '.$section);
            $this->assertSame($operation, $metadata['operation'], $url.' / '.$section);
            $this->assertStringContainsString('Titre du document', $metadata['search_text']);
        }
    }

    public function test_it_does_not_invent_ambiguous_metadata(): void
    {
        $metadata = (new ChunkMetadataExtractor)->extract(
            'Cette page presente les environnements sandbox et production.',
            null,
            'PVIT',
        );

        $this->assertNull($metadata['environment']);
        $this->assertNull($metadata['http_method']);
        $this->assertNull($metadata['endpoint']);
        $this->assertNull($metadata['error_code']);
        $this->assertNull($metadata['http_status']);
    }

    public function test_http_method_requires_a_following_endpoint_path(): void
    {
        $metadata = (new ChunkMetadataExtractor)->extract(
            'To authenticate, get the token from the merchant dashboard.',
            'Get the token',
            'PVIT',
        );

        $this->assertNull($metadata['http_method']);
        $this->assertNull($metadata['endpoint']);
    }

    public function test_it_accepts_a_single_segment_endpoint(): void
    {
        $metadata = (new ChunkMetadataExtractor)->extract(
            'La methode documentee est GET /payments.',
            'Liste des paiements',
            'PVIT',
        );

        $this->assertSame('GET', $metadata['http_method']);
        $this->assertSame('/payments', $metadata['endpoint']);
    }

    public function test_safe_projection_keeps_facts_and_field_names_but_never_values(): void
    {
        $extractor = new ChunkMetadataExtractor;
        $projection = $extractor->safeTechnicalProjection(<<<'BLOCK'
POST /payments
Authorization: Bearer secret-value-that-must-disappear
{"amount": 5000, "card_number": "4111111111111111"}
curl --data @payload.json https://docs.mypvit.pro/payments
HTTP/1.1 401 Unauthorized
BLOCK);

        $this->assertStringContainsString('Methode HTTP et endpoint documentes : POST /payments.', $projection);
        $this->assertStringContainsString('Statut HTTP : 401.', $projection);
        // Field and header NAMES are documentation facts, not executable code: allowed.
        $this->assertStringContainsString('Authorization', $projection);
        $this->assertStringContainsString('amount', $projection);
        $this->assertStringContainsString('card_number', $projection);
        // The literal VALUES they carry must never be reproduced.
        $this->assertStringNotContainsString('secret-value-that-must-disappear', $projection);
        $this->assertStringNotContainsString('4111111111111111', $projection);
        $this->assertStringNotContainsString('curl', $projection);
        $this->assertStringNotContainsString('@payload.json', $projection);

        $metadata = $extractor->extract($projection, null, 'PVIT');
        $this->assertSame('POST', $metadata['http_method']);
        $this->assertSame('/payments', $metadata['endpoint']);
        $this->assertSame(401, $metadata['http_status']);
    }

    public function test_safe_projection_recovers_endpoint_and_field_names_from_a_curl_example(): void
    {
        $extractor = new ChunkMetadataExtractor;
        $projection = $extractor->safeTechnicalProjection(<<<'BLOCK'
curl --location 'https://api.mypvit.pro/v2/XXXXXXXX/renew-secret' \
--header 'Content-Type: application/x-www-form-urlencoded' \
--data-urlencode 'operationAccountCode=ACC_PROD_001' \
--data-urlencode 'password=********'
BLOCK);

        $this->assertStringContainsString('Endpoint documente : /v2/XXXXXXXX/renew-secret.', $projection);
        $this->assertStringContainsString('operationAccountCode', $projection);
        $this->assertStringContainsString('password', $projection);
        $this->assertStringContainsString('Content-Type', $projection);
        $this->assertStringNotContainsString('ACC_PROD_001', $projection);
        $this->assertStringNotContainsString('curl', $projection);
        $this->assertStringNotContainsString('--data-urlencode', $projection);
    }

    public function test_safe_projection_returns_empty_string_for_content_with_no_recoverable_facts(): void
    {
        $extractor = new ChunkMetadataExtractor;

        $this->assertSame('', $extractor->safeTechnicalProjection('## Exemple de requete'));
    }
}
