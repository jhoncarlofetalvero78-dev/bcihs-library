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
    if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'not_logged_in']);
    } else {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
    }
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$userId = isset($input['id']) ? (int)$input['id'] : 0;

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user ID.']);
    exit;
}

$stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
$deleted = $stmt->execute([$userId]);

if ($deleted && $stmt->rowCount() > 0) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'User not found.']);
}
