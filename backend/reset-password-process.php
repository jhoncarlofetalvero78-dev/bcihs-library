<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db_connect.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new DomainException('method_not_allowed', 405);
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    if (!is_array($input)) throw new DomainException('Enter a valid recovery form.', 422);
    $recovery = $_SESSION['password_recovery'] ?? null;
    $code = $input['code'] ?? '';
    $password = $input['password'] ?? '';
    if (!is_array($recovery) || $recovery['expires'] < time()
        || securityPhone($input['phone'] ?? null) !== $recovery['phone']
        || ($input['role'] ?? '') !== $recovery['role']) throw new DomainException('Request a new code in this browser.', 422);
    if (!is_string($code) || !preg_match('/^\d{6}$/D', $code)
        || !is_string($password) || strlen($password) < 8 || strlen($password) > 72) {
        throw new DomainException('Enter a six-digit code and a password of 8 to 72 bytes.', 422);
    }
    if (!securityBudget('reset-code', $recovery['marker'], 5, 900)
        || !securityBudget('reset-verify-ip', $_SERVER['REMOTE_ADDR'] ?? 'local', 30, 900)) {
        unset($_SESSION['password_recovery']);
        throw new DomainException('Too many attempts. Request a new code after the cooldown.', 429);
    }
    if (!password_verify($code, $recovery['hash'])) throw new DomainException('That code is invalid or expired.', 422);
    $pdo->exec("SET time_zone='+08:00'");
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT reset_code FROM users WHERE id=:id AND reset_code_expires>NOW() FOR UPDATE');
    $stmt->execute(['id'=>$recovery['id']]);
    $marker = $stmt->fetchColumn();
    if (!$marker || !hash_equals($recovery['marker'], $marker)) throw new DomainException('That code is invalid or expired.', 422);
    $pdo->prepare('UPDATE users SET password_hash=:hash,reset_code=NULL,reset_code_expires=NULL WHERE id=:id')
        ->execute(['hash'=>password_hash($password, PASSWORD_DEFAULT), 'id'=>$recovery['id']]);
    $pdo->commit();
    unset($_SESSION['password_recovery']);
    // Remove this browser's authenticated identity; other initialized sessions fail their hash check.
    foreach (['user_id','admin_id','role','password_fingerprint'] as $key) unset($_SESSION[$key]);
    session_regenerate_id(true);
    echo json_encode(['success'=>true]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $expected = $exception instanceof DomainException;
    http_response_code($expected ? $exception->getCode() : 503);
    echo json_encode(['error'=>$expected ? $exception->getMessage() : 'Recovery is temporarily unavailable. Please try again later.']);
}
