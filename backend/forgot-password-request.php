<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/sms.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new DomainException('method_not_allowed', 405);
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    if (!is_array($input)) throw new DomainException('Enter a valid recovery form.', 422);
    $phone = securityPhone($input['phone'] ?? null);
    $role = $input['role'] ?? '';
    if (!is_string($role) || !in_array($role, ['', 'student', 'teacher', 'librarian', 'staff', 'employee', 'visitor'], true)
        || !$phone) throw new DomainException('Enter the Philippine mobile number registered to your account.', 422);
    // Check before creating a code or changing account recovery fields.
    $config = smsConfig();
    $delivery = smsDeliveryStatus(smsPermissionState($pdo), $config);
    if ($delivery['delivery_mode'] !== 'Live'
        || ($delivery['provider'] === 'unisms' && !smsUniProductionReady($config))) {
        unset($_SESSION['password_recovery']);
        throw new DomainException('SMS password recovery is currently unavailable. Contact library staff for identity-verified assistance. No recovery code was issued.', 503);
    }
    if (!securityBudget('reset-ip', $_SERVER['REMOTE_ADDR'] ?? 'local', 10, 3600)
        || !securityBudget('reset-phone-hour', $phone, 3, 3600)
        || !securityBudget('reset-phone-minute', $phone, 1, 60)) {
        throw new DomainException('Please wait before requesting another code. At most three requests per hour are allowed.', 429);
    }
    unset($_SESSION['password_recovery']);
    $digits = substr($phone, 1);
    $stmt = $pdo->prepare("SELECT id, phone, role FROM users WHERE
        REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),'(',''),')',''),'.','')
        IN (:local,:mobile,:international)" . ($role !== '' ? ' AND role=:role' : '') . ' LIMIT 2');
    $values = ['local'=>$phone, 'mobile'=>$digits, 'international'=>'63'.$digits];
    if ($role !== '') $values['role'] = $role;
    $stmt->execute($values);
    $users = $stmt->fetchAll();
    // Shared/ambiguous phone numbers require staff assistance, never arbitrary account selection.
    if (count($users) === 1) {
        $user = $users[0];
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $marker = bin2hex(random_bytes(5));
        $expires = time() + 900;
        $pdo->exec("SET time_zone='+08:00'");
        $pdo->prepare('UPDATE users SET reset_code=:marker,reset_code_expires=:expires WHERE id=:id')
            ->execute(['marker'=>$marker, 'expires'=>(new DateTimeImmutable('@'.$expires))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s'), 'id'=>$user['id']]);
        // The existing short database column holds a challenge ID, never the SMS code.
        $_SESSION['password_recovery'] = ['id'=>(int)$user['id'], 'phone'=>$phone, 'role'=>$role,
            'marker'=>$marker, 'hash'=>password_hash($code, PASSWORD_DEFAULT), 'expires'=>$expires];
        $sent = send_sms($pdo, (int)$user['id'], $user['phone'],
            "BCIHS Library: Your password reset code is {$code}. It expires in 15 minutes. If you didn't request this, ignore this message.",
            'password_reset', 'reset:'.$user['id'].':'.$marker);
        if (!$sent) {
            // Permission may have been disabled after the initial readiness check.
            unset($_SESSION['password_recovery']);
            $pdo->prepare('UPDATE users SET reset_code=NULL,reset_code_expires=NULL WHERE id=:id AND reset_code=:marker')
                ->execute(['id'=>$user['id'], 'marker'=>$marker]);
            throw new DomainException('The recovery SMS could not be submitted. Contact library staff for identity-verified assistance; do not keep requesting codes.', 503);
        }
    }
    echo json_encode(['success'=>true, 'message'=>'If one eligible account matches, a reset notification is requested. Delivery is not confirmed. Simulation mode sends no SMS. Use this same browser to enter the code.']);
} catch (Throwable $exception) {
    $expected = $exception instanceof DomainException;
    http_response_code($expected ? $exception->getCode() : 503);
    echo json_encode(['error'=>$expected ? $exception->getMessage() : 'Recovery is temporarily unavailable. Please try again later.']);
}
