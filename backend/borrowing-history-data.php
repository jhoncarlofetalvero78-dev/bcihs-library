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
    'SELECT
        br.id,
        br.status,
        br.requested_at,
        br.approved_at,
        br.due_date,
        br.returned_at,
        b.title AS book_title,
        b.author
     FROM borrow_requests br
     JOIN books b ON b.id = br.book_id
     WHERE br.user_id = :user_id
     UNION ALL
     SELECT
        r.id,
        CASE WHEN r.status = "returned" THEN "returned" ELSE "borrowed" END AS status,
        r.fulfilled_at AS requested_at,
        r.fulfilled_at AS approved_at,
        NULL AS due_date,
        r.returned_at,
        b.title AS book_title,
        b.author
     FROM reservations r
     JOIN books b ON b.id = r.book_id
     WHERE r.user_id = :reservation_user_id
       AND r.status IN ("fulfilled", "returned")
     ORDER BY requested_at DESC'
);
$stmt->execute([
    'user_id' => $_SESSION['user_id'],
    'reservation_user_id' => $_SESSION['user_id'],
]);
$requests = $stmt->fetchAll();

echo json_encode(['requests' => $requests]);
