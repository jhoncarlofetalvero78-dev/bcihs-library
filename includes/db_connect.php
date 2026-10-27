<?php
/**
 * Database connection for the BCIHS Library Management System.
 * Uses PDO with MySQL. Defaults to the local XAMPP database.
 */

function databaseSetting(string $name, string $fallback): string
{
    $value = getenv($name);
    return $value === false || $value === '' ? $fallback : $value;
}

// Override these with environment variables when using a non-local database.
define('DB_HOST', databaseSetting('BCIHS_DB_HOST', 'localhost'));
define('DB_NAME', databaseSetting('BCIHS_DB_NAME', 'bcihs_library'));
define('DB_USER', databaseSetting('BCIHS_DB_USER', 'root'));
define('DB_PASS', databaseSetting('BCIHS_DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // Never leak DB credentials or raw exception details to the browser.
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('We could not connect to the library system right now. Please try again later.');
}
