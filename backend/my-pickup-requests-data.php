<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/pickup-qr.php';
try {
    requireBookAccess($pdo);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') bookError(405, 'method_not_allowed');
    if (isset($_SESSION['admin_id']) || !isset($_SESSION['user_id'])) bookError(403, 'Open pickup codes from a borrower account.');
    $stmt = $pdo->prepare('SELECT br.id,b.title,br.approved_at FROM borrow_requests br JOIN books b ON b.id=br.book_id
        WHERE br.user_id=:user AND br.status="awaiting_pickup" ORDER BY br.approved_at DESC,br.id DESC');
    $stmt->execute(['user' => $_SESSION['user_id']]);
    echo json_encode(['requests' => $stmt->fetchAll(), 'csrfToken' => bookCsrfToken()]);
} catch (Throwable $exception) { borrowingFailure($pdo, $exception); }
