<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/approved-user-session.php';
header('Content-Type: application/json');

if (!requireApprovedUserSession($pdo)) {
    http_response_code(401);
    echo json_encode(['error' => 'account_not_accepted']);
    exit;
}

$isLibrarian = isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'librarian';
$isAdmin = isset($_SESSION['admin_id']);
if (!$isLibrarian && !$isAdmin) {
    http_response_code(isset($_SESSION['user_id']) || isset($_SESSION['admin_id']) ? 403 : 401);
    echo json_encode(['error' => isset($_SESSION['user_id']) || isset($_SESSION['admin_id']) ? 'forbidden' : 'not_logged_in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid account action.']);
    exit;
}
$userId = isset($input['id']) ? (int) $input['id'] : 0;
$status = $input['status'] ?? '';

if ($userId <= 0 || !in_array($status, ['accepted', 'rejected'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid account action.']);
    exit;
}

$stmt = $pdo->prepare('UPDATE users SET account_status = :status WHERE id = :id');
$stmt->execute(['status' => $status, 'id' => $userId]);

$check = $pdo->prepare('SELECT account_status FROM users WHERE id = :id LIMIT 1');
$check->execute(['id' => $userId]);
$account = $check->fetch();

if (!$account) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found.']);
    exit;
}

if ($account['account_status'] !== $status) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Account status was not updated.',
        'status' => $account['account_status'],
    ]);
    exit;
}

echo json_encode(['success' => true, 'status' => $account['account_status']]);
