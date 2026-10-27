<?php
require_once __DIR__ . '/local-cli.php';
date_default_timezone_set('Asia/Manila');

function databaseSnapshot(PDO $pdo, array $columns): array
{
    $snapshot = [];
    foreach ($columns as $table => $fields) {
        $hash = hash_init('sha256');
        $count = 0;
        $query = $pdo->query('SELECT `' . implode('`, `', $fields) . '` FROM `' . $table . '` ORDER BY id');
        while ($row = $query->fetch()) {
            hash_update($hash, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $count++;
        }
        $snapshot[$table] = ['rows' => $count, 'checksum' => hash_final($hash)];
    }
    return $snapshot;
}

$optionFile = null;
$errorFile = null;
try {
    $fields = $pdo->query('SHOW COLUMNS FROM borrow_requests')->fetchAll(PDO::FETCH_UNIQUE);
    $hasColumn = isset($fields['checked_out_at']);
    $hasStatuses = str_contains($fields['status']['Type'], "'awaiting_pickup'") && str_contains($fields['status']['Type'], "'checked_out'");
    $reservationFields = $pdo->query('SHOW COLUMNS FROM reservations')->fetchAll(PDO::FETCH_COLUMN);
    $hasReservationDue = in_array('due_date', $reservationFields, true);
    if ($hasColumn && $hasStatuses && $hasReservationDue) {
        echo "The borrowing lifecycle migration is already applied. No changes made.\n";
        exit;
    }
    if ($hasColumn !== $hasStatuses || (!$hasColumn && $fields['status']['Type'] !== "enum('pending','approved','rejected','returned')")) {
        throw new RuntimeException('Unexpected or partial borrowing schema. Review it before migrating.');
    }
    $invalidStock = $pdo->query(
        'SELECT COUNT(*) FROM books b WHERE borrowed_copies > total_copies
         OR borrowed_copies <> (SELECT COUNT(*) FROM borrow_requests br WHERE br.book_id = b.id AND br.status IN ("approved","checked_out"))
                            + (SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.id AND r.status = "fulfilled")
         OR (SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.id AND r.status = "ready") > total_copies - borrowed_copies'
    )->fetchColumn();
    if ($invalidStock) {
        throw new RuntimeException('Existing stock or ready-reservation holds need review. No migration was applied.');
    }
    if (empty($argv[1]) || empty($argv[2]) || !is_file($argv[2])) {
        throw new RuntimeException('Usage: php scripts/migrate-borrowing.php ABSOLUTE_BACKUP_DIRECTORY PATH_TO_MYSQLDUMP');
    }
    $backupDirectory = $argv[1];
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true)) {
        throw new RuntimeException('Could not create the private backup directory.');
    }
    $backupDirectory = realpath($backupDirectory);
    $webRoot = dirname(dirname(__DIR__));
    if (!$backupDirectory || str_starts_with(strtolower($backupDirectory . DIRECTORY_SEPARATOR), strtolower($webRoot . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('Choose a backup directory outside the web server document root.');
    }
    $columns = [];
    foreach (['admins', 'users', 'books', 'borrow_requests', 'reservations', 'attendance', 'announcements', 'sms_logs'] as $table) {
        $columns[$table] = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN);
    }
    $before = databaseSnapshot($pdo, $columns);
    $backup = $backupDirectory . '/before-phase2b-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sql';
    $optionFile = tempnam($backupDirectory, '.mysql-options-');
    $errorFile = tempnam($backupDirectory, '.mysql-errors-');
    chmod($optionFile, 0600);
    chmod($errorFile, 0600);
    $quote = static fn(string $value): string => '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value) . '"';
    $settings = "[client]\nhost=" . $quote(DB_HOST) . "\nuser=" . $quote(DB_USER) . "\npassword=" . $quote(DB_PASS) . "\n";
    if (DB_PORT !== '') $settings .= 'port=' . DB_PORT . "\n";
    file_put_contents($optionFile, $settings);
    unset($settings);
    touch($backup);
    chmod($backup, 0600);
    $command = [$argv[2], '--defaults-extra-file=' . $optionFile, '--single-transaction', '--skip-lock-tables',
        '--no-tablespaces', '--hex-blob', '--default-character-set=utf8mb4', DB_NAME];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $backup, 'w'], 2 => ['file', $errorFile, 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start the local backup utility.');
    fclose($pipes[0]);
    if (proc_close($process) !== 0 || filesize($backup) === 0) {
        unlink($backup);
        throw new RuntimeException('Database backup failed. Check the dump utility and private settings; no migration was applied.');
    }
    $manifest = ['before' => $before, 'original_columns' => $columns];
    file_put_contents($backup . '.json', json_encode($manifest, JSON_PRETTY_PRINT));
    chmod($backup . '.json', 0600);
    echo 'Private backup saved: ' . $backup . PHP_EOL;
    if (databaseSnapshot($pdo, $columns) !== $before) {
        throw new RuntimeException('Data changed while backing up. Stop local activity and run the migration again.');
    }
    if (!$hasColumn) {
        $pdo->exec(file_get_contents(__DIR__ . '/../sql/borrowing-lifecycle-migration.sql'));
    }
    if (!$hasReservationDue) {
        $pdo->exec(file_get_contents(__DIR__ . '/../sql/reservation-due-date-migration.sql'));
    }
    if (databaseSnapshot($pdo, $columns) !== $before) {
        throw new RuntimeException('Original data verification changed. Keep the backup and review before using the new workflow.');
    }
    echo "Migration applied. All original table row counts, values, statuses, and dates are unchanged.\n";
} catch (PDOException $exception) {
    fwrite(STDERR, "Database migration failed. Preserve any backup and inspect the local schema before retrying.\n");
    $failed = true;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    $failed = true;
} finally {
    foreach ([$optionFile, $errorFile] as $temporary) {
        if ($temporary && is_file($temporary)) unlink($temporary);
    }
}

exit(isset($failed) ? 1 : 0);
