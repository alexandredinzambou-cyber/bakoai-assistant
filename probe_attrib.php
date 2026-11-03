<?php

require __DIR__.'/vendor/autoload.php';

use App\Services\Assistant\CitationGuard;
use App\Services\Assistant\ResponseGuard;
use Illuminate\Support\Collection;

$guard = new CitationGuard;
$responseGuard = new ResponseGuard;

// Chunks calqués sur le corpus réel (495, 496-497, 513).
$chunks = new Collection([
    (object) [
        'chunk_id' => 495,
        'document_titre' => "Initier un paiement",
        'section' => 'Exemple de requête (cURL)',
        'contenu' => "Methode HTTP et endpoint documentes : POST /v2/{{code_url_payment}}/rest.\nChamps et valeurs documentes dans cet exemple : agent = AGENT123, amount = 150, product = PRODUCT123, reference = REF1234567890, service = RESTFUL, kind = MOBILE_MONEY, operator_code = AIRTEL_MONEY.",
    ],
    (object) [
        'chunk_id' => 496,
        'document_titre' => "Initier un paiement",
        'section' => 'Corps de la requête',
        'contenu' => "- amount (number · double, Obligatoire) — Le montant de la transaction (valeur positive).\n- reference (string, Obligatoire) — Référence alphanumérique unique de la transaction (max 20 car.).\n- operator_code (string, Obligatoire) — Code de l'opérateur.\n- merchant_operation_account_code (string, Obligatoire) — Code du compte d'opération marchand (ACC_xxx).\n- customer_account_number (string, Obligatoire) — Numéro de compte du client.\n- transaction_type (string, Obligatoire) — Type de transaction : PAYMENT.\n- owner_charge (string, Obligatoire) — Qui supporte les frais (CUSTOMER ou MERCHANT).",
    ],
    (object) [
        'chunk_id' => 497,
        'document_titre' => "Initier un paiement",
        'section' => 'En-têtes',
        'contenu' => "## En-têtes\n\n- X-Secret (string, Obligatoire) — Clé secrète authentifiant l'opération.\n- X-Callback-MediaType (string) — Type de média optionnel pour la réponse de callback.\n- Content-Type (string, Obligatoire) — application/json.",
    ],
    (object) [
        'chunk_id' => 492,
        'document_titre' => "Initier un paiement",
        'section' => 'Corps',
        'contenu' => "## Initier un paiement\n\nBASE URL: https://api.mypvit.pro/v2\n\nPOST /{code-url-payment}/rest",
    ],
]);

// La forme exacte qui a été refusée à 15:10 : tout est correct, AUCUNE citation.
$answer = "Pour initier un paiement via PVIT, votre backend doit envoyer une requête **POST** à l'API de paiement REST avec les données suivantes :\n\n"
    ."1. **Endpoint** : `POST /v2/{{code_url_payment}}/rest`\n\n"
    ."2. **Champs obligatoires** :\n"
    ."   - `amount` : montant de la transaction.\n"
    ."   - `reference` : votre référence marchande.\n"
    ."   - `transaction_type` : toujours `PAYMENT`.\n"
    ."   - `owner_charge` : `CUSTOMER` ou `MERCHANT`.\n\n"
    ."3. **En-têtes requis** :\n"
    ."   - `X-Secret` : clé secrète de votre compte PVIT.\n"
    ."   - `Content-Type` : `application/json`.\n\n"
    ."```curl\ncurl -X POST \"https://api.mypvit.pro/v2/{{code_url_payment}}/rest\" \\\n-H \"X-Secret: {{secret_key}}\" \\\n-H \"Content-Type: application/json\" \\\n-d '{\"amount\": 150, \"reference\": \"REF1234567890\", \"transaction_type\": \"PAYMENT\", \"owner_charge\": \"CUSTOMER\"}'\n```";

$first = $guard->validate($answer, $chunks, true);
echo 'validation directe => ', $first->valid ? 'VALID' : 'INVALID: '.$first->reason, PHP_EOL;

// Essais unitaires : chaque bloc + tag candidat, raison exacte en cas d echec.
foreach ([
    'endpoint' => "1. **Endpoint** : `POST /v2/{{code_url_payment}}/rest` [SOURCE:495]",
    'champs' => "2. **Champs obligatoires** :\n   - `amount` : montant de la transaction. [SOURCE:496]",
    'entetes' => "3. **En-têtes requis** :\n   - `X-Secret` : clé secrète de votre compte PVIT. [SOURCE:497]",
    'entetes495' => "3. **En-têtes requis** :\n   - `X-Secret` : clé secrète de votre compte PVIT. [SOURCE:495]",
] as $label => $candidate) {
    $r = $guard->validate($candidate, $chunks, true);
    echo 'essai ', $label, ' => ', $r->valid ? 'VALID' : 'INVALID: '.$r->reason, PHP_EOL;
}

// Simule la passe d'attribution du contrôleur via reflection.
$controllerClass = new ReflectionClass(App\Http\Controllers\MistralRawController::class);
$method = $controllerClass->getMethod('answerWithCitationsAttributed');
$controller = $controllerClass->newInstanceWithoutConstructor();

$attributed = $method->invoke($controller, $answer, $chunks, $guard, $responseGuard);

if ($attributed === null) {
    echo 'attribution => null (refus maintenu)', PHP_EOL;

    // Diagnostique pourquoi la passe n'a rien produit : re-simule la boucle d'attribution.
    $exampleBlocks = [];
    $text = preg_replace_callback(
        '/(?:```|~~~)([A-Za-z0-9_-]*)[ \t]*\R([\s\S]*?)(?:```|~~~)/u',
        function (
            array $m) use (&$exampleBlocks, $responseGuard): string {
            if ($responseGuard->isDocumentedApiExample($m[1], $m[2])) {
                $exampleBlocks[] = $m[0];

                return '';
            }

            return $m[0];
        },
        $answer,
    );
    echo 'blocs exemple extraits: ', count($exampleBlocks), PHP_EOL;

    $blocks = preg_split('/(?:\R[ \t]*){2,}/u', trim((string) $text)) ?: [];
    $chunkBodies = $chunks->mapWithKeys(fn (
        $c): array => [(int) $c->chunk_id => preg_replace('/\s+/u', ' ', mb_strtolower(
        \Illuminate\Support\Str::ascii((string) $c->contenu)))]);

    foreach ($blocks as $block) {
        $visible = trim((string) preg_replace('/\[\s*SOURCE\s*:\s*\d+\s*\]/iu', '', $block));
        $hasCitation = preg_match('/\[\s*SOURCE\s*:\s*\d+\s*\]/iu', $block) === 1;
        preg_match_all('/`[^`\r\n]+`|\b[a-z][a-z0-9]*(?:_[a-z0-9]+)+\b|\b\d{2,}(?:[.,]\d+)*\b/iu', $visible, $tokens);
        $tokens = array_values(array_filter(array_map('trim', array_map(fn (
            $t) => trim($t, '`'), $tokens[0]))));
        $joined = mb_strtolower(\Illuminate\Support\Str::ascii(implode(' ', $tokens)));
        $best = ['id' => null, 'score' => 0];

        foreach ($chunkBodies as $cid => $body) {
            $score = 0;

            foreach ($tokens as $t) {
                if (str_contains($body, mb_strtolower(\Illuminate\Support\Str::ascii($t)))) {
                    $score++;
                }
            }

            if ($score > $best['score']) {
                $best = ['id' => $cid, 'score' => $score];
            }
        }
        $preview = mb_substr(preg_replace('/\s+/u', ' ', $visible), 0, 60);
        echo "  bloc [cite=".var_export($hasCitation, true)." tokens=".count($tokens).
            " best={$best['id']}#{$best['score']}] $preview", PHP_EOL;
    }
} else {
    echo 'attribution => candidate produit', PHP_EOL;
    $revalidated = $guard->validate($attributed, $chunks, true);
    echo 'revalidation  => ', $revalidated->valid ? 'VALID — la reponse serait acceptee' : 'INVALID: '.$revalidated->reason, PHP_EOL;
    echo PHP_EOL, '--- extrait candidat ---', PHP_EOL, mb_substr($attributed, 0, 600), PHP_EOL;
}

// Contre-epreuve : un fait invente ne doit PAS etre sauve par l'attribution.
$poisoned = str_replace('montant de la transaction.', 'montant de la transaction. Le delai est de 1 a 24h.', $answer);
$poisonedResult = $method->invoke($controller, $poisoned, $chunks, $guard, $responseGuard);
echo PHP_EOL, 'contre-epreuve (fait invente) => ', $poisonedResult === null ? 'null (refus maintenu, OK)' : 'candidat accepte (BUG)', PHP_EOL;
