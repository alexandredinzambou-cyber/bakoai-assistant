<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Assistant\CitationGuard;
use App\Services\Rag\RetrievalService;

$chunks = app(RetrievalService::class)->search('Comment initier un paiement via pvit ?', 'PVIT', null);
$guard = app(CitationGuard::class);

$bloc1 = <<<'TXT'
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
TXT;

$r = $guard->validate($bloc1.' [SOURCE:457] [SOURCE:555]', $chunks, true);
echo 'bloc1 + [457][555] => '.($r->valid ? 'VALID' : 'INVALID: '.$r->reason)."\n";

// quelle bullet echoue contre la COMBINAISON 457+555 ?
$lines = preg_split('/\R/u', $bloc1);
foreach ($lines as $idx => $line) {
    if (trim($line) === '') {
        continue;
    }
    $r = $guard->validate($line.' [SOURCE:457] [SOURCE:555]', $chunks, true);
    echo 'ligne '.$idx.' + [457][555] => '.($r->valid ? 'VALID' : 'INVALID: '.$r->reason).' :: '.mb_substr($line, 0, 70)."\n";
}

// bissection: prefixes croissants du bloc complet
$linesFiltered = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));
for ($n = 1; $n <= count($linesFiltered); $n++) {
    $partial = implode("\n", array_slice($linesFiltered, 0, $n));
    $r = $guard->validate($partial.' [SOURCE:457] [SOURCE:555]', $chunks, true);
    echo 'prefix '.$n.' lignes => '.($r->valid ? 'VALID' : 'INVALID: '.$r->reason)."\n";
}
