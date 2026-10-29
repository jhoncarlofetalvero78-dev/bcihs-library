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
        'SELECT br.id AS request_id, br.book_id, br.status, br.requested_at, br.approved_at,
                br.checked_out_at, br.due_date, br.returned_at,
                COALESCE(br.checked_out_at, CASE WHEN br.status IN ("approved","returned") THEN br.approved_at END) AS borrowed_at,
                u.id AS user_id, u.full_name, u.email, u.student_id, u.id_number, u.year_section, u.department, u.phone, u.role
         FROM borrow_requests br JOIN users u ON u.id = br.user_id
         WHERE br.user_id = :user ORDER BY br.requested_at DESC, br.id DESC'
    );
    $stmt->execute(['user' => (int) $_SESSION['user_id']]);
    echo json_encode(['requests' => $stmt->fetchAll()]);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
