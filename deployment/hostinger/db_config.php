<?php
require_once __DIR__ . '/hosting-config.php';
try {
    $hosted = hostingConfiguration();
    hostingRequireRotation();
    $db = $hosted['database'];
    foreach (['host','name'] as $field) {
        if (strpbrk($db[$field], ";\r\n\0") !== false) throw new InvalidArgumentException('Invalid database connection setting.');
    }
    $port = (string)($db['port'] ?? '3306');
    if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) throw new InvalidArgumentException('Invalid database port.');
    define('DB_HOST',$db['host']); define('DB_PORT',$port); define('DB_NAME',$db['name']);
    define('DB_USER',$db['user']); define('DB_PASS',$db['password']); define('DB_CHARSET','utf8mb4');
} catch (Throwable $error) {
    if (PHP_SAPI !== 'cli') http_response_code(503);
    exit('Hosting configuration is unavailable. Contact the deployment administrator.');
}
