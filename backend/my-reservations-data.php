<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/borrowing.php';

try {
    requireBookAccess($pdo);
    if (!isset($_SESSION['user_id'])) bookError(401, 'not_logged_in');
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') bookError(405, 'method_not_allowed');
    $stmt = $pdo->prepare(
        'SELECT r.id, r.book_id, r.status, r.reserved_at, r.pickup_at, r.fulfilled_at, r.due_date, r.returned_at, b.title AS book_title
         FROM reservations r
         JOIN books b ON b.id = r.book_id
         WHERE r.user_id = :user_id
         ORDER BY r.reserved_at DESC'
    );
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $reservations = $stmt->fetchAll();

    echo json_encode(['reservations' => $reservations]);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
