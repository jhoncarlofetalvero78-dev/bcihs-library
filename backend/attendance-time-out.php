<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/attendance.php';
try {
    attendanceAccess($pdo, 'POST');
    $input = bookRequestInput();
    $id = attendanceId($input['id'] ?? null);
    if (($input['confirm_departure'] ?? false) !== true) throw new DomainException('Confirm the visitor has departed.', 422);
    $pdo->beginTransaction();
    attendanceSettings($pdo, true);
    $stmt = $pdo->prepare('SELECT tracks_departure, time_out FROM attendance WHERE id = :id FOR UPDATE');
    $stmt->execute(['id'=>$id]); $row = $stmt->fetch();
    if (!$row) throw new DomainException('Visit not found.', 404);
    if (!(int)$row['tracks_departure']) throw new DomainException('Departure was not tracked for this visit.', 409);
    if ($row['time_out'] !== null) throw new DomainException('Time Out is already recorded.', 409);
    $pdo->prepare('UPDATE attendance SET time_out = NOW() WHERE id = :id')->execute(['id'=>$id]);
    $pdo->commit();
    echo json_encode(['success'=>true]);
} catch (Throwable $exception) { attendanceFailure($pdo, $exception); }
