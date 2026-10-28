<?php
require_once __DIR__ . '/sms-core.php';
require_once __DIR__ . '/sms-permission.php';

function smsSettings(): array
{
    $local = [];
    if (is_file(__DIR__ . '/sms-local.php')) $local = require __DIR__ . '/sms-local.php';
    if (!is_array($local)) throw new RuntimeException('Invalid SMS configuration.');
    $mode = getenv('BCIHS_SMS_MODE');
    $enabled = getenv('BCIHS_SMS_LIVE_ENABLED');
    $sender = getenv('SEMAPHORE_SENDER_NAME');
    $provider = getenv('BCIHS_SMS_PROVIDER');
    return ['mode' => $mode !== false ? $mode : ($local['mode'] ?? 'simulate'),
        'provider' => $provider !== false ? $provider : ($local['provider'] ?? 'simulation'),
        'live_enabled' => $enabled !== false ? $enabled === '1' : ($local['live_enabled'] ?? false) === true,
        'sender' => $sender !== false ? $sender : ($local['sender'] ?? ''),
        'live_test' => $local['live_test'] ?? null,
        'unisms_verified_networks' => is_array($local['unisms_verified_networks'] ?? null) ? $local['unisms_verified_networks'] : []];
}

function smsConfig(): array
{
    // Fail closed: merely installing a private API key can never enable sends.
    $settings = smsSettings();
    $key = '';
    if ($settings['mode'] === 'live' && $settings['live_enabled'] && $settings['provider'] === 'unisms') {
        $local = is_file(__DIR__ . '/sms-local.php') ? require __DIR__ . '/sms-local.php' : [];
        $key = $local['unisms_secret'] ?? '';
    } elseif ($settings['mode'] === 'live' && $settings['live_enabled'] && $settings['provider'] === 'semaphore') {
        $key = getenv('SEMAPHORE_API_KEY') ?: ($_SERVER['SEMAPHORE_API_KEY'] ?? '');
        if ($key === '' && is_file(__DIR__ . '/semaphore-api-key.php')) {
            $value = require __DIR__ . '/semaphore-api-key.php';
            $key = is_string($value) ? trim($value) : '';
        }
    }
    return $settings + ['key' => is_string($key) ? trim($key) : '', 'configuration_file' => __DIR__ . '/sms-local.php'];
}

function smsTransport(array $payload): array
{
    if (($payload['provider'] ?? '') === 'unisms') {
        $curl = curl_init('https://unismsapi.com/api/sms');
        curl_setopt_array($curl, [CURLOPT_POST => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_USERPWD => $payload['secret'] . ':',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload['body'], JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false]);
        $body = curl_exec($curl); $http = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = $body === false;
        curl_close($curl);
        return ['http' => $http, 'body' => $body, 'error' => $error];
    }
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
    return match ($state) {'accepted','queued','pending','sent','retrying' => 'sent', 'failed','unknown' => 'failed', default => 'skipped'};
}

function send_sms(PDO $pdo, ?int $userId, ?string $phone, string $message, string $event = 'legacy', ?string $eventKey = null): bool
{
    $lock = null;
    $permissionLock = null;
    try {
        // Notifications run only after the business transaction has committed.
        if ($pdo->inTransaction()) return false;
        $permissionLock = smsPermissionLock($pdo);
        $permission = smsPermissionState($pdo);
        $eventKey = $eventKey ?? bin2hex(random_bytes(16));
        $key = hash('sha256', $event . ':' . $eventKey);
        $lock = 'bcihs-sms-' . substr($key, 0, 48);
        $query = $pdo->prepare('SELECT GET_LOCK(:key,2)'); $query->execute(['key' => $lock]);
        if ((int)$query->fetchColumn() !== 1) return false;
        $query = $pdo->prepare('SELECT id FROM sms_logs WHERE CASE WHEN JSON_VALID(response)
            THEN JSON_UNQUOTE(JSON_EXTRACT(response,"$.event_key")) ELSE NULL END=:key LIMIT 1');
        $query->execute(['key' => $key]);
        if ($query->fetch()) return false;
        $config = smsConfig();
        $metadata = ['sms_version' => 1, 'event_key' => $key, 'event' => $event,
            'provider' => $config['provider'], 'request_attempted' => null,
            'state' => 'pending', 'reason' => 'attempt_reserved_not_delivery', 'provider_id' => null];
        // Codes never belong in staff-visible logs, even in simulation.
        $loggedMessage = $event === 'password_reset' ? 'BCIHS Library: Password reset code [redacted].' : $message;
        $query = $pdo->prepare('INSERT INTO sms_logs(user_id,phone,message,status,response)
            VALUES(:user,:phone,:message,"skipped",:response)');
        $query->execute(['user' => $userId, 'phone' => $permission['allowed'] ? (smsPhone($phone) ?? '(missing/invalid)') : '(suppressed)',
            'message' => $loggedMessage, 'response' => json_encode($metadata, JSON_THROW_ON_ERROR)]);
        $id = (int)$pdo->lastInsertId();
        $attempted = false;
        $result = !$permission['allowed']
            ? smsResult('skipped', $permission['available'] ? 'notifications_disabled' : 'notification_permission_unavailable')
            : smsDispatch($config, $phone, $message, static function (array $payload) use (&$attempted): array {
            $attempted = true;
            return smsTransport($payload);
        }, ['event' => $event, 'event_id' => $eventKey, 'user_id' => $userId]);
        $metadata = array_merge($metadata, $result, ['request_attempted' => $attempted]);
        $query = $pdo->prepare('UPDATE sms_logs SET status=:status,response=:response WHERE id=:id');
        $query->execute(['status' => smsStorageState($result['state']),
            'response' => json_encode($metadata, JSON_THROW_ON_ERROR), 'id' => $id]);
        return in_array($result['state'], ['queued','pending','accepted','sent','retrying','simulated'], true);
    } catch (Throwable $exception) {
        error_log('Library notification could not be completed; review SMS logs.');
        return false;
    } finally {
        smsPermissionUnlock($pdo, $permissionLock);
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
