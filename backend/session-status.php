<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/approved-user-session.php';

if (!requireApprovedUserSession($pdo)) {
    echo json_encode(['loggedIn' => false]);
    exit;
}

$adminId = $_SESSION['admin_id'] ?? null;
if (is_numeric($adminId)) {
    $stmt = $pdo->prepare('SELECT username FROM admins WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $adminId]);
    $admin = $stmt->fetch();

    if ($admin) {
        echo json_encode([
            'loggedIn' => true,
            'fullName' => $admin['username'],
            'role' => 'admin',
        ]);
        exit;
    }
}

$userId = $_SESSION['user_id'] ?? null;
if (!$userId || !is_numeric($userId)) {
    echo json_encode(['loggedIn' => false]);
    exit;
}

$stmt = $pdo->prepare('SELECT full_name, role, account_status FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => (int) $userId]);
$user = $stmt->fetch();

if (!$user || $user['account_status'] !== 'accepted') {
    echo json_encode(['loggedIn' => false]);
    exit;
}

echo json_encode([
    'loggedIn' => true,
    'fullName' => $user['full_name'],
    'role' => $user['role'],
]);
