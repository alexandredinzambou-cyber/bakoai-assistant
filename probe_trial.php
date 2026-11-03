<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Assistant\CitationGuard;
use App\Services\Rag\RetrievalService;
use Illuminate\Support\Str;

$chunks = app(RetrievalService::class)->search('Comment initier un paiement via pvit ?', 'PVIT', null);
$guard = app(CitationGuard::class);

$source = Str::ascii(mb_strtolower($chunks
    ->map(static fn ($chunk): string => (string) data_get($chunk, 'contenu', ''))
    ->implode("\n")));

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

// chaque bullet individuellement avec [SOURCE:555]
$lines = preg_split('/\R/u', $bloc1);
foreach ($lines as $idx => $line) {
    if (trim($line) === '') {
        continue;
    }
    $result = $guard->validate($line.' [SOURCE:555]', $chunks, true);
    echo 'ligne '.$idx.' => '.($result->valid ? 'VALID' : 'INVALID: '.$result->reason).' :: '.mb_substr($line, 0, 80)."\n";
}

// tokens de la ligne operator_code + verdict par token via le source concatene
$operatorLine = '   - `operator_code` : code de l’opérateur (ex. `AIRTEL_MONEY`, `MOOV_MONEY`, `VISA`, `MASTERCARD`, etc.).';
$normalized = Str::ascii(mb_strtolower($operatorLine));
preg_match_all('/`[^`\r\n]+`|\b[a-z][a-z0-9]*(?:_[a-z0-9]+)+\b|\b\d{2,}(?:[.,]\d+)?\b/iu', $normalized, $toks);
echo "\n=== tokens de la ligne operator_code ===\n";
foreach (array_unique($toks[0]) as $t) {
    $needle = trim($t, '`');
    echo "  '".$t."' => ".(str_contains($source, $needle) ? 'present' : 'ABSENT')."\n";
}

// le chunk 555 complet (contenu) pour voir ce qui manque
echo "\n=== chunk 555 (contenu integral) ===\n";
foreach ($chunks as $chunk) {
    if ((int) $chunk->chunk_id === 555) {
        echo str_replace("\r", '', (string) data_get($chunk, 'contenu', ''))."\n";
    }
}
