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
        'SELECT br.id, br.book_id, b.title AS book_title,
                COALESCE(br.borrower_name, u.full_name, "Unknown borrower") AS borrower_name,
                COALESCE(br.checked_out_at, br.approved_at) AS borrowed_at, br.due_date, br.returned_at,
                CASE WHEN br.status = "returned" THEN "returned" ELSE "borrowed" END AS status
         FROM borrow_requests br LEFT JOIN users u ON u.id = br.user_id JOIN books b ON b.id = br.book_id
         WHERE br.status IN ("approved","checked_out","returned")
         UNION ALL
         SELECT r.id, r.book_id, b.title, u.full_name, r.fulfilled_at, r.due_date, r.returned_at,
                CASE WHEN r.status = "returned" THEN "returned" ELSE "borrowed" END
         FROM reservations r JOIN users u ON u.id = r.user_id JOIN books b ON b.id = r.book_id
         WHERE r.status IN ("fulfilled","returned") AND r.fulfilled_at IS NOT NULL
         ORDER BY borrowed_at DESC'
    );
    echo json_encode(['borrowings' => $stmt->fetchAll()]);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
