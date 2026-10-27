<?php
require_once __DIR__ . '/local-backup.php';
date_default_timezone_set('Asia/Manila');

try {
    if ($pdo->query("SHOW TABLES LIKE 'reservation_settings'")->fetchColumn()) {
        $settings = $pdo->query('SELECT id, daily_limit, slot_limit FROM reservation_settings WHERE id = 1')->fetch();
        if (!$settings) throw new RuntimeException('Settings table exists without its policy row. Review before retrying.');
        echo "Reservation capacity migration is already applied. No changes made.\n";
        exit;
    }
    if (empty($argv[1]) || empty($argv[2])) throw new RuntimeException('Usage: php scripts/migrate-reservation-capacity.php PRIVATE_BACKUP_DIRECTORY MYSQLDUMP_PATH');
    $before = localRowSnapshot($pdo);
    $backup = backupLocalDatabase($pdo, $argv[1], $argv[2]);
    $pdo->exec(file_get_contents(__DIR__ . '/../sql/reservation-capacity-migration.sql'));
    $after = localRowSnapshot($pdo);
    unset($after['reservation_settings']);
    if ($after !== $before) throw new RuntimeException('Original row verification failed. Preserve the backup and review.');
    echo 'Private backup: ' . $backup . PHP_EOL;
    echo "Capacity settings added with unlimited defaults. All original rows unchanged.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception instanceof PDOException ? "Local capacity migration failed. Preserve backups and inspect the schema.\n" : $exception->getMessage() . PHP_EOL);
    exit(1);
}
