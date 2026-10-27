<?php
require_once __DIR__ . '/sms-core.php';

function smsSettings(): array
{
    $local = [];
    if (is_file(__DIR__ . '/sms-local.php')) $local = require __DIR__ . '/sms-local.php';
    if (!is_array($local)) throw new RuntimeException('Invalid SMS configuration.');
    $mode = getenv('BCIHS_SMS_MODE');
    $enabled = getenv('BCIHS_SMS_LIVE_ENABLED');
    $sender = getenv('SEMAPHORE_SENDER_NAME');
    return ['mode' => $mode !== false ? $mode : ($local['mode'] ?? 'simulate'),
        'live_enabled' => $enabled !== false ? $enabled === '1' : ($local['live_enabled'] ?? false) === true,
        'sender' => $sender !== false ? $sender : ($local['sender'] ?? '')];
}

function smsConfig(): array
{
    // Fail closed: merely installing a private API key can never enable sends.
    $settings = smsSettings();
    $key = '';
    if ($settings['mode'] === 'live' && $settings['live_enabled']) {
        $key = getenv('SEMAPHORE_API_KEY') ?: ($_SERVER['SEMAPHORE_API_KEY'] ?? '');
        if ($key === '' && is_file(__DIR__ . '/semaphore-api-key.php')) {
            $value = require __DIR__ . '/semaphore-api-key.php';
            $key = is_string($value) ? trim($value) : '';
        }
    }
    return $settings + ['key' => is_string($key) ? trim($key) : ''];
}

function smsTransport(array $payload): array
{
    $curl = curl_init('https://api.semaphore.co/api/v4/messages');
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false]);
    $body = curl_exec($curl); $http = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = $body === false;
    curl_close($curl);
    return ['http' => $http, 'body' => $body, 'error' => $error];
}

function smsStorageState(string $state): string
{
    // Preserve the existing enum. The versioned response JSON is authoritative.
    return match ($state) {'accepted','queued','pending' => 'sent', 'failed','unknown' => 'failed', default => 'skipped'};
}

function send_sms(PDO $pdo, ?int $userId, ?string $phone, string $message, string $event = 'legacy', ?string $eventKey = null): bool
{
    $lock = null;
    try {
        // Notifications run only after the business transaction has committed.
        if ($pdo->inTransaction()) return false;
        $eventKey = $eventKey ?? bin2hex(random_bytes(16));
        $key = hash('sha256', $event . ':' . $eventKey);
        $lock = 'bcihs-sms-' . substr($key, 0, 48);
        $query = $pdo->prepare('SELECT GET_LOCK(:key,2)'); $query->execute(['key' => $lock]);
        if ((int)$query->fetchColumn() !== 1) return false;
        $query = $pdo->prepare('SELECT id FROM sms_logs WHERE CASE WHEN JSON_VALID(response)
            THEN JSON_UNQUOTE(JSON_EXTRACT(response,"$.event_key")) ELSE NULL END=:key LIMIT 1');
        $query->execute(['key' => $key]);
        if ($query->fetch()) return false;
        $metadata = ['sms_version' => 1, 'event_key' => $key, 'event' => $event,
            'state' => 'pending', 'reason' => 'attempt_reserved_not_delivery', 'provider_id' => null];
        // Codes never belong in staff-visible logs, even in simulation.
        $loggedMessage = $event === 'password_reset' ? 'BCIHS Library: Password reset code [redacted].' : $message;
        $query = $pdo->prepare('INSERT INTO sms_logs(user_id,phone,message,status,response)
            VALUES(:user,:phone,:message,"skipped",:response)');
        $query->execute(['user' => $userId, 'phone' => smsPhone($phone) ?? '(missing/invalid)',
            'message' => $loggedMessage, 'response' => json_encode($metadata, JSON_THROW_ON_ERROR)]);
        $id = (int)$pdo->lastInsertId();
        $result = smsDispatch(smsConfig(), $phone, $message, 'smsTransport');
        $metadata = array_merge($metadata, $result);
        $query = $pdo->prepare('UPDATE sms_logs SET status=:status,response=:response WHERE id=:id');
        $query->execute(['status' => smsStorageState($result['state']),
            'response' => json_encode($metadata, JSON_THROW_ON_ERROR), 'id' => $id]);
        return in_array($result['state'], ['queued','pending','accepted','simulated'], true);
    } catch (Throwable $exception) {
        error_log('Library notification could not be completed; review SMS logs.');
        return false;
    } finally {
        if ($lock !== null) {
            try {$query = $pdo->prepare('SELECT RELEASE_LOCK(:key)');$query->execute(['key' => $lock]);}
            catch (Throwable $exception) {error_log('Could not release notification lock.');}
        }
    }
}

function notifyLibraryEvent(PDO $pdo, ?int $userId, string $event, int $recordId, array $details = [], string $source = 'borrow'): void
{
    if (!$userId) return;
    try {
        if ($pdo->inTransaction()) return;
        $query = $pdo->prepare('SELECT phone FROM users WHERE id=:id'); $query->execute(['id' => $userId]);
        $phone = $query->fetchColumn();
        if ($phone === false) return;
        send_sms($pdo, $userId, $phone, smsEventMessage($event, $details), $event, $source . ':' . $recordId);
    } catch (Throwable $exception) {error_log('Library event notification unavailable.');}
}

function notifyCheckoutEvent(PDO $pdo, ?int $userId, int $id, string $title, string $source = 'borrow'): void
{
    if (!$userId || $pdo->inTransaction()) return;
    try {
        $table = $source === 'reservation' ? 'reservations' : 'borrow_requests';
        $query = $pdo->prepare('SELECT due_date FROM ' . $table . ' WHERE id=:id');$query->execute(['id' => $id]);
        notifyLibraryEvent($pdo, $userId, 'checkout', $id, ['title' => $title, 'due_date' => $query->fetchColumn()], $source);
    } catch (Throwable $exception) {error_log('Checkout notification unavailable.');}
}
