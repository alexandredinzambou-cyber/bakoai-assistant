<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\MistralRawController;
use App\Services\Assistant\CitationGuard;
use App\Services\Assistant\ResponseGuard;
use App\Services\Rag\RetrievalService;

// Reponse du tirage 19:30 (log, UTC+1) : citations mal placees => unsupported_claim.
// Tail tronquee du preview reconstituee fidelement (la phrase est coupee a "produc...").
$answer = <<<'TXT'
Pour initier un paiement via PVIT, votre backend doit envoyer une requête **POST** à l’API de paiement REST avec les données essentielles de la transaction. Voici les étapes clés et les éléments obligatoires à inclure :

1. **Endpoint et méthode** :

2. **Champs obligatoires** (à adapter selon votre cas d'usage) :
   - `amount` : Montant de la transaction (ex. `150` pour 150 XAF).
   - `reference` : Votre référence marchande unique (ex. `REF1234567890`).
   - `operator_code` : Code de l’opérateur (ex. `AIRTEL_MONEY`, `MOOV_MONEY`, etc.).
   - `merchant_operation_account_code` : Code du compte d’opération lié à l’opérateur [SOURCE:360].
   - `customer_account_number` : Numéro de téléphone ou identifiant client (ex. `074111111`).
   - `transaction_type` : Toujours `PAYMENT`.
   - `owner_charge` : `CUSTOMER` (le client supporte les frais) ou `MERCHANT` selon votre configuration.

3. **En-têtes requis** :
   - `X-Secret` : Votre clé secrète PVIT (à remplacer par `{{secret_key}}`).
   - `X-Callback-MediaType` : `application/json`.
   - `Content-Type` : `application/json`. [SOURCE:555]

---
**Exemple de requête cURL** (à personnaliser avec vos valeurs) :

[SOURCE:555]

---
**Remarques** :
- La première réponse n’est pas toujours définitive : gérez les callbacks pour les résultats finaux [SOURCE:233].
- Pour les tests en Sandbox, utilisez des montants > 1000 XAF pour simuler des échecs [SOURCE:442].
- Assurez-vous que le `merchant_operation_account_code` est bien configuré en production avant de recevoir des paiements réels.
TXT;

$chunks = app(RetrievalService::class)->search('Comment initier un paiement via pvit ?', 'PVIT', null);
$guard = app(CitationGuard::class);
$controller = app(MistralRawController::class);

// l'entree du pipeline : validate() doit echouer en unsupported_claim
$initial = $guard->validate($answer, $chunks, true);
echo 'validation initiale => '.($initial->valid ? 'VALID (inedite)' : 'INVALID: '.$initial->reason)."\n";

$ref = new ReflectionMethod(MistralRawController::class, 'answerWithCitationsAttributed');
$ref->setAccessible(true);
$candidate = $ref->invoke($controller, $answer, $chunks, $guard, app(ResponseGuard::class));

echo 'attribution => '.($candidate === null ? 'null (refus maintenu)' : 'candidat produit')."\n";
if ($candidate !== null) {
    file_put_contents(__DIR__.'/.tmp/candidate2.txt', $candidate);
    $final = $guard->validate($candidate, $chunks, true);
    echo 'validation finale => '.($final->valid ? 'VALID — la reponse serait acceptee' : 'INVALID: '.$final->reason)."\n";
}
