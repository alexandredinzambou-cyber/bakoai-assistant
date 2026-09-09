<?php

$envPath = dirname(__DIR__).'/.env';
$lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

if ($lines === false) {
    fwrite(STDERR, "Unable to read .env\n");
    exit(1);
}

$env = [];

foreach ($lines as $line) {
    $line = trim($line);

    if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
        continue;
    }

    [$key, $value] = explode('=', $line, 2);
    $env[$key] = trim($value, " \t\n\r\0\x0B\"'");
}

$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '5432';
$user = $env['DB_USERNAME'] ?? 'postgres';
$pass = $env['DB_PASSWORD'] ?? '';
$database = $env['DB_DATABASE'] ?? 'bakoai_assistant';

if (! preg_match('/^[A-Za-z0-9_]+$/', $database)) {
    fwrite(STDERR, "Invalid DB_DATABASE value: {$database}\n");
    exit(1);
}

$pdo = new PDO("pgsql:host={$host};port={$port};dbname=postgres", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$statement = $pdo->prepare('select 1 from pg_database where datname = ?');
$statement->execute([$database]);

if ($statement->fetchColumn()) {
    echo "Database already exists: {$database}\n";
    exit(0);
}

$pdo->exec('CREATE DATABASE '.$database);

echo "Database created: {$database}\n";
