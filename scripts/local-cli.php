<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    require_once __DIR__ . '/../includes/db_config.php';
} catch (Throwable $exception) {
    fwrite(STDERR, "Check the format of includes/db-local.php and your database environment variables.\n");
    exit(1);
}

// Refuse remote hosts before opening a connection or making any changes.
if (!in_array(DB_HOST, ['localhost', '127.0.0.1', '::1'], true) || DB_NAME !== 'bcihs_library') {
    fwrite(STDERR, "This command only supports a local bcihs_library database. No connection was opened.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/db_connect.php';
