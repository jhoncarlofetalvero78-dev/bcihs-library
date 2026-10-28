<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/book-management.php';
if ($_SERVER['REQUEST_METHOD']!=='GET') bookError(405,'method_not_allowed');
echo json_encode(['csrfToken'=>bookCsrfToken()]);
