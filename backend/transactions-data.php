<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/borrowing.php';

try {
    requireBookAccess($pdo, true);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') bookError(405, 'method_not_allowed');
    $stmt = $pdo->query(
        'SELECT br.id, "borrow" AS source, br.status, br.requested_at, br.approved_at, br.checked_out_at,
                br.due_date, br.returned_at,
                COALESCE(br.checked_out_at, CASE WHEN br.status IN ("approved","returned") THEN br.approved_at END) AS borrowed_at,
                COALESCE(br.borrower_name, u.full_name) AS student_name, b.title AS book_title
         FROM borrow_requests br LEFT JOIN users u ON u.id = br.user_id JOIN books b ON b.id = br.book_id
         UNION ALL
         SELECT r.id, "reservation", r.status, r.reserved_at, r.ready_at, r.fulfilled_at,
                r.due_date, r.returned_at, r.fulfilled_at, u.full_name, b.title
         FROM reservations r JOIN users u ON u.id = r.user_id JOIN books b ON b.id = r.book_id
         ORDER BY requested_at DESC, id DESC'
    );
    echo json_encode(['requests' => $stmt->fetchAll()]);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
