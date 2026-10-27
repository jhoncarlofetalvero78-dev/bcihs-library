<?php
// Copy to db-local.php, then enter your own settings privately.
if (PHP_SAPI !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

return [
    'BCIHS_DB_HOST' => '127.0.0.1',
    'BCIHS_DB_PORT' => '8889',
    'BCIHS_DB_NAME' => 'bcihs_library',
    'BCIHS_DB_USER' => 'root',
    'BCIHS_DB_PASS' => '', // Enter the actual local password; do not guess it.
];
