<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/approved-user-session.php';
header('Content-Type: application/json');

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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'method_not_allowed');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    fail(400, 'Invalid request body.');
}
$studentName = trim((string) ($input['student_name'] ?? ''));
if ($studentName === '' || mb_strlen($studentName) > 150) {
    fail(422, 'Enter a name up to 150 characters.');
}
$yearLevel = trim((string) ($input['year_level'] ?? ''));
if ($yearLevel === '' || mb_strlen($yearLevel) > 20) {
    fail(422, 'Enter a year level up to 20 characters.');
}
$section = trim((string) ($input['section'] ?? ''));
if ($section === '' || mb_strlen($section) > 25) {
    fail(422, 'Enter a section up to 25 characters.');
}

try {
    $insert = $pdo->prepare(
        'INSERT INTO attendance (student_name, year_level, section)
         VALUES (:student_name, :year_level, :section)'
    );
    $insert->execute([
        'student_name' => $studentName,
        'year_level' => $yearLevel,
        'section' => $section,
    ]);
    echo json_encode([
        'success' => true,
        'student_name' => $studentName,
        'year_level' => $yearLevel,
        'section' => $section,
    ]);
} catch (PDOException $exception) {
    error_log('Could not record student time-in: ' . $exception->getMessage());
    fail(500, 'Could not record time-in. Please try again.');
}
