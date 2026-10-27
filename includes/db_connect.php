<?php
/**
 * Database connection for the BCIHS Library Management System.
 * Uses PDO with MySQL. Defaults remain compatible with local XAMPP.
 */

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    require_once __DIR__ . '/db_config.php';

    $dsn = 'mysql:host=' . DB_HOST;
    if (DB_PORT !== '') {
        $dsn .= ';port=' . DB_PORT;
    }
    $dsn .= ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException | InvalidArgumentException $e) {
    // PDO messages can contain connection details, so keep logs generic too.
    error_log('Database connection failed. Check the private configuration and MySQL service.');
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Could not connect to the library database. Check your private configuration and MySQL service.\n");
        exit(1);
    }
    http_response_code(500);
    die('We could not connect to the library system right now. Please try again later.');
}
