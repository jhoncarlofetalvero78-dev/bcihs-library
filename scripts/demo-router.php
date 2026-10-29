<?php
// PHP's built-in server ignores .htaccess. This preview is loopback-only.
$root = getenv('BCIHS_DEMO_WEBROOT');
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
// Loopback preview identity only; no credentials, personal records or provider requests.
if ($path === '/__staging-status' && is_string($root) && is_dir($root)) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') throw new RuntimeException();
        require $root . '/includes/db_connect.php';
        require_once $root . '/includes/sms.php';
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $config = smsConfig();
        $permission = smsPermissionState($pdo);
        if ($database !== 'bcihs_demo_20261028_236ee935' || $config['provider'] !== 'simulation'
            || $config['mode'] !== 'simulate' || $config['live_enabled'] || $config['key'] !== ''
            || !$permission['available'] || $permission['allowed']) throw new RuntimeException();
        echo json_encode(['application'=>'bcihs-final-staging','database'=>$database,
            'sms_permission'=>false,'provider'=>'simulation','live_enabled'=>false,'credentials_present'=>false]);
    } catch (Throwable $error) {
        http_response_code(503);
        echo json_encode(['application'=>'bcihs-final-staging','ready'=>false]);
    }
    return;
}
if (!is_string($root) || !is_dir($root) || str_contains($path, "\0")
    || preg_match('#(^|/)(\.|includes|vendor|scripts|tests|sql|tools)|\.(sql|bak|log|json|ini|env)$#i', $path)) {
    http_response_code(403); exit;
}
$file = realpath($root . $path);
if ($file !== false && $file !== realpath($root) && !str_starts_with($file, realpath($root) . '/')) {
    http_response_code(403); exit;
}
return false;
