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
        'SELECT br.id, br.status, br.requested_at, br.approved_at, br.checked_out_at, br.due_date, br.rejected_at, br.returned_at,
                COALESCE(br.checked_out_at, CASE WHEN br.status IN ("approved","returned") THEN br.approved_at END) AS borrowed_at,
                br.user_id, COALESCE(br.borrower_name, u.full_name) AS student_name,
                b.id AS book_id, b.title AS book_title, b.author, b.accession_number
         FROM borrow_requests br LEFT JOIN users u ON u.id = br.user_id
         JOIN books b ON b.id = br.book_id ORDER BY br.requested_at DESC, br.id DESC'
    );
    echo json_encode(['requests' => $stmt->fetchAll(), 'csrfToken' => bookCsrfToken(), 'serverDate' => date('Y-m-d'), 'manualCheckoutToken' => manualCheckoutToken()]);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
