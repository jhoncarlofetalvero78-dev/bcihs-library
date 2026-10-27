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
        br.id, br.status, br.requested_at, br.approved_at, br.due_date, br.rejected_at, br.returned_at,
        br.user_id, COALESCE(br.borrower_name, u.full_name) AS student_name,
        b.id AS book_id, b.title AS book_title, b.author, b.accession_number
     FROM borrow_requests br
     LEFT JOIN users u ON u.id = br.user_id
     JOIN books b ON b.id = br.book_id
     ORDER BY br.requested_at DESC'
);
$requests = $stmt->fetchAll();

echo json_encode(['requests' => $requests]);
