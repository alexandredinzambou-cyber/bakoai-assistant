<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Assistant\CitationGuard;
use App\Services\Rag\RetrievalService;

$chunks = app(RetrievalService::class)->search('Comment initier un paiement via pvit ?', 'PVIT', null);
$guard = app(CitationGuard::class);

$candidate = file_get_contents(__DIR__.'/.tmp/candidate.txt');

// validation paragraphe par paragraphe (meme split que paragraphs(): lignes vides)
$paragraphs = preg_split('/(?:\R[ \t]*){2,}/u', trim($candidate)) ?: [];
foreach ($paragraphs as $i => $p) {
    $r = $guard->validate($p, $chunks, true);
    echo 'para '.$i.' => '.($r->valid ? 'VALID' : 'INVALID: '.$r->reason).' :: '.mb_substr(str_replace("\n", ' | ', $p), 0, 100)."\n";
}

// le para 0 seul ("Pour initier..." + 3 lead-ins) puis para 0+1 cumules, pour reproduire le missing/unsupported
echo "\n--- cumuls ---\n";
$paras = array_values($paragraphs);
for ($n = 1; $n <= count($paras); $n++) {
    $partial = implode("\n\n", array_slice($paras, 0, $n));
    $r = $guard->validate($partial, $chunks, true);
    echo 'cumul '.$n." paras => ".($r->valid ? 'VALID' : 'INVALID: '.$r->reason)."\n";
}
