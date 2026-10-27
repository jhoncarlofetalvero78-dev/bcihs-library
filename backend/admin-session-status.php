<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/approved-user-session.php';

$adminId = $_SESSION['admin_id'] ?? null;
if ($adminId && is_numeric($adminId)) {
    $stmt = $pdo->prepare('SELECT username FROM admins WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $adminId]);
    $admin = $stmt->fetch();

    if ($admin) {
        echo json_encode([
            'loggedIn' => true,
            'username' => $admin['username'],
            'role' => 'admin',
        ]);
        exit;
    }

    unset($_SESSION['admin_id'], $_SESSION['admin_username']);
}

$userId = $_SESSION['user_id'] ?? null;
if ($userId && is_numeric($userId) && ($_SESSION['role'] ?? '') === 'librarian'
    && requireApprovedUserSession($pdo)) {
    $stmt = $pdo->prepare(
        'SELECT full_name FROM users WHERE id = :id AND role = "librarian" LIMIT 1'
    );
    $stmt->execute(['id' => (int) $userId]);
    $librarian = $stmt->fetch();

    if ($librarian) {
        echo json_encode([
            'loggedIn' => true,
            'username' => $librarian['full_name'],
            'role' => 'librarian',
        ]);
        exit;
    }
}

echo json_encode(['loggedIn' => false]);
