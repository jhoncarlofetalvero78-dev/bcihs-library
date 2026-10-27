<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/borrowing.php';

try {
    requireBookAccess($pdo, true);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') bookError(405, 'method_not_allowed');
    $months = $totals = $borrowed = [];
    $now = new DateTimeImmutable('first day of this month');
    $totalQuery = $pdo->prepare('SELECT COALESCE(SUM(total_copies), 0) FROM books WHERE created_at < :end');
    $loanQuery = $pdo->prepare(
        'SELECT (SELECT COUNT(*) FROM borrow_requests WHERE status IN ("approved","checked_out","returned")
                  AND COALESCE(checked_out_at, approved_at) < :borrow_end
                  AND (returned_at IS NULL OR returned_at >= :borrow_return))
              + (SELECT COUNT(*) FROM reservations WHERE status IN ("fulfilled","returned")
                  AND fulfilled_at < :reserved_end AND (returned_at IS NULL OR returned_at >= :reserved_return))'
    );
    for ($i = 5; $i >= 0; $i--) {
        $month = $now->modify('-' . $i . ' months');
        $end = $month->modify('+1 month')->format('Y-m-01 00:00:00');
        $totalQuery->execute(['end' => $end]);
        $loanQuery->execute(['borrow_end' => $end, 'borrow_return' => $end, 'reserved_end' => $end, 'reserved_return' => $end]);
        $months[] = $month->format('M');
        $totals[] = (int) $totalQuery->fetchColumn();
        $borrowed[] = (int) $loanQuery->fetchColumn();
    }
    echo json_encode(['months' => $months, 'total' => $totals, 'borrowed' => $borrowed,
        'available' => array_map(static fn(int $total, int $out) => max(0, $total - $out), $totals, $borrowed)]);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
