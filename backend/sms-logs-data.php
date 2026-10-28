<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/borrowing.php';
try {
    requireBookAccess($pdo, true);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') bookError(405, 'method_not_allowed');
    $rows = $pdo->query('SELECT s.id,s.phone,s.message,s.status,s.response,s.created_at,u.full_name
        FROM sms_logs s LEFT JOIN users u ON u.id=s.user_id ORDER BY s.created_at DESC,s.id DESC LIMIT 300')->fetchAll();
    $logs = [];
    foreach ($rows as $row) $logs[] = smsPublicLog($row);
    $settings = smsSettings();
    echo json_encode(['logs' => $logs, 'provider' => in_array($settings['provider'], ['simulation','semaphore','unisms'], true) ? $settings['provider'] : 'disabled',
        'mode' => $settings['provider'] === 'simulation' || $settings['mode'] === 'simulate' ? 'simulate' : 'live_or_disabled', 'timezone' => 'Asia/Manila']);
} catch (Throwable $exception) {borrowingFailure($pdo, $exception);}
