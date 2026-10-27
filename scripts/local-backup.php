<?php
require_once __DIR__ . '/local-cli.php';

function localRowSnapshot(PDO $pdo): array
{
    $result = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $rows = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '` ORDER BY id')->fetchAll();
        $result[$table] = ['rows' => count($rows), 'hash' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }
    return $result;
}

function backupLocalDatabase(PDO $pdo, string $directory, string $dumpTool, string $label = 'phase4'): string
{
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('Cannot create backup directory.');
    $directory = realpath($directory);
    $webRoot = realpath(dirname(__DIR__, 2));
    if (!$directory || !$webRoot || str_starts_with($directory . '/', $webRoot . '/')) {
        throw new RuntimeException('Choose a private backup directory outside the web root.');
    }
    if (!is_file($dumpTool)) throw new RuntimeException('Specify the local mysqldump executable.');
    if (!preg_match('/^[a-z0-9-]+$/', $label)) throw new RuntimeException('Invalid backup label.');
    $backup = $directory . '/before-' . $label . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sql';
    $optionFile = tempnam($directory, '.mysql-options-');
    $errorFile = tempnam($directory, '.mysql-errors-');
    chmod($optionFile, 0600);
    chmod($errorFile, 0600);
    try {
        $quote = static fn(string $value): string => '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value) . '"';
        $settings = "[client]\nhost=" . $quote(DB_HOST) . "\nuser=" . $quote(DB_USER) . "\npassword=" . $quote(DB_PASS) . "\n";
        if (DB_PORT !== '') $settings .= 'port=' . DB_PORT . "\n";
        file_put_contents($optionFile, $settings);
        unset($settings);
        touch($backup);
        chmod($backup, 0600);
        $before = localRowSnapshot($pdo);
        $process = proc_open([$dumpTool, '--defaults-extra-file=' . $optionFile, '--single-transaction', '--skip-lock-tables',
            '--no-tablespaces', '--hex-blob', '--default-character-set=utf8mb4', DB_NAME],
            [0 => ['pipe', 'r'], 1 => ['file', $backup, 'w'], 2 => ['file', $errorFile, 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start the local backup.');
        fclose($pipes[0]);
        if (proc_close($process) !== 0 || filesize($backup) === 0) throw new RuntimeException('Local backup failed. No migration applied.');
        if (localRowSnapshot($pdo) !== $before) throw new RuntimeException('Data changed during backup. Stop local activity and retry.');
        file_put_contents($backup . '.json', json_encode($before, JSON_PRETTY_PRINT));
        chmod($backup . '.json', 0600);
        return $backup;
    } finally {
        unlink($optionFile);
        unlink($errorFile);
    }
}
