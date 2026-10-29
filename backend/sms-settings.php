<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/borrowing.php';
try {
    $role = requireBookAccess($pdo, true);
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, ['GET','POST'], true)) bookError(405, 'method_not_allowed');
    if ($method === 'POST') {
        if ($role !== 'admin') bookError(403, 'Only administrators may change SMS notification permission.');
        requireBookCsrf();
        smsPermissionChange($pdo, (int)$_SESSION['admin_id'], bookRequestInput());
    }
    $permission = smsPermissionState($pdo);
    $config = smsConfig();
    $audit = [];
    if ($role === 'admin' && $permission['available']) {
        $audit = $pdo->query('SELECT admin_id,previous_allowed,new_allowed,setting_version,
            DATE_FORMAT(CONVERT_TZ(changed_at,"+00:00","+08:00"),"%Y-%m-%d %H:%i:%s") AS changed_at
            FROM sms_notification_audit ORDER BY id DESC LIMIT 20')->fetchAll();
    }
    echo json_encode(['permission' => $permission, 'can_manage' => $role === 'admin',
        'csrfToken' => bookCsrfToken(), 'audit' => $audit] + smsDeliveryStatus($permission, $config), JSON_THROW_ON_ERROR);
} catch (Throwable $exception) { borrowingFailure($pdo, $exception); }
