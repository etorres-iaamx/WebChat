<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Webchat\App\Database;

$config = webchat_config();
$dryRun = in_array('--dry-run', $argv ?? [], true);
$files = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

if ($dryRun) {
    foreach ($files as $file) {
        printf("PLAN %s\n", basename($file, '.sql'));
    }
    printf("Migrations complete. No changes made.\n");
    exit(0);
}

$database = new Database($config);
$pdo = $database->pdo();

$pdo->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(160) NOT NULL PRIMARY KEY,
        applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL);

foreach ($files as $file) {
    $version = basename($file, '.sql');
    $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version LIMIT 1');
    $check->execute(['version' => $version]);
    if ($check->fetchColumn() !== false) {
        printf("SKIP %s\n", $version);
        continue;
    }

    printf("APPLY %s\n", $version);

    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException('Unable to read migration: ' . $file);
    }

    // MariaDB DDL implicitly commits, so the migration file is executed as a
    // unit and recorded immediately after it succeeds.
    $pdo->exec($sql);
    $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)')->execute(['version' => $version]);
}

printf("Migrations complete.\n");
