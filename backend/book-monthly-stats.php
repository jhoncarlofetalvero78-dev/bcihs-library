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

$now = new DateTime('first day of this month');
$months = [];
$totalArr = [];
$borrowedArr = [];

for ($i = 5; $i >= 0; $i--) {
    $d = (clone $now)->modify("-{$i} months");
    $monthKey = $d->format('Y-m');
    $monthLabel = $d->format('M');

    $totalStmt = $pdo->query("
        SELECT COALESCE(SUM(total_copies), 0) as total
        FROM books
        WHERE DATE_FORMAT(created_at, '%Y-%m') <= '$monthKey'
    ");
    $total = (int)$totalStmt->fetch()['total'];

    $borrowedStmt = $pdo->query("
        SELECT COUNT(*) as borrowed
        FROM borrow_requests
        WHERE status IN ('approved', 'returned')
        AND approved_at IS NOT NULL
        AND DATE_FORMAT(approved_at, '%Y-%m') <= '$monthKey'
    ");
    $borrowed = (int)$borrowedStmt->fetch()['borrowed'];

    $months[] = $monthLabel;
    $totalArr[] = $total;
    $borrowedArr[] = $borrowed;
}

$availableArr = array_map(fn($t, $b) => max(0, $t - $b), $totalArr, $borrowedArr);

echo json_encode([
    'months' => $months,
    'total' => $totalArr,
    'borrowed' => $borrowedArr,
    'available' => $availableArr,
]);
