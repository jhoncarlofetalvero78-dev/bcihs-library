<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/borrowing.php';

try {
    $input = borrowingPost($pdo, true);
    $id = borrowingId($input['id'] ?? null);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $id]);
    if (!$stmt->fetch()) throw new DomainException('User not found.', 404);
    $history = $pdo->prepare('SELECT EXISTS(SELECT 1 FROM borrow_requests WHERE user_id = :borrow_user)
                                 OR EXISTS(SELECT 1 FROM reservations WHERE user_id = :reserved_user)
                                 OR EXISTS(SELECT 1 FROM attendance WHERE user_id = :attendance_user)');
    $history->execute(['borrow_user' => $id, 'reserved_user' => $id, 'attendance_user' => $id]);
    if ($history->fetchColumn()) throw new DomainException('This user has library transaction history and cannot be deleted. Preserve the account and its records.', 409);
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
