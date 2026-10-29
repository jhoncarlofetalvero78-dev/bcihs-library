<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/announcements.php';
try {
    requireBookAccess($pdo,true);
    if ($_SERVER['REQUEST_METHOD']!=='GET') bookError(405,'method_not_allowed');
    $rows=$pdo->query('SELECT id,title,body,category,image_path,created_at FROM announcements ORDER BY created_at DESC,id DESC')->fetchAll();
    echo json_encode(['announcements'=>$rows,'csrfToken'=>bookCsrfToken(),'submissionToken'=>announcementToken()]);
} catch(Throwable $exception){borrowingFailure($pdo,$exception);}
