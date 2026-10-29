<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/borrowing.php';
try {
    $role = requireBookAccess($pdo, true);
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, ['GET','POST'], true)) bookError(405, 'method_not_allowed');
    if ($method === 'POST') {
        if ($role !== 'admin') bookError(403, 'Only administrators may change the default borrowing period.');
        requireBookCsrf();
        saveLoanPolicy(bookRequestInput(), (int)$_SESSION['admin_id']);
    }
    $policy = loanPolicy();
    echo json_encode(['days'=>$policy['days'], 'version'=>$policy['version'], 'can_manage'=>$role === 'admin',
        'audit'=>$role === 'admin' ? array_slice($policy['audit'], -20) : [], 'csrfToken'=>bookCsrfToken(), 'timezone'=>'Asia/Manila',
        'server_date'=>date('Y-m-d'), 'max_date'=>min('2038-01-18', (new DateTimeImmutable('+365 days'))->format('Y-m-d'))]);
} catch (Throwable $exception) { borrowingFailure($pdo, $exception); }
