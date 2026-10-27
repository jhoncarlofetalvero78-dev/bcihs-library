<?php
/**
 * Step 1 of the Forgot Password flow.
 * Looks up the account by role + identifier (same identifier used to log
 * in: Student ID, ID Number, or email), confirms the phone number given
 * matches the one on file, then SMS's a 6-digit reset code that expires
 * in 15 minutes.
 *
 * The phone-number match is a lightweight second factor — it means
 * knowing someone's Student ID alone isn't enough to trigger a reset
 * for their account.
 */
session_start();
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/sms.php';
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

$role  = isset($input['role']) ? preg_replace('/[^a-z]/', '', strtolower($input['role'])) : '';
$phone = trim($input['phone'] ?? '');

$phoneDigits = preg_replace('/\D+/', '', $phone);
$normalizedPhone = null;
if (preg_match('/^639\d{9}$/', $phoneDigits)) {
    $normalizedPhone = substr($phoneDigits, 2);
} elseif (preg_match('/^09\d{9}$/', $phoneDigits)) {
    $normalizedPhone = substr($phoneDigits, 1);
} elseif (preg_match('/^9\d{9}$/', $phoneDigits)) {
    $normalizedPhone = $phoneDigits;
}

$allowedRoles = ['student', 'teacher', 'staff', 'employee', 'visitor'];
if ($role !== '' && !in_array($role, $allowedRoles, true)) {
    fail(422, 'Please choose your account type again from the role screen.');
}
if ($phone === '') {
    fail(422, 'Please enter the phone number on file.');
}

$phoneMatch = 'phone = :v';
$phoneParams = ['v' => $phone];
if ($normalizedPhone !== null) {
    $phoneMatch = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', ''), '.', '') IN (:local, :mobile, :international)";
    $phoneParams = [
        'local' => '0' . $normalizedPhone,
        'mobile' => $normalizedPhone,
        'international' => '63' . $normalizedPhone,
    ];
}

if ($role !== '') {
    $stmt = $pdo->prepare("SELECT id, phone FROM users WHERE {$phoneMatch} AND role = :role LIMIT 1");
    $stmt->execute($phoneParams + ['role' => $role]);
} else {
    $stmt = $pdo->prepare("SELECT id, phone FROM users WHERE {$phoneMatch} LIMIT 1");
    $stmt->execute($phoneParams);
}
$user = $stmt->fetch();

// Same generic response whether the account doesn't exist or the phone
// doesn't match, so this endpoint can't be used to probe which IDs/emails
// are registered.
$genericOk = [
    'success' => true,
    'message' => 'If the account and phone match our records, a reset notification is requested. Delivery is not confirmed. Simulation mode sends no SMS.',
];

$storedPhone = $user ? trim((string) $user['phone']) : '';
$storedPhoneDigits = preg_replace('/\D+/', '', $storedPhone);
$phoneMatches = $normalizedPhone !== null
    ? in_array($storedPhoneDigits, ['63' . $normalizedPhone, '0' . $normalizedPhone, $normalizedPhone], true)
    : $storedPhone === $phone;

if (!$user || $storedPhone === '' || !$phoneMatches) {
    echo json_encode($genericOk);
    exit;
}

$code    = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expires = date('Y-m-d H:i:s', time() + 15 * 60);

$pdo->prepare('UPDATE users SET reset_code = :code, reset_code_expires = :expires WHERE id = :id')
    ->execute(['code' => $code, 'expires' => $expires, 'id' => $user['id']]);

send_sms(
    $pdo, (int) $user['id'], $user['phone'],
    "BCIHS Library: Your password reset code is {$code}. It expires in 15 minutes. If you didn't request this, you can ignore this message.",
    'password_reset', 'reset:' . $user['id'] . ':' . hash('sha256', $code . ':' . $expires)
);

echo json_encode($genericOk);
