<?php
require_once __DIR__ . '/local-backup.php';
date_default_timezone_set('Asia/Manila');

try {
    if ($pdo->query("SHOW TABLES LIKE 'attendance_settings'")->fetchColumn()) {
        $columns = $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_COLUMN);
        if (!$pdo->query('SELECT id FROM attendance_settings WHERE id = 1')->fetchColumn()
            || array_diff(['year_section_snapshot','tracks_departure','time_out','submission_key','submission_hash'], $columns)) {
            throw new RuntimeException('Incomplete attendance migration. Review the schema before proceeding.');
        }
        echo "Attendance migration is already applied. No changes made.\n";
        exit;
    }
    if (empty($argv[1]) || empty($argv[2])) throw new RuntimeException('Usage: php scripts/migrate-attendance-workflow.php PRIVATE_BACKUP_DIRECTORY MYSQLDUMP_PATH');
    $originalColumns = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $originalColumns[$table] = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN);
    }
    $snapshot = static function () use ($pdo, $originalColumns): array {
        $result = [];
        foreach ($originalColumns as $table => $columns) {
            $fields = implode(',', array_map(static fn($column) => '`' . str_replace('`', '``', $column) . '`', $columns));
            $rows = $pdo->query('SELECT ' . $fields . ' FROM `' . $table . '` ORDER BY id')->fetchAll();
            $result[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        }
        return $result;
    };
    $before = $snapshot();
    $backup = backupLocalDatabase($pdo, $argv[1], $argv[2], 'phase5');
    echo 'Private backup: ' . $backup . PHP_EOL;
    $pdo->exec(file_get_contents(__DIR__ . '/../sql/attendance-workflow-migration.sql'));
    if ($snapshot() !== $before) throw new RuntimeException('Original values changed. Preserve the backup and review.');
    echo "Attendance workflow added with unlimited defaults and departure tracking off. All original values unchanged.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception instanceof PDOException ? "Local attendance migration failed. Preserve backups and inspect the schema before retrying.\n" : $exception->getMessage() . PHP_EOL);
    exit(1);
}
