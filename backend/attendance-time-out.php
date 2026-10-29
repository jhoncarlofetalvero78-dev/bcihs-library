<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/attendance.php';
try {
    attendanceAccess($pdo, 'POST');
    // Retire stale clients without touching historical departure timestamps.
    bookError(410, 'Attendance is Time In only. Departure recording is no longer available.');
} catch (Throwable $exception) { attendanceFailure($pdo, $exception); }
