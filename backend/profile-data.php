<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/account-management.php';
try {
    $role=requireBookAccess($pdo);
    if ($_SERVER['REQUEST_METHOD']!=='GET') bookError(405,'method_not_allowed');
    if ($role==='admin') {
        $stmt=$pdo->prepare('SELECT username AS full_name,created_at FROM admins WHERE id=:id');$stmt->execute(['id'=>$_SESSION['admin_id']]);
        echo json_encode(array_merge($stmt->fetch(),['loggedIn'=>true,'role'=>'admin','readOnly'=>true,'csrfToken'=>bookCsrfToken()]));exit;
    }
    $stmt=$pdo->prepare('SELECT full_name,email,student_id,year_section,id_number,department,phone,role,account_status,created_at FROM users WHERE id=:id');
    $stmt->execute(['id'=>$_SESSION['user_id']]);
    echo json_encode(array_merge($stmt->fetch(),['loggedIn'=>true,'csrfToken'=>bookCsrfToken()]));
} catch(Throwable $exception){borrowingFailure($pdo,$exception);}
