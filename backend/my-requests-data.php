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
        br.id AS request_id,
        br.book_id,
        br.status,
        br.requested_at,
        br.approved_at,
        br.due_date,
        br.returned_at,
        u.id AS user_id,
        u.full_name,
        u.email,
        u.student_id,
        u.id_number,
        u.year_section,
        u.department,
        u.phone,
        u.role
     FROM borrow_requests br
     JOIN users u ON u.id = br.user_id
     WHERE br.user_id = :user_id
     ORDER BY br.requested_at DESC'
);
$stmt->execute(['user_id' => $_SESSION['user_id']]);
$requests = $stmt->fetchAll();

echo json_encode(['requests' => $requests]);
