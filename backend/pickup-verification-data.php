<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/pickup-qr.php';

try {
    requireBookAccess($pdo, true);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') bookError(405, 'method_not_allowed');
    $token = pickupToken($_GET['token'] ?? null);
    $stmt = $pdo->prepare('SELECT br.id,br.status,br.requested_at,br.approved_at,br.checked_out_at,br.due_date,br.returned_at,
        u.full_name,u.student_id,u.id_number,u.email,u.year_section,u.account_status,
        b.id AS book_id,b.title,b.author,b.call_number
        FROM borrow_requests br JOIN users u ON u.id=br.user_id JOIN books b ON b.id=br.book_id
        WHERE br.pickup_token=:token');
    $stmt->execute(['token' => $token]);
    $request = $stmt->fetch();
    if (!$request) throw new DomainException('Pickup QR was not found. Ask the student to reopen their approved request.', 404);
    $eligible = $request['status'] === 'awaiting_pickup' && $request['account_status'] === 'accepted';
    echo json_encode(['request' => $request, 'eligible' => $eligible, 'csrfToken' => bookCsrfToken()]);
} catch (Throwable $exception) { borrowingFailure($pdo, $exception); }
