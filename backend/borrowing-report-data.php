<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';
header('Content-Type: application/json');

$isLibraryStaff = isset($_SESSION['admin_id']) || (
    isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'librarian'
);
if (!$isLibraryStaff) {
    http_response_code(401);
    echo json_encode(['error' => 'not_logged_in']);
    exit;
}

$stmt = $pdo->query(
    'SELECT
        br.id, br.book_id, b.title AS book_title,
        COALESCE(br.borrower_name, u.full_name, "Unknown borrower") AS borrower_name,
        COALESCE(br.approved_at, br.requested_at) AS borrowed_at, br.due_date, br.returned_at,
        CASE WHEN br.status = "returned" THEN "returned" ELSE "borrowed" END AS status
     FROM borrow_requests br
     LEFT JOIN users u ON u.id = br.user_id
     JOIN books b ON b.id = br.book_id
     WHERE br.status IN ("approved", "returned")

     UNION ALL

     SELECT
        r.id, r.book_id, b.title AS book_title, u.full_name AS borrower_name,
        r.fulfilled_at AS borrowed_at, NULL AS due_date, r.returned_at,
        CASE WHEN r.status = "returned" THEN "returned" ELSE "borrowed" END AS status
     FROM reservations r
     JOIN users u ON u.id = r.user_id
     JOIN books b ON b.id = r.book_id
     WHERE r.status IN ("fulfilled", "returned")
       AND r.fulfilled_at IS NOT NULL

     ORDER BY borrowed_at DESC'
);

echo json_encode(['borrowings' => $stmt->fetchAll()]);
