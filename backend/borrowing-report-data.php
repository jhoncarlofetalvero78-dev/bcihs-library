<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/reporting.php';

try {
    requireBookAccess($pdo, true);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') bookError(405, 'method_not_allowed');
    $rows=array_values(array_filter(reportTransactions($pdo),static fn($row)=>$row['is_loan']));
    foreach ($rows as &$row) $row['status']=$row['status']==='returned'?'returned':'borrowed';
    unset($row);
    usort($rows,static fn($a,$b)=>strcmp($b['borrowed_at'],$a['borrowed_at']) ?: strcmp($b['record_key'],$a['record_key']));
    echo json_encode(['borrowings'=>$rows]);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
