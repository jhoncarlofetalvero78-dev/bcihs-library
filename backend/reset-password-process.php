<?php
/**
 * Step 2 of the Forgot Password flow — verifies the 6-digit SMS code and
 * sets a new password.
 */
session_start();
require_once __DIR__ . '/../includes/db_connect.php';
header('Content-Type: application/json');

function fail(int $code, string $message) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'method_not_allowed');
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$role        = isset($input['role']) ? preg_replace('/[^a-z]/', '', strtolower($input['role'])) : '';
$identifier  = trim($input['identifier'] ?? '');
$phone       = trim($input['phone'] ?? '');
$code        = trim($input['code'] ?? '');
$newPassword = $input['password'] ?? '';

$allowedRoles = ['student', 'teacher', 'staff', 'employee', 'visitor'];
if ($role !== '' && !in_array($role, $allowedRoles, true)) {
    fail(422, 'Please choose your account type again from the role screen.');
}
if (($identifier === '' && $phone === '') || $code === '' || $newPassword === '') {
    fail(422, 'Please fill in every field.');
}
if (strlen($newPassword) < 8) {
    fail(422, 'Your new password must be at least 8 characters.');
}

if ($phone !== '') {
    $stmt = $pdo->prepare('SELECT id, reset_code, reset_code_expires FROM users WHERE phone = :v LIMIT 1');
    $stmt->execute(['v' => $phone]);
} elseif ($role === 'student') {
    $stmt = $pdo->prepare('SELECT id, reset_code, reset_code_expires FROM users WHERE student_id = :v AND role = "student" LIMIT 1');
    $stmt->execute(['v' => $identifier]);
} elseif ($role === 'teacher' || $role === 'staff') {
    $stmt = $pdo->prepare('SELECT id, reset_code, reset_code_expires FROM users WHERE id_number = :v AND role = :role LIMIT 1');
    $stmt->execute(['v' => $identifier, 'role' => $role]);
} else {
    $stmt = $pdo->prepare('SELECT id, reset_code, reset_code_expires FROM users WHERE email = :v AND role = :role LIMIT 1');
    $stmt->execute(['v' => $identifier, 'role' => $role]);
}
$user = $stmt->fetch();

if (!$user || $user['reset_code'] === null) {
    fail(422, 'That code is invalid. Please request a new one.');
}
if (strtotime($user['reset_code_expires']) < time()) {
    fail(422, 'That code has expired. Please request a new one.');
}
if (!hash_equals((string) $user['reset_code'], $code)) {
    fail(422, 'That code is incorrect. Please check the SMS and try again.');
}

$hash = password_hash($newPassword, PASSWORD_DEFAULT);
$pdo->prepare('UPDATE users SET password_hash = :hash, reset_code = NULL, reset_code_expires = NULL WHERE id = :id')
    ->execute(['hash' => $hash, 'id' => $user['id']]);

echo json_encode(['success' => true]);
