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
    if (array_key_exists('track_departures', $input) || array_key_exists('occupancy_limit', $input)) {
        throw new DomainException('Departure settings are no longer supported. Reload Attendance to update the daily visit limit.', 422);
    }
    foreach (['daily_visit_limit'] as $field) {
        $value = $input[$field] ?? null;
        if ($value === null || $value === '') $limits[$field] = null;
        elseif ((is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]{0,4}$/', (string)$value) && (int)$value <= 65535) $limits[$field] = (int)$value;
        else throw new DomainException('Limits must be blank for unlimited, or whole numbers from 1 to 65535.', 422);
    }
    $pdo->beginTransaction();
    attendanceSettings($pdo, true);
    $today = $pdo->query('SELECT CURDATE()')->fetchColumn();
    if ($limits['daily_visit_limit'] !== null && $limits['daily_visit_limit'] < attendanceCounts($pdo,$today)['visits']) {
        throw new DomainException('A limit cannot be below the current count.', 409);
    }
    $pdo->prepare('UPDATE attendance_settings SET daily_visit_limit=:daily WHERE id=1')
        ->execute(['daily'=>$limits['daily_visit_limit']]);
    $pdo->commit();
    echo json_encode(['success'=>true]);
} catch (Throwable $exception) { attendanceFailure($pdo, $exception); }
