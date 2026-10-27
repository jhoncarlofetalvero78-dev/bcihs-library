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

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'not_logged_in']);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT r.id, r.book_id, r.status, r.reserved_at, r.pickup_at, b.title AS book_title
     FROM reservations r
     JOIN books b ON b.id = r.book_id
     WHERE r.user_id = :user_id
     ORDER BY r.reserved_at DESC'
);
$stmt->execute(['user_id' => $_SESSION['user_id']]);
$reservations = $stmt->fetchAll();

echo json_encode(['reservations' => $reservations]);
