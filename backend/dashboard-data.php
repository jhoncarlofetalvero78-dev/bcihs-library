<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/reporting.php';
try {
    requireBookAccess($pdo,true);
    if ($_SERVER['REQUEST_METHOD']!=='GET') bookError(405,'method_not_allowed');
    $pdo->beginTransaction();
    $books=reportInventory($pdo);$transactions=reportTransactions($pdo);
    $current=reportCurrentSummary($pdo,$books,$transactions);
    $pdo->commit();
    echo json_encode(['current'=>$current,'generatedAt'=>date('Y-m-d H:i:s'),'timezone'=>'Asia/Manila']);
} catch (Throwable $exception) { borrowingFailure($pdo,$exception); }
