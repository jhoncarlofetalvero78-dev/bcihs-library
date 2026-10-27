<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/account-management.php';
try {
    $input=borrowingPost($pdo,true);
    $id=borrowingId($input['id']??null);$status=$input['status']??null;
    if (!in_array($status,['accepted','rejected'],true)) throw new DomainException('Choose Approve or Reject.',422);
    $pdo->beginTransaction();
    $stmt=$pdo->prepare('SELECT account_status FROM users WHERE id=:id FOR UPDATE');$stmt->execute(['id'=>$id]);
    $current=$stmt->fetchColumn();
    if ($current===false) throw new DomainException('User not found.',404);
    if ($current===$status) throw new DomainException('This account is already '.$status.'. Reload the list.',409);
    if ($current==='accepted') throw new DomainException('Approved accounts cannot be rejected through the pending-account review workflow.',409);
    $pdo->prepare('UPDATE users SET account_status=:status WHERE id=:id')->execute(['status'=>$status,'id'=>$id]);
    $pdo->commit();
    if ($status === 'accepted') notifyLibraryEvent($pdo, $id, 'account_approved', $id, [], 'account');
    echo json_encode(['success'=>true,'status'=>$status]);
} catch (Throwable $exception) { borrowingFailure($pdo,$exception); }
