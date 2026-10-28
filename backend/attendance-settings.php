<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/attendance.php';
try {
    attendanceAccess($pdo, 'POST');
    $input = bookRequestInput();
    $limits = [];
    foreach (['daily_visit_limit','occupancy_limit'] as $field) {
        $value = $input[$field] ?? null;
        if ($value === null || $value === '') $limits[$field] = null;
        elseif ((is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]{0,4}$/', (string)$value) && (int)$value <= 65535) $limits[$field] = (int)$value;
        else throw new DomainException('Limits must be blank for unlimited, or whole numbers from 1 to 65535.', 422);
    }
    $tracking = $input['track_departures'] ?? null;
    if (!is_bool($tracking)) throw new DomainException('Choose whether departures are tracked.', 422);
    if (!$tracking && $limits['occupancy_limit'] !== null) throw new DomainException('Enable departure tracking before setting occupancy capacity.', 422);
    $pdo->beginTransaction();
    attendanceSettings($pdo, true);
    $inside = attendanceOccupancy($pdo);
    $today = $pdo->query('SELECT CURDATE()')->fetchColumn();
    if (!$tracking && $inside > 0) throw new DomainException('Record all open departures before disabling tracking.', 409);
    if (($limits['occupancy_limit'] !== null && $limits['occupancy_limit'] < $inside)
        || ($limits['daily_visit_limit'] !== null && $limits['daily_visit_limit'] < attendanceCounts($pdo,$today)['visits'])) {
        throw new DomainException('A limit cannot be below the current count.', 409);
    }
    $pdo->prepare('UPDATE attendance_settings SET daily_visit_limit=:daily, occupancy_limit=:occupancy, track_departures=:tracking WHERE id=1')
        ->execute(['daily'=>$limits['daily_visit_limit'],'occupancy'=>$limits['occupancy_limit'],'tracking'=>(int)$tracking]);
    $pdo->commit();
    echo json_encode(['success'=>true]);
} catch (Throwable $exception) { attendanceFailure($pdo, $exception); }
