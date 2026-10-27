<?php
require_once __DIR__ . '/../includes/db_connect.php';

function redirectToForgotPassword(string $message = '', ?string $phone = null, bool $success = false): void
{
    $query = [];
    if ($message !== '') {
        $query['message'] = $message;
    }
    if ($success) {
        $query['success'] = '1';
    }
    if ($phone !== null && $phone !== '') {
        $query['phone'] = $phone;
    }

    header('Location: ../forgot-password.html?' . http_build_query($query));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectToForgotPassword('Invalid request. Please try again.');
}

$phone = trim($_POST['identifier'] ?? '');
if ($phone === '') {
    redirectToForgotPassword('Please enter your phone number.');
}

$userStmt = $pdo->prepare(
    'SELECT id, phone FROM users WHERE phone = :phone LIMIT 1'
);
$userStmt->execute(['phone' => $phone]);
$user = $userStmt->fetch();

if (!$user) {
    redirectToForgotPassword('If a library account matches this phone number, an OTP will be generated.', null, true);
}

$otp = (string) random_int(100000, 999999);
$expires = date('Y-m-d H:i:s', time() + 300);
$otpHash = password_hash($otp, PASSWORD_DEFAULT);

$resetStmt = $pdo->prepare(
    'UPDATE users SET otp_code = :otp_code, otp_expires = :otp_expires, reset_token = NULL, reset_expires = NULL WHERE id = :id'
);
$resetStmt->execute([
    'otp_code' => $otpHash,
    'otp_expires' => $expires,
    'id' => $user['id'],
]);

header('Location: ../reset-password.html?' . http_build_query([
    'message' => 'OTP code generated successfully. Use the code below and complete your new password.',
    'otp' => $otp,
    'phone' => $phone,
]));
exit;
