<?php
require_once __DIR__ . '/local-backup.php';

try {
    $columns = $pdo->query('SHOW COLUMNS FROM borrow_requests')->fetchAll(PDO::FETCH_UNIQUE);
    if (isset($columns['pickup_token'])) {
        $indexes = $pdo->query('SHOW INDEX FROM borrow_requests WHERE Key_name="uq_borrow_pickup_token"')->fetchAll();
        if ($columns['pickup_token']['Type'] !== 'char(64)' || $columns['pickup_token']['Null'] !== 'YES'
            || count($indexes) !== 1 || (int)$indexes[0]['Non_unique'] !== 0 || $indexes[0]['Column_name'] !== 'pickup_token') {
            throw new RuntimeException('Unexpected pickup token schema. Review it before proceeding.');
        }
        echo "Pickup QR migration already applied. No changes made.\n"; exit;
    }
    if (empty($argv[1]) || empty($argv[2])) throw new RuntimeException('Usage: php scripts/migrate-pickup-qr.php PRIVATE_BACKUP_DIRECTORY MYSQLDUMP_PATH');
    $before = localRowSnapshot($pdo);
    $backup = backupLocalDatabase($pdo, $argv[1], $argv[2], 'phase3b-qr');
    $pdo->exec(file_get_contents(__DIR__ . '/../sql/pickup-qr-migration.sql'));
    $after = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $rows = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '` ORDER BY id')->fetchAll();
        if ($table === 'borrow_requests') {
            foreach ($rows as &$row) {
                if ($row['pickup_token'] !== null) throw new RuntimeException('Unexpected non-empty migrated token.');
                unset($row['pickup_token']);
            }
            unset($row);
        }
        $after[$table] = ['rows' => count($rows), 'hash' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }
    if ($after !== $before) throw new RuntimeException('Original record verification failed; retain the private backup for review.');
    echo "Pickup token field and unique index added; all original records/settings unchanged.\nPrivate backup: " . $backup . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception instanceof PDOException ? "Local pickup migration failed. Review schema and private backup.\n" : $exception->getMessage() . PHP_EOL);
    exit(1);
}
