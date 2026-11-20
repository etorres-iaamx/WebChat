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

$freshInstall = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 0;
$obsoleteOnFreshInstall = [
    '003_pairing_attempts',
    '010_pairing_events',
    '011_pairing_events_active_identity',
    '012_pairing_state',
    '013_drop_legacy_pairing_attempts',
    '014_drop_legacy_pairing_state',
];
$requiresDrop = ['013_drop_legacy_pairing_attempts', '014_drop_legacy_pairing_state'];
$dropPrivilegeChecked = false;
$hasDropPrivilege = false;

foreach ($files as $file) {
    $version = basename($file, '.sql');
    $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version LIMIT 1');
    $check->execute(['version' => $version]);
    if ($check->fetchColumn() !== false) {
        printf("SKIP %s\n", $version);
        continue;
    }

    if ($freshInstall && in_array($version, $obsoleteOnFreshInstall, true)) {
        printf("SKIP LEGACY %s\n", $version);
        $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)')->execute(['version' => $version]);
        continue;
    }

    if (in_array($version, $requiresDrop, true) && !$dropPrivilegeChecked) {
        $dropPrivilegeChecked = true;
        $databaseName = strtoupper((string) $config->get('DB_DATABASE', ''));
        foreach ($pdo->query('SHOW GRANTS') as $grant) {
            $text = strtoupper(implode(' ', array_map('strval', $grant)));
            $onDatabase = str_contains($text, 'ON *.*') || ($databaseName !== '' && str_contains($text, '`' . $databaseName . '`.*'));
            if ($onDatabase && (str_contains($text, 'ALL PRIVILEGES') || preg_match('/\\bDROP\\b/', $text))) {
                $hasDropPrivilege = true;
                break;
            }
        }
        if (!$hasDropPrivilege) {
            throw new RuntimeException('Las migraciones de limpieza requieren un usuario DBA con privilegio DROP. Ejecútalas con la cuenta administradora de la instancia WebChat y vuelve a ejecutar bin/migrate.php. No se aplicaron cambios.');
        }
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
