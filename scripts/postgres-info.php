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

$pdo = new PDO("pgsql:host={$host};port={$port};dbname={$database}", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$workspaceControlPath = str_replace('\\', '/', dirname(__DIR__, 2).'/.pgvector-pg18/share');

if (is_dir($workspaceControlPath.'/extension')) {
    $quotedControlPath = $pdo->quote($workspaceControlPath.';$system');
    $pdo->exec("SET extension_control_path = {$quotedControlPath}");
}

$queries = [
    'version' => 'select version()',
    'server_version' => 'show server_version',
    'extension_control_path' => 'show extension_control_path',
    'dynamic_library_path' => 'show dynamic_library_path',
];

foreach ($queries as $label => $sql) {
    try {
        echo $label.': '.$pdo->query($sql)->fetchColumn().PHP_EOL;
    } catch (Throwable $exception) {
        echo $label.': '.$exception->getMessage().PHP_EOL;
    }
}

$extensions = $pdo->query("select name, default_version, installed_version from pg_available_extensions where name = 'vector'")->fetchAll(PDO::FETCH_ASSOC);
$installed = $pdo->query("select extname as name, extversion as version from pg_extension where extname = 'vector'")->fetchAll(PDO::FETCH_ASSOC);

echo 'available_extensions: '.json_encode($extensions, JSON_UNESCAPED_SLASHES).PHP_EOL;
echo 'installed_extensions: '.json_encode($installed, JSON_UNESCAPED_SLASHES).PHP_EOL;
