<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/approved-user-session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$isLibraryStaff = isset($_SESSION['admin_id']) || (
    isset($_SESSION['user_id'])
    && ($_SESSION['role'] ?? '') === 'librarian'
    && requireApprovedUserSession($pdo)
);
if (!$isLibraryStaff) {
    http_response_code(401);
    echo json_encode(['error' => 'not_logged_in']);
    exit;
}

$stmt = $pdo->query(
    'SELECT
        s.id, s.phone, s.message, s.status, s.response, s.created_at,
        u.id AS user_id, u.full_name
     FROM sms_logs s
     LEFT JOIN users u ON u.id = s.user_id
     ORDER BY s.created_at DESC
     LIMIT 300'
);
$logs = $stmt->fetchAll();

echo json_encode(['logs' => $logs]);
