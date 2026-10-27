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
        'SELECT id, full_name, email, student_id, year_section, id_number, department, phone, role, account_status, last_login, created_at
         FROM users ORDER BY created_at DESC'
    );
    $users = $stmt->fetchAll();

    echo json_encode(['users' => $users, 'csrfToken' => bookCsrfToken()]);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
