<?php
// One persistent claim for the single-server UniSMS acceptance test. Never auto-reset it.
function smsReserveLiveTest(array $config, string $phone, string $message, array $context): ?string
{
    if (($config['provider'] ?? '') !== 'unisms') return 'live_test_wrong_provider';
    if (($config['mode'] ?? '') !== 'live' || ($config['live_enabled'] ?? false) !== true) return 'live_sending_not_enabled';
    $authorization = $config['live_test'] ?? null;
    $now = time();
    if (!is_array($authorization) || ($authorization['enabled'] ?? false) !== true
        || !is_int($authorization['issued_at'] ?? null) || !is_int($authorization['expires_at'] ?? null)
        || $authorization['issued_at'] > $now || $authorization['issued_at'] < $now - 600
        || $authorization['expires_at'] <= $now || $authorization['expires_at'] <= $authorization['issued_at']
        || $authorization['expires_at'] - $authorization['issued_at'] > 600) {
        return 'live_test_authorization_invalid';
    }
    $userId = $context['user_id'] ?? null;
    $recipient = $authorization['recipient'] ?? null;
    if (!is_int($userId) || $userId < 1 || ($context['event'] ?? '') !== 'account_approved'
        || ($context['event_id'] ?? '') !== 'account:' . $userId
        || ($authorization['event_id'] ?? null) !== $context['event_id']
        || !is_string($recipient) || smsPhone($recipient) !== $recipient || $recipient !== $phone
        || !in_array(($config['unisms_verified_networks'] ?? [])[$recipient] ?? '', ['globe','dito'], true)) {
        return 'live_test_event_or_recipient_mismatch';
    }
    // Exact approved text is a subset of basic GSM-7: no extensions, prefixes or extra segments.
    if (strlen($message) > 160 || !preg_match('/^[A-Za-z0-9 :.,]+$/D', $message)
        || $message !== 'BCIHS Library: Your account is approved. You may sign in to the library portal.') {
        return 'live_test_message_rejected';
    }
    $directory = $authorization['directory'] ?? null;
    if (!is_string($directory) || $directory === '' || realpath($directory) !== $directory
        || !is_dir($directory) || is_link($directory) || !is_writable($directory)) return 'live_test_storage_unavailable';
    foreach ([dirname(__DIR__, 2), $_SERVER['DOCUMENT_ROOT'] ?? ''] as $root) {
        $root = $root !== '' ? realpath($root) : false;
        if ($root !== false && ($directory === $root || str_starts_with($directory . '/', $root . '/'))) return 'live_test_storage_unavailable';
    }
    clearstatcache(true, $directory);
    $permissions = fileperms($directory);
    if ($permissions === false || ($permissions & 0077) !== 0
        || (function_exists('posix_geteuid') && fileowner($directory) !== posix_geteuid())) return 'live_test_storage_unavailable';
    $path = $directory . '/unisms-live-test.claim';
    $oldMask = umask(0077);
    try {
        // Exclusive creation is the atomic decision. Even an empty/interrupted claim stays consumed.
        $handle = @fopen($path, 'x+b');
    } finally {umask($oldMask);}
    if ($handle === false) return file_exists($path) || is_link($path) ? 'live_test_consumed' : 'live_test_storage_unavailable';
    try {
        $record = json_encode(['consumed_at' => $now, 'event_hash' => hash('sha256', 'account_approved:' . $context['event_id']),
            'message_hash' => hash('sha256', $message)], JSON_THROW_ON_ERROR);
        if (!chmod($path, 0600) || fwrite($handle, $record) !== strlen($record) || !fflush($handle)
            || !function_exists('fsync') || !fsync($handle)) return 'live_test_storage_unavailable';
        return null;
    } catch (Throwable $exception) {return 'live_test_storage_unavailable';}
    finally {fclose($handle);}
}

function smsRestoreLiveTest(array $config): bool
{
    // Close the global live window before transport, using only this request's captured payload.
    // Environment-enforced live gates cannot be reset by changing a private file.
    if (getenv('BCIHS_SMS_MODE') !== false && getenv('BCIHS_SMS_MODE') !== 'simulate') return false;
    if (getenv('BCIHS_SMS_LIVE_ENABLED') === '1') return false;
    $path = $config['configuration_file'] ?? null;
    $temporary = null; $handle = null;
    try {
        if (!is_string($path) || realpath($path) !== $path || !is_file($path) || is_link($path)
            || pathinfo($path, PATHINFO_EXTENSION) !== 'php') return false;
        clearstatcache(true, $path);
        if ((fileperms($path) & 0777) !== 0600
            || (function_exists('posix_geteuid') && fileowner($path) !== posix_geteuid())) return false;
        $before = file_get_contents($path);
        if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
        $local = require $path;
        if (!is_array($local) || ($local['provider'] ?? '') !== 'unisms' || ($local['mode'] ?? '') !== 'live'
            || ($local['live_enabled'] ?? false) !== true || ($local['live_test'] ?? null) !== ($config['live_test'] ?? null)) return false;
        $local['mode'] = 'simulate'; $local['live_enabled'] = false; $local['live_test']['enabled'] = false;
        $source = "<?php\n// Private SMS settings. One-attempt live window closed before transport.\nreturn " . var_export($local, true) . ";\n";
        // Same-directory rename is atomic. A PHP extension avoids exposing temporary config as text.
        $temporary = dirname($path) . '/.sms-local-safe-' . bin2hex(random_bytes(12)) . '.php';
        $oldMask = umask(0077);
        try {$handle = @fopen($temporary, 'x+b');} finally {umask($oldMask);}
        if ($handle === false) return false;
        if (!chmod($temporary, 0600) || fwrite($handle, $source) !== strlen($source) || !fflush($handle)
            || !function_exists('fsync') || !fsync($handle)) return false;
        fclose($handle); $handle = null;
        if (file_get_contents($path) !== $before || !@rename($temporary, $path)) return false;
        if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
        clearstatcache(true, $path);
        return (fileperms($path) & 0777) === 0600 && file_get_contents($path) === $source;
    } catch (Throwable $exception) {
        return false;
    } finally {
        if (is_resource($handle)) fclose($handle);
        if ($temporary !== null && is_file($temporary)) @unlink($temporary);
    }
}
