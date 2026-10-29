<?php
// One database-scoped lock orders settings changes and notification submissions.
function smsPermissionLock(PDO $pdo): string
{
    $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($database) || $database === '') throw new RuntimeException('SMS database unavailable.');
    $key = 'bcihs-sms-permission-' . substr(hash('sha256', $database), 0, 32);
    $query = $pdo->prepare('SELECT GET_LOCK(:key,5)');
    $query->execute(['key' => $key]);
    if ((int)$query->fetchColumn() !== 1) throw new DomainException('Notifications are busy. Refresh and try again.', 423);
    return $key;
}

function smsPermissionUnlock(PDO $pdo, ?string $key): void
{
    if ($key === null) return;
    try {
        $query = $pdo->prepare('SELECT RELEASE_LOCK(:key)');
        $query->execute(['key' => $key]);
    } catch (Throwable $exception) { error_log('Could not release SMS permission lock.'); }
}

function smsPermissionState(PDO $pdo, bool $forUpdate = false): array
{
    try {
        // An incomplete installation must not authorize sends without its audit dependency.
        $pdo->query('SELECT id FROM sms_notification_audit LIMIT 0');
        $row = $pdo->query('SELECT allow_notifications,version FROM sms_notification_settings WHERE id=1' . ($forUpdate ? ' FOR UPDATE' : ''))->fetch();
        if (!$row || !in_array((string)$row['allow_notifications'], ['0','1'], true)
            || !ctype_digit((string)$row['version']) || (int)$row['version'] < 0) throw new RuntimeException('Invalid setting.');
        return ['available' => true, 'allowed' => (string)$row['allow_notifications'] === '1', 'version' => (int)$row['version']];
    } catch (Throwable $exception) {
        return ['available' => false, 'allowed' => false, 'version' => null];
    }
}

function smsPermissionChange(PDO $pdo, int $adminId, array $input): array
{
    if (!is_bool($input['allowed'] ?? null) || !is_int($input['version'] ?? null) || $input['version'] < 0)
        throw new DomainException('Send a valid notification permission and version.', 422);
    if ($input['allowed'] && ($input['confirm_enable'] ?? false) !== true)
        throw new DomainException('Confirm that enabling notifications may use paid credits if live delivery is separately authorized.', 422);
    $lock = smsPermissionLock($pdo);
    try {
        $pdo->beginTransaction();
        $state = smsPermissionState($pdo, true);
        if (!$state['available']) throw new DomainException('SMS permission storage needs deployment setup. Sending remains disabled.', 503);
        if ($state['version'] !== $input['version']) throw new DomainException('Another administrator changed this setting. Refresh before saving.', 409);
        if ($state['allowed'] !== $input['allowed']) {
            $next = $state['version'] + 1;
            $pdo->prepare('UPDATE sms_notification_settings SET allow_notifications=:allowed,version=:version WHERE id=1')
                ->execute(['allowed' => (int)$input['allowed'], 'version' => $next]);
            $pdo->prepare('INSERT INTO sms_notification_audit(admin_id,previous_allowed,new_allowed,setting_version,changed_at)
                VALUES(:admin,:previous,:new,:version,UTC_TIMESTAMP())')
                ->execute(['admin' => $adminId, 'previous' => (int)$state['allowed'], 'new' => (int)$input['allowed'], 'version' => $next]);
        }
        $pdo->commit();
        return smsPermissionState($pdo);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    } finally { smsPermissionUnlock($pdo, $lock); }
}

function smsDeliveryStatus(array $permission, array $config): array
{
    $provider = in_array($config['provider'] ?? '', ['simulation','unisms','semaphore'], true) ? $config['provider'] : 'disabled';
    $ready = false;
    $mode = 'Disabled';
    $message = 'Private provider configuration is incomplete or live delivery is disabled.';
    if ($provider === 'simulation' || ($config['mode'] ?? '') === 'simulate') {
        $ready = in_array($provider, ['simulation','unisms','semaphore'], true);
        $message = 'Simulation only: no provider requests and no credits used.';
        if ($ready) $mode = 'Simulation';
    } elseif (in_array($provider, ['unisms','semaphore'], true) && ($config['mode'] ?? '') === 'live'
        && ($config['live_enabled'] ?? false) === true && !empty($config['key'])) {
        if ($provider === 'semaphore' && ($config['live_test'] ?? null) === null) {
            $ready = ($config['semaphore_sender_status'] ?? null) === null || $config['semaphore_sender_status'] === 'approved';
            $message = $ready ? 'Private live gates are enabled. Provider restrictions and duplicate protection still apply. Paid credits may be used.'
                : 'The intended Semaphore Sender Name is pending approval. No Semaphore submission is allowed.';
        } elseif ($provider === 'unisms') {
            $test = $config['live_test'] ?? null;
            if (($config['unisms_delivery'] ?? 'acceptance') === 'production') {
                $ready = smsUniProductionReady($config);
                $message = $ready ? 'Private UniSMS production authorization is enabled. Verified Globe/DITO recipients and one-segment content restrictions still apply. Paid credits may be used.'
                    : 'UniSMS production delivery is not separately authorized, or a live-test scope is still configured. Sending is blocked.';
            } elseif (($config['unisms_delivery'] ?? 'acceptance') === 'acceptance') {
                $ready = is_array($test) && ($test['enabled'] ?? false) === true
                    && is_int($test['issued_at'] ?? null) && is_int($test['expires_at'] ?? null)
                    && $test['issued_at'] <= time() && $test['issued_at'] >= time() - 600
                    && $test['expires_at'] > time() && $test['expires_at'] - $test['issued_at'] <= 600
                    && is_string($test['directory'] ?? null) && is_dir($test['directory'])
                    && !file_exists($test['directory'] . '/unisms-live-test.claim');
                $message = $ready ? 'UniSMS is limited to its separately authorized one-attempt account-approval test. All guard checks still apply.'
                    : 'UniSMS live-test authorization is unavailable, expired or consumed. Sending is blocked.';
            } else $message = 'UniSMS delivery policy is invalid. Sending is blocked.';
        }
        if ($ready) $mode = 'Live';
    }
    if (!$permission['available']) $message = 'SMS permission storage is unavailable. Sending is disabled until deployment setup is completed.';
    elseif (!$permission['allowed']) $message = 'Notifications are OFF. Suppressed events will not be queued or replayed. ' . $message;
    return ['provider' => $provider, 'delivery_mode' => $permission['available'] && $permission['allowed'] ? $mode : 'Disabled',
        'configuration_ready' => $ready, 'configuration_message' => $message];
}
