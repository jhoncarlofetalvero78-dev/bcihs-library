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

$stmt = $pdo->query(
    'SELECT id, full_name, email, student_id, year_section, id_number, department, phone, role, account_status, last_login, created_at
     FROM users ORDER BY created_at DESC'
);
$users = $stmt->fetchAll();

echo json_encode(['users' => $users]);
