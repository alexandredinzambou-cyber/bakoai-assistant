<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\MistralRawController;
use App\Services\Assistant\CitationGuard;
use App\Services\Assistant\ResponseGuard;
use App\Services\Rag\RetrievalService;
use Illuminate\Support\Str;

// Reconstruction fidele de la reponse refusee a 18:22 (preview log 1500 chars + tail curl complete
// avec les valeurs documentees du chunk 495, memes guillemets echappes que le modele).
$answer = <<<'TXT'
Pour initier un paiement via PVIT, votre backend doit envoyer une requête **POST** à l’API de paiement REST avec les données suivantes :

2. **Champs obligatoires** (exemple avec valeurs placeholders) :
   - `agent` : identifiant de votre agent (ex. `AGENT123`).
   - `amount` : montant de la transaction (ex. `150`).
   - `product` : code de votre produit (ex. `PRODUCT123`).
   - `reference` : référence marchande unique (ex. `REF1234567890`).
   - `service` : `RESTFUL`.
   - `callback_url_code` : code de votre URL de callback (à configurer dans votre compte PVIT).
   - `customer_account_number` : numéro de téléphone ou compte client (ex. `074111111`).
   - `merchant_operation_account_code` : code du compte d’opération lié à un opérateur (ex. `AIRTEL_MONEY` ou `MOOV_MONEY`).
   - `transaction_type` : `PAYMENT`.
   - `owner_charge` : `CUSTOMER` (le client supporte les frais).
   - `owner_charge_operator` : `CUSTOMER`.
   - `operator_code` : code de l’opérateur (ex. `AIRTEL_MONEY`, `MOOV_MONEY`, `VISA`, `MASTERCARD`, etc.).

3. **En-têtes requis** :
   - `X-Secret` : clé secrète fournie par PVIT (à remplacer par `{{secret_key}}`).
   - `X-Callback-MediaType` : `application/json`.
   - `Content-Type` : `application/json`.

---
**Exemple de requête cURL** (à adapter avec vos valeurs réelles) :
```curl
curl -X POST \"https://api.mypvit.pro/v2/{{code_url_payment}}/rest\" \
  -H \"X-Secret: {{secret_key}}\" \
  -H \"X-Callback-MediaType: application/json\" \
  -H \"Content-Type: application/json\" \
  -d '{\"agent\": \"AGENT123\", \"amount\": 150, \"reference\": \"REF1234567890\", \"service\": \"RESTFUL\", \"callback_url_code\": \"{{callback_url_code}}\", \"customer_account_number\": \"074111111\", \"merchant_operation_account_code\": \"{{operation_account_code}}\", \"transaction_type\": \"PAYMENT\", \"owner_charge\": \"CUSTOMER\", \"operator_code\": \"AIRTEL_MONEY\"}'
```
TXT;

$chunks = app(RetrievalService::class)->search('Comment initier un paiement via pvit ?', 'PVIT', null);
echo 'retrieval chunks: '.$chunks->pluck('chunk_id')->implode(', ')."\n";

$guard = app(CitationGuard::class);
$responseGuard = app(ResponseGuard::class);
$controller = app(MistralRawController::class);

// --- diagnostic par bloc : factsExistInRetrieval + tokens manquants ---
$source = Str::ascii(mb_strtolower($chunks
    ->map(static fn ($chunk): string => (string) data_get($chunk, 'contenu', ''))
    ->implode("\n")));

$blocks = preg_split('/(?:\R[ \t]*){2,}/u', trim($answer)) ?: [];
foreach ($blocks as $i => $block) {
    $visible = trim((string) preg_replace('/\[\s*SOURCE\s*:\s*\d+\s*\]/iu', '', $block));
    if ($visible === '') {
        continue;
    }
    $ok = $guard->factsExistInRetrieval($visible, $chunks);
    echo 'bloc '.$i.': '.($ok ? 'OK   ' : 'FAIL ').':: '.mb_substr(str_replace("\n", ' | ', $visible), 0, 90)."\n";
    if (! $ok) {
        preg_match_all('/`[^`\r\n]+`|\b[a-z][a-z0-9]*(?:_[a-z0-9]+)+\b|\b\d{2,}(?:[.,]\d+)?\b|\b\d+(?:[.,]\d+)?(?:h|s|j|min)\b/iu', $visible, $tokens);
        foreach (array_unique($tokens[0] ?? []) as $token) {
            // test isole via la VRAIE implementation (token court => verdict fiable)
            if (! $guard->factsExistInRetrieval(trim($token, '`'), $chunks)) {
                echo "    token absent du retrieval: '".$token."'\n";
            }
        }
        // bissection par ligne: quelle ligne fait echouer le bloc ?
        foreach (preg_split('/\R/u', $visible) as $lineIdx => $line) {
            if (trim($line) === '') {
                continue;
            }
            if (! $guard->factsExistInRetrieval($line, $chunks)) {
                echo '    ligne '.$lineIdx.' FAIL: '.mb_substr($line, 0, 120)."\n";
            }
        }
        // bissection par caracteres: localise la sous-chaine minimale fautive
        $lo = 0;
        $hi = mb_strlen($visible);
        if (! $guard->factsExistInRetrieval(mb_substr($visible, 0, 1), $chunks) || $guard->factsExistInRetrieval($visible, $chunks)) {
            // le bloc entier passe ici => incoherence, on ne bissecte pas
        } else {
            while ($hi - $lo > 4) {
                $mid = intdiv($lo + $hi, 2);
                if ($guard->factsExistInRetrieval(mb_substr($visible, 0, $mid), $chunks)) {
                    $lo = $mid;
                } else {
                    $hi = $mid;
                }
            }
            echo '    zone punitive (prefixe '.mb_substr($visible, max(0, $lo - 30), 30).'[>>'.mb_substr($visible, $lo, $hi - $lo)."<<): "
                .mb_substr($visible, $lo, 40)."\n";
        }
    }
}

// --- diagnostic approfondi du bloc curl: tokens extraits + verdict par token ---
$blocksFull = preg_split('/(?:\R[ \t]*){2,}/u', trim($answer)) ?: [];
$curlBlock = null;
foreach ($blocksFull as $b) {
    if (str_contains($b, '```curl')) {
        $curlBlock = $b;
    }
}
if ($curlBlock !== null) {
    $normFn = new ReflectionMethod(CitationGuard::class, 'normalizeEvidence');
    $normFn->setAccessible(true);
    preg_match_all('/`[^`\r\n]+`|\b[a-z][a-z0-9]*(?:_[a-z0-9]+)+\b|\b\d{2,}(?:[.,]\d+)?\b|\b\d+(?:[.,]\d+)?(?:h|s|j|min)\b/iu', $curlBlock, $toks);
    echo "\n=== tokens du bloc curl (".count($toks[0]).') ===';
    foreach (array_unique($toks[0]) as $t) {
        $normed = $normFn->invoke($guard, trim($t, '`'));
        $present = str_contains($source, $normed);
        echo "\n  '".$t."' => norm '".$normed."' => ".($present ? 'present' : 'ABSENT');
    }
    echo "\n";
}

// --- test de la theorie du token fantome (fences qui s'apparient) ---
$fantom1 = "```curl\ncurl -X POST https://api.mypvit.pro/v2/{{code_url_payment}}/rest -H 'X-Secret: {{secret_key}}'\n```";
$fantom2 = "curl -X POST https://api.mypvit.pro/v2/{{code_url_payment}}/rest -H 'X-Secret: {{secret_key}}'";
echo "\nfence-curl AVEC fences => ".($guard->factsExistInRetrieval($fantom1, $chunks) ? 'OK' : 'FAIL (token fantome !)')."\n";
echo "fence-curl SANS fences => ".($guard->factsExistInRetrieval($fantom2, $chunks) ? 'OK' : 'FAIL')."\n";
echo "MOOV_MONEY => ".($guard->factsExistInRetrieval('MOOV_MONEY', $chunks) ? 'OK' : 'FAIL (absent verbatim)')." | 'Moov Money' => ".($guard->factsExistInRetrieval('Moov Money', $chunks) ? 'OK' : 'FAIL')."\n";

// --- passe d'attribution via reflexion ---
$ref = new ReflectionMethod(MistralRawController::class, 'answerWithCitationsAttributed');
$ref->setAccessible(true);
$candidate = $ref->invoke($controller, $answer, $chunks, $guard, $responseGuard);

echo "\nattribution => ".($candidate === null ? 'null (refus maintenu)' : 'candidat produit')."\n";
if ($candidate !== null) {
    echo "=== CANDIDAT ===\n".$candidate."\n=== FIN CANDIDAT ===\n";
    file_put_contents(__DIR__.'/.tmp/candidate.txt', $candidate);
    $validation = $guard->validate($candidate, $chunks, true);
    echo 'validation finale => '.($validation->valid ? 'VALID — la reponse serait acceptee' : 'INVALID: '.$validation->reason)."\n";
}
