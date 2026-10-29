<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/account-management.php';
try {
    $input=borrowingPost($pdo,true);$id=borrowingId($input['id']??null);
    if (!isset($_SESSION['admin_id']) && (int)($_SESSION['user_id']??0)===$id) throw new DomainException('You cannot delete your own signed-in account.',409);
    $pdo->beginTransaction();
    $stmt=$pdo->prepare('SELECT id FROM users WHERE id=:id FOR UPDATE');$stmt->execute(['id'=>$id]);
    if (!$stmt->fetch()) throw new DomainException('User not found.',404);
    $stmt=$pdo->prepare('SELECT ('.accountHistorySql().') FROM users u WHERE u.id=:id');$stmt->execute(['id'=>$id]);
    if ($stmt->fetchColumn()) throw new DomainException('Preserve this account: it has borrowing, reservation, attendance, SMS or announcement history. Deletion would damage library records.',409);
    $pdo->prepare('DELETE FROM users WHERE id=:id')->execute(['id'=>$id]);$pdo->commit();
    echo json_encode(['success'=>true]);
} catch (Throwable $exception) { borrowingFailure($pdo,$exception); }
