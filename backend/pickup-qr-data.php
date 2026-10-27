<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/pickup-qr.php';

try {
    $input = borrowingPost($pdo);
    if (isset($_SESSION['admin_id']) || !isset($_SESSION['user_id'])) bookError(403, 'Open the request from its owner account.');
    $id = borrowingId($input['id'] ?? null);
    $base = pickupBaseUrl();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT status,pickup_token FROM borrow_requests WHERE id=:id AND user_id=:user FOR UPDATE');
    $stmt->execute(['id' => $id, 'user' => $_SESSION['user_id']]);
    $request = $stmt->fetch();
    if (!$request) throw new DomainException('Your borrowing request was not found.', 404);
    if ($request['status'] !== 'awaiting_pickup') throw new DomainException('Pickup QR is available only while this request is awaiting pickup.', 409);
    // Also supports requests approved before this feature was installed.
    $token = $request['pickup_token'] ?: bin2hex(random_bytes(32));
    if (!$request['pickup_token']) {
        $pdo->prepare('UPDATE borrow_requests SET pickup_token=:token WHERE id=:id')->execute(['token' => $token, 'id' => $id]);
    }
    $pdo->commit();
    echo json_encode(['url' => $base . '/pickup-verification.html?token=' . pickupToken($token)]);
} catch (Throwable $exception) { borrowingFailure($pdo, $exception); }
