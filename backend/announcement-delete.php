<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/announcements.php';
try {
    $input=borrowingPost($pdo,true);$id=borrowingId($input['id']??null);
    $pdo->beginTransaction();
    $q=$pdo->prepare('SELECT image_path FROM announcements WHERE id=:id FOR UPDATE');$q->execute(['id'=>$id]);$row=$q->fetch();
    if (!$row) throw new DomainException('Announcement not found.',404);
    $pdo->prepare('DELETE FROM announcements WHERE id=:id')->execute(['id'=>$id]);$pdo->commit();
    removeAnnouncementPhoto($row['image_path']);echo json_encode(['success'=>true]);
} catch(Throwable $exception){borrowingFailure($pdo,$exception);}
