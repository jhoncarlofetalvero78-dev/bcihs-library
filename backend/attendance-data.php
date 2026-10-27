<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/approved-user-session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

if (!requireApprovedUserSession($pdo)) {
    fail(401, 'not_logged_in');
}

$isLibraryStaff = isset($_SESSION['admin_id']) || (
    isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'librarian'
);
if (!$isLibraryStaff) {
    fail(403, 'forbidden');
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    fail(405, 'method_not_allowed');
}

$date = trim((string) ($_GET['date'] ?? date('Y-m-d')));
$parsedDate = DateTime::createFromFormat('!Y-m-d', $date);
if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
    fail(422, 'Enter a valid attendance date.');
}

try {
    $recordsQuery = $pdo->prepare(
        'SELECT id, student_name, year_level, section, time_in
         FROM attendance
         WHERE time_in >= :start_time AND time_in < :end_time
         ORDER BY time_in DESC, id DESC'
    );
    $recordsQuery->execute([
        'start_time' => $date . ' 00:00:00',
        'end_time' => (new DateTime($date))->modify('+1 day')->format('Y-m-d') . ' 00:00:00',
    ]);

    echo json_encode(['records' => $recordsQuery->fetchAll()]);
} catch (PDOException $exception) {
    error_log('Could not load attendance records: ' . $exception->getMessage());
    fail(500, 'Could not load attendance records. Please try again.');
}
