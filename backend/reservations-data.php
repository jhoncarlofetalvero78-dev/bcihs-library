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
        r.id, r.status, r.reserved_at, r.pickup_at, r.ready_at, r.fulfilled_at, r.returned_at,
        u.id AS user_id, u.full_name AS student_name,
        b.id AS book_id, b.title AS book_title, b.total_copies, b.borrowed_copies
     FROM reservations r
     JOIN users u ON u.id = r.user_id
     JOIN books b ON b.id = r.book_id
     ORDER BY r.book_id, r.reserved_at ASC'
);
$reservations = $stmt->fetchAll();

// Compute each 'waiting' reservation's place in line, per book, without
// storing it — position 1 is the next one eligible to be marked ready.
$positionByBook = [];
foreach ($reservations as &$r) {
    if ($r['status'] === 'waiting') {
        $bookId = $r['book_id'];
        $positionByBook[$bookId] = ($positionByBook[$bookId] ?? 0) + 1;
        $r['queue_position'] = $positionByBook[$bookId];
    } else {
        $r['queue_position'] = null;
    }
}
unset($r);

// Most-recent-first is friendlier for admin browsing than grouped-by-book.
usort($reservations, fn($a, $b) => strtotime($b['reserved_at']) <=> strtotime($a['reserved_at']));

echo json_encode(['reservations' => $reservations]);
