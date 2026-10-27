<?php
require_once __DIR__ . '/book-management.php';
date_default_timezone_set('Asia/Manila');
$pdo->exec("SET time_zone = '+08:00'");

function attendanceAccess(PDO $pdo, string $method): void
{
    requireBookAccess($pdo, true);
    if ($_SERVER['REQUEST_METHOD'] !== $method) bookError(405, 'method_not_allowed');
    if ($method === 'POST') requireBookCsrf();
}

function attendanceText($value, int $max, bool $required = true): string
{
    if (!is_string($value)) throw new DomainException('Enter valid text fields.', 422);
    $value = trim($value);
    if (($required && $value === '') || mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
        throw new DomainException('Check the required fields and their maximum lengths.', 422);
    }
    return $value;
}

function attendanceId($value): int
{
    if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,9}$/', (string)$value)
        || (float)$value > 4294967295) throw new DomainException('Choose a valid record.', 422);
    return (int)$value;
}

function attendanceSettings(PDO $pdo, bool $lock = false): array
{
    $row = $pdo->query('SELECT * FROM attendance_settings WHERE id = 1' . ($lock ? ' FOR UPDATE' : ''))->fetch();
    if (!$row) throw new RuntimeException('Attendance settings missing.');
    return $row;
}

function attendanceCounts(PDO $pdo, string $date): array
{
    $stmt = $pdo->prepare('SELECT COUNT(*) AS visits, COUNT(DISTINCT user_id) AS registered_students,
        COALESCE(SUM(user_id IS NULL),0) AS walk_in_visits FROM attendance WHERE time_in >= :start AND time_in < :end');
    $stmt->execute(['start'=>$date . ' 00:00:00', 'end'=>(new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d') . ' 00:00:00']);
    return array_map('intval', $stmt->fetch());
}

function attendanceOccupancy(PDO $pdo): int
{
    return (int)$pdo->query('SELECT COUNT(*) FROM attendance WHERE tracks_departure = 1 AND time_out IS NULL')->fetchColumn();
}

function attendanceFormToken(): string
{
    if (empty($_SESSION['attendance_form_token'])) {
        $_SESSION['attendance_form_token'] = bin2hex(random_bytes(16));
        $_SESSION['attendance_keys'][] = $_SESSION['attendance_form_token'];
        $_SESSION['attendance_keys'] = array_slice($_SESSION['attendance_keys'], -20);
    }
    return $_SESSION['attendance_form_token'];
}

function attendanceFailure(PDO $pdo, Throwable $exception): void
{
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($exception instanceof DomainException) bookError($exception->getCode() ?: 422, $exception->getMessage());
    error_log('Attendance operation failed.');
    bookError(500, 'Could not complete the attendance operation. Reload and try again.');
}
