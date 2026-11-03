<?php

use Illuminate\Contracts\Console\Kernel;

// Run on the Railway application container after migrations, never during a build.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (! $app->environment('production')) {
    fwrite(STDERR, "Cette commande est réservée à la base de production.\n");
    exit(1);
}

$urls = json_decode(file_get_contents(__DIR__.'/../deployment/knowledge-sources.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($urls as $url) {
    if (! is_string($url) || ! str_starts_with($url, 'https://docs.mypvit.pro/fr/')) {
        throw new RuntimeException('Source non autorisée dans le manifeste de déploiement.');
    }
}

$status = $kernel->call('db:seed', ['--force' => true]);
echo $kernel->output();
if ($status !== 0) {
    exit($status);
}

foreach ($urls as $index => $url) {
    echo '['.($index + 1).'/'.count($urls)."] {$url}\n";
    $status = $kernel->call('rag:ingest', ['--url' => [$url], '--moyen' => 'PVIT']);
    echo $kernel->output();
    if ($status !== 0) {
        fwrite(STDERR, "Import interrompu : corriger la source ou la configuration avant de relancer.\n");
        exit($status);
    }
}
