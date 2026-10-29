<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/db_connect.php';

$file = __DIR__ . '/../includes/sms-local.php';
$local = [];
$readable = false;
try {
    if (is_file($file)) {
        $value = require $file;
        if (is_array($value)) {
            $local = $value;
            $readable = true;
        }
    }
    $settings = smsConfig();
    $permission = smsPermissionState($pdo);
} catch (Throwable $exception) {
    $settings = [];
    $permission = ['available' => false, 'allowed' => false];
}

$safePermissions = $readable && (fileperms($file) & 0777) === 0600;
$consumedClaim = false;
$authorization = $local['live_test'] ?? null;
if (!is_array($authorization) || ($authorization['event_id'] ?? null) !== 'account:364') {
    $authorization = $local['previous_live_test'] ?? null;
}
if (is_array($authorization) && ($authorization['event_id'] ?? null) === 'account:364'
    && is_string($authorization['directory'] ?? null)) {
    $claim = $authorization['directory'] . '/unisms-live-test.claim';
    if (is_file($claim) && !is_link($claim)) {
        $record = json_decode((string) @file_get_contents($claim), true);
        $consumedClaim = is_array($record)
            && ($record['event_hash'] ?? null) === hash('sha256', 'account_approved:account:364');
    }
}

$consumedLatestClaim = false;
$latest = $local['consumed_live_tests']['account:368'] ?? ($local['live_test'] ?? null);
if (is_array($latest) && ($latest['event_id'] ?? null) === 'account:368'
    && is_string($latest['directory'] ?? null)) {
    $claim = $latest['directory'] . '/unisms-live-test.claim';
    if (is_file($claim) && !is_link($claim)) {
        $record = json_decode((string) @file_get_contents($claim), true);
        $consumedLatestClaim = is_array($record)
            && ($record['event_hash'] ?? null) === hash('sha256', 'account_approved:account:368');
    }
}
$summary = smsSafeDiagnosticSummary($local, $settings, $permission, $readable, $safePermissions, $consumedClaim, $consumedLatestClaim);
$semaphoreKeyFile = __DIR__ . '/../includes/semaphore-api-key.php';
$semaphoreKeyPresent = false;
if (is_file($semaphoreKeyFile)) {
    ob_start();
    try {
        $value = require $semaphoreKeyFile;
        $semaphoreKeyPresent = is_string($value) && trim($value) !== '';
    } catch (Throwable $exception) {
        $semaphoreKeyPresent = false;
    } finally { ob_end_clean(); }
}
$summary['semaphore_key_file_exists'] = is_file($semaphoreKeyFile);
$summary['semaphore_key_file_permissions_safe'] = is_file($semaphoreKeyFile) && !is_link($semaphoreKeyFile)
    && (fileperms($semaphoreKeyFile) & 0777) === 0600;
$summary['semaphore_environment_key_present'] = !empty(getenv('SEMAPHORE_API_KEY')) || !empty($_SERVER['SEMAPHORE_API_KEY']);
$summary['semaphore_api_key_present'] = $semaphoreKeyPresent || $summary['semaphore_environment_key_present'];
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
