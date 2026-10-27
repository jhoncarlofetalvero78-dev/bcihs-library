<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/approved-user-session.php';
header('Content-Type: application/json');

if (!requireApprovedUserSession($pdo)) {
    http_response_code(401);
    echo json_encode(['loggedIn' => false]);
    exit;
}

$accountId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
if (!$accountId || !is_numeric($accountId)) {
    http_response_code(401);
    echo json_encode(['loggedIn' => false]);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT full_name, email, student_id, year_section, id_number, department, phone, role, created_at
     FROM users WHERE id = :id LIMIT 1'
);
$stmt->execute(['id' => (int) $accountId]);
$user = $stmt->fetch();

if (!$user) {
    // The session points at an account that no longer exists — treat as logged out.
    http_response_code(401);
    echo json_encode(['loggedIn' => false]);
    exit;
}

echo json_encode(array_merge(['loggedIn' => true], $user));
