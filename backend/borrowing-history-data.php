<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/borrowing.php';

try {
    requireBookAccess($pdo);
    if (!isset($_SESSION['user_id'])) bookError(401, 'not_logged_in');
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') bookError(405, 'method_not_allowed');
    $stmt = $pdo->prepare(
        'SELECT br.id, br.status, br.requested_at, br.approved_at, br.checked_out_at, br.due_date, br.returned_at,
                COALESCE(br.checked_out_at, CASE WHEN br.status IN ("approved","returned") THEN br.approved_at END) AS borrowed_at,
                b.title AS book_title, b.author
         FROM borrow_requests br JOIN books b ON b.id = br.book_id WHERE br.user_id = :borrow_user
         UNION ALL
         SELECT r.id, CASE WHEN r.status = "returned" THEN "returned" ELSE "checked_out" END,
                r.reserved_at, r.ready_at, r.fulfilled_at, r.due_date, r.returned_at, r.fulfilled_at, b.title, b.author
         FROM reservations r JOIN books b ON b.id = r.book_id
         WHERE r.user_id = :reserved_user AND r.status IN ("fulfilled","returned")
         ORDER BY requested_at DESC'
    );
    $stmt->execute(['borrow_user' => (int) $_SESSION['user_id'], 'reserved_user' => (int) $_SESSION['user_id']]);
    echo json_encode(['requests' => $stmt->fetchAll()]);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
