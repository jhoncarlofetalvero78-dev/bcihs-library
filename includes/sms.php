<?php
/**
 * SMS notifications for the BCIHS Library Management System.
 * Uses Semaphore (semaphore.co), a Philippines-focused SMS gateway API.
 *
 * Configure SEMAPHORE_API_KEY in the PHP environment. Until a key is
 * configured, every send is safely logged as "skipped".
 */

$semaphoreApiKey = getenv('SEMAPHORE_API_KEY');
if ($semaphoreApiKey === false || $semaphoreApiKey === '') {
    $semaphoreApiKey = $_SERVER['SEMAPHORE_API_KEY'] ?? '';
}
if ($semaphoreApiKey === '' && is_file(__DIR__ . '/semaphore-api-key.php')) {
    $configuredKey = require __DIR__ . '/semaphore-api-key.php';
    $semaphoreApiKey = is_string($configuredKey) ? $configuredKey : '';
}
define('SEMAPHORE_API_KEY', $semaphoreApiKey === false ? '' : trim($semaphoreApiKey));
define('SEMAPHORE_SENDER_NAME', ''); // optional — leave blank to use your default sender

/**
 * Sends an SMS through Semaphore and logs the attempt (sent/failed/skipped)
 * to the sms_logs table no matter what happens. Never throws — a failed
 * or skipped SMS should never break the borrow/reservation action it's
 * attached to.
 */
function send_sms(PDO $pdo, ?int $userId, ?string $phone, string $message): bool
{
    $phone = trim((string) $phone);

    if ($phone === '') {
        log_sms($pdo, $userId, '(no phone on file)', $message, 'skipped', 'No phone number stored for this account.');
        return false;
    }

    if (SEMAPHORE_API_KEY === '') {
        log_sms($pdo, $userId, $phone, $message, 'skipped', 'SEMAPHORE_API_KEY has not been configured yet.');
        return false;
    }

    $payload = [
        'apikey'  => SEMAPHORE_API_KEY,
        'number'  => $phone,
        'message' => $message,
    ];
    if (SEMAPHORE_SENDER_NAME !== '') {
        $payload['sendername'] = SEMAPHORE_SENDER_NAME;
    }

    $ch = curl_init('https://api.semaphore.co/api/v4/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        log_sms($pdo, $userId, $phone, $message, 'failed', 'cURL error: ' . $curlError);
        return false;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        log_sms($pdo, $userId, $phone, $message, 'sent', $response);
        return true;
    }

    log_sms($pdo, $userId, $phone, $message, 'failed', "HTTP {$httpCode}: {$response}");
    return false;
}

/** Records one SMS attempt — success, failure, or skip — in sms_logs. */
function log_sms(PDO $pdo, ?int $userId, string $phone, string $message, string $status, ?string $response): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO sms_logs (user_id, phone, message, status, response)
         VALUES (:user_id, :phone, :message, :status, :response)'
    );
    $stmt->execute([
        'user_id'  => $userId,
        'phone'    => $phone,
        'message'  => $message,
        'status'   => $status,
        'response' => $response,
    ]);
}
