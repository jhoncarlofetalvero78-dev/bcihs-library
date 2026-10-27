<?php
// Run with curl_init,curl_exec,curl_multi_init disabled. No database or HTTP connection.
if (function_exists('curl_init')) {
    fwrite(STDERR, "Run with -d disable_functions=curl_init,curl_exec,curl_multi_init\n");
    exit(1);
}
// Transport wiring is reviewed through source assertions; dispatch uses closures below.
require __DIR__ . '/phase7b-sms.php';
$start = $checks;
$uniMockRoot = realpath(sys_get_temp_dir()) . '/bcihs-unisms-mock-' . bin2hex(random_bytes(8));
mkdir($uniMockRoot, 0700);
register_shutdown_function(static function () use ($uniMockRoot): void {
    foreach (glob($uniMockRoot . '/*') as $directory) {
        foreach (glob($directory . '/*') as $file) unlink($file);
        rmdir($directory);
    }
    rmdir($uniMockRoot);
});
function uniMockDispatch(array $config, $phone, string $message, callable $transport, array $context = []): array
{
    global $uniMockRoot;
    if (($config['provider'] ?? '') === 'unisms') {
        $directory = $uniMockRoot . '/' . bin2hex(random_bytes(8)); mkdir($directory, 0700);
        $config['live_test'] = ['enabled' => true,'issued_at' => time() - 1,'expires_at' => time() + 120,
            'event_id' => 'account:900','recipient' => '09171234567','directory' => $directory];
        $config['configuration_file'] = $directory . '/settings.php';
        file_put_contents($config['configuration_file'], '<?php return ' . var_export($config, true) . ';');
        chmod($config['configuration_file'],0600);
        $context = $context ?: ['event' => 'account_approved','event_id' => 'account:900','user_id' => 900];
    }
    return smsDispatch($config, $phone, $message, $transport, $context);
}
$config = ['provider' => 'unisms', 'mode' => 'live', 'live_enabled' => true, 'key' => 'MOCK_SECRET_ONLY',
    'unisms_verified_networks' => ['09171234567' => 'globe', '09911234567' => 'dito']];
$calls = 0;
$transport = function ($payload) use (&$calls) {
    $calls++;
    verify($payload['provider'] === 'unisms' && $payload['secret'] === 'MOCK_SECRET_ONLY', 'UniSMS chosen without Semaphore fallback');
    verify($payload['body'] === ['recipient' => '+639171234567', 'content' => smsEventMessage('account_approved'), 'sender_id' => 'Unisoft'], 'documented single-recipient JSON fields and default sender');
    return ['http' => 201, 'body' => '{"message":{"status":"pending","recipient":"+639171234567","reference_id":"msg_mock-123","fail_reason":null}}'];
};
$r = uniMockDispatch($config, '09171234567', smsEventMessage('account_approved'), $transport);
verify($r['state'] === 'pending' && $r['provider_id'] === 'msg_mock-123' && $r['http_status'] === 201 && $calls === 1, 'valid submission stores pending, reference and HTTP status');
verify(!str_contains(json_encode($r), 'MOCK_SECRET'), 'safe result contains no API secret');
foreach (['pending','sent','retrying','failed'] as $status) {
    $r = smsUniResult(201, json_encode(['message' => ['status' => $status, 'recipient' => '09171234567', 'reference_id' => 'msg_mock-123']]), '09171234567');
    verify($r['state'] === $status && $r['state'] !== 'delivered', 'provider state preserved without handset claim: ' . $status);
    verify(smsStorageState($status) === ($status === 'failed' ? 'failed' : 'sent'), 'state fits existing enum without migration');
}
foreach ([401 => 'provider_authentication_rejected',403 => 'provider_authentication_rejected',402 => 'provider_credit_rejected',422 => 'provider_validation_rejected',429 => 'provider_rate_limited',503 => 'provider_unavailable'] as $http => $reason) {
    $r = uniMockDispatch($config, '09171234567', smsEventMessage('account_approved'), static fn($payload) => ['http' => $http, 'body' => '{"message":"PRIVATE_RAW_RESPONSE"}']);
    verify($r['reason'] === $reason && $r['state'] === ($http === 503 ? 'unknown' : 'failed') && !str_contains(json_encode($r), 'PRIVATE_RAW_RESPONSE'), 'safe HTTP rejection/uncertainty: ' . $http);
}
foreach (['Insufficient credits' => 'provider_credit_rejected', "This Sender ID cannot send to Smart, TNT or Sun numbers" => 'provider_network_rejected'] as $text => $reason) {
    verify(smsUniResult(422, json_encode(['errors' => [$text]]), '09171234567')['reason'] === $reason, 'recognizable provider validation receives a safe reason');
}
foreach (['not json','{}','[]','{"message":{"status":"delivered","recipient":"09171234567","reference_id":"msg_mock-123"}}','{"message":{"status":"pending","recipient":"09991234567","reference_id":"msg_mock-123"}}','{"message":{"status":"pending","recipient":"09171234567","reference_id":"<img>"}}','{"message":{"status":[],"recipient":"09171234567","reference_id":"msg_mock-123"}}'] as $body) {
    verify(smsUniResult(201, $body, '09171234567')['state'] === 'unknown', 'malformed, mismatched, or unsupported response remains uncertain');
}
$never = static function ($payload) {throw new RuntimeException('Transport must not be called.');};
foreach (['bad' => 'missing_or_invalid_phone','09181234567' => 'unisms_network_not_verified'] as $phone => $reason) {
    verify(uniMockDispatch($config, $phone, smsEventMessage('account_approved'), $never)['reason'] === $reason, 'invalid or unsupported/unverified recipient blocked before HTTP');
}
foreach (['smart','tnt','sun','unknown'] as $network) {
    $blocked = $config; $blocked['unisms_verified_networks']['09171234567'] = $network;
    verify(uniMockDispatch($blocked, '09171234567', smsEventMessage('account_approved'), $never)['reason'] === 'unisms_network_not_verified', 'disallowed recipient network: ' . $network);
}
foreach (['https://example.com','www.example.com','example.com','https://例え.jp','ftp://example.com','example.ph/path'] as $link) {
    verify(uniMockDispatch($config, '09171234567', 'Book ' . $link, $never)['reason'] === 'unisms_links_not_allowed', 'links blocked even when supplied through a title');
}
verify(uniMockDispatch($config, '09171234567', str_repeat('a',161), $never)['reason'] === 'unisms_message_too_long', 'local credit-conscious length limit');
verify(uniMockDispatch($config, '09171234567', smsEventMessage('account_approved'), static fn($payload) => ['error' => true])['state'] === 'unknown', 'timeout cannot trigger another provider');
verify(uniMockDispatch($config, '09171234567', smsEventMessage('account_approved'), static function($payload) {throw new RuntimeException('PRIVATE_SECRET');})['state'] === 'unknown', 'transport exception is contained');
$simulation = $config; $simulation['provider'] = 'simulation';
verify(uniMockDispatch($simulation, '09171234567', smsEventMessage('account_approved'), $never)['state'] === 'simulated', 'simulation provider ignores live gates and never uses HTTP');
$simulation = $config; $simulation['mode'] = 'simulate';
verify(uniMockDispatch($simulation, '09171234567', smsEventMessage('account_approved'), $never)['state'] === 'simulated', 'UniSMS can be previewed in simulation');
$disabled = $config; $disabled['live_enabled'] = false;
verify(uniMockDispatch($disabled, '09171234567', smsEventMessage('account_approved'), $never)['reason'] === 'live_sending_not_enabled', 'provider selection alone cannot authorize live SMS');
$missing = $config; $missing['key'] = '';
verify(uniMockDispatch($missing, '09171234567', smsEventMessage('account_approved'), $never)['reason'] === 'missing_provider_credentials', 'missing private UniSMS secret is blocked');
$unknown = $config; $unknown['provider'] = 'other';
verify(uniMockDispatch($unknown, '09171234567', smsEventMessage('account_approved'), $never)['reason'] === 'unsupported_provider', 'typo never falls back to a real provider');
foreach (['account_approved','borrow_ready','borrow_rejected','checkout','reservation_ready'] as $event) {
    $message = smsEventMessage($event, ['title' => str_repeat('É',60), 'due_date' => '2026-11-11 10:30:00', 'pickup_at' => '2026-11-11 10:30:00']);
    verify(smsUniRestriction($config, '09171234567', $message) === null, 'shared template meets UniSMS length/content restrictions: ' . $event);
}
$originalProvider = getenv('BCIHS_SMS_PROVIDER'); $originalMode = getenv('BCIHS_SMS_MODE'); $originalLive = getenv('BCIHS_SMS_LIVE_ENABLED');
try {
    putenv('BCIHS_SMS_MODE=simulate'); putenv('BCIHS_SMS_LIVE_ENABLED=0'); putenv('BCIHS_SMS_PROVIDER=semaphore');
    $db = new SmsMemoryPDO(); send_sms($db,1,'09171234567',smsEventMessage('account_approved'),'checkout','same-record');
    putenv('BCIHS_SMS_PROVIDER=unisms');
    verify(send_sms($db,1,'09171234567',smsEventMessage('account_approved'),'checkout','same-record') === false && count($db->rows) === 1, 'switching provider cannot resend an existing event');
    $db->rows[0]['response'] = json_encode(['sms_version' => 1,'event_key' => hash('sha256','checkout:same-record'),'state' => 'unknown','provider' => 'unisms','reason' => 'transport_interrupted_check_provider_before_retry']);
    putenv('BCIHS_SMS_PROVIDER=semaphore');
    verify(send_sms($db,1,'09171234567',smsEventMessage('account_approved'),'checkout','same-record') === false && count($db->rows) === 1, 'uncertain UniSMS outcome cannot fall back to Semaphore');
    verify(smsConfig()['key'] === '', 'disabled simulation does not expose a private key');
} finally {
    putenv($originalProvider === false ? 'BCIHS_SMS_PROVIDER' : 'BCIHS_SMS_PROVIDER=' . $originalProvider);
    putenv($originalMode === false ? 'BCIHS_SMS_MODE' : 'BCIHS_SMS_MODE=' . $originalMode);
    putenv($originalLive === false ? 'BCIHS_SMS_LIVE_ENABLED' : 'BCIHS_SMS_LIVE_ENABLED=' . $originalLive);
}
$row = ['id' => 1, 'full_name' => 'Borrower', 'phone' => '09171234567', 'message' => smsEventMessage('account_approved'), 'created_at' => '2026-10-28 10:00:00', 'status' => 'sent',
    'response' => json_encode(['sms_version' => 1,'state' => 'retrying','event' => 'checkout','provider' => 'unisms','provider_id' => 'msg_mock-123','reason' => 'provider_retrying','http_status' => 201,'request_attempted' => true,'secret' => 'PRIVATE_SECRET'])];
$public = smsPublicLog($row);
verify($public['status'] === 'retrying' && $public['provider'] === 'unisms' && $public['reference'] === 'msg_mock-123' && $public['request_attempted'] === true && $public['http_status'] === 201, 'staff projection preserves safe provider, reference, request and status');
verify(!str_contains(json_encode($public),'PRIVATE_SECRET') && !str_contains(json_encode($public),'09171234567'), 'staff projection excludes secrets and full phone');
$row['response'] = json_encode(['sms_version' => 1,'state' => 'accepted','provider' => 'semaphore','provider_id' => '123']);
verify(smsPublicLog($row)['reference'] === '123', 'Semaphore message reference is visible through the same safe staff projection');
$row['response'] = json_encode(['sms_version' => 1,'state' => 'pending','provider' => 'unisms','provider_id' => '<script>']);
verify(smsPublicLog($row)['reference'] === null, 'unsafe references are excluded before reaching the UI');
$source = file_get_contents(__DIR__ . '/../includes/sms.php');
verify(str_contains($source,"curl_init('https://unismsapi.com/api/sms')") && str_contains($source,'CURLOPT_HTTPAUTH => CURLAUTH_BASIC') && str_contains($source,"CURLOPT_USERPWD => \$payload['secret'] . ':'"), 'transport source uses fixed HTTPS endpoint and Basic secret with empty password');
verify(str_contains($source,"CURLOPT_POSTFIELDS => json_encode(\$payload['body'], JSON_THROW_ON_ERROR)") && str_contains($source,'CURLOPT_FOLLOWLOCATION => false'), 'transport source sends JSON and refuses redirects');

// Exercise every existing event through fake storage, then both mock adapters.
$savedProvider = getenv('BCIHS_SMS_PROVIDER'); $savedMode = getenv('BCIHS_SMS_MODE'); $savedLive = getenv('BCIHS_SMS_LIVE_ENABLED');
try {
    putenv('BCIHS_SMS_PROVIDER=simulation'); putenv('BCIHS_SMS_MODE=simulate'); putenv('BCIHS_SMS_LIVE_ENABLED=0');
    $events = ['account_approved','borrow_ready','borrow_rejected','reservation_ready','borrow_checkout','reservation_checkout','password_reset'];
    foreach ($events as $case) {
        $db = new SmsMemoryPDO();
        $trigger = static function () use ($db, $case): void {
            if ($case === 'borrow_checkout' || $case === 'reservation_checkout') {
                notifyCheckoutEvent($db, 1, 900, 'Mock Book', $case === 'reservation_checkout' ? 'reservation' : 'borrow');
            } elseif ($case === 'password_reset') {
                send_sms($db, 1, '09123456789', "BCIHS Library: Your password reset code is 123456. It expires in 15 minutes. If you didn't request this, you can ignore this message.", 'password_reset', 'mock-reset:900');
            } else {
                notifyLibraryEvent($db, 1, $case, 900, ['title' => 'Mock Book','pickup_at' => '2026-11-11 10:30:00'], $case === 'account_approved' ? 'account' : ($case === 'reservation_ready' ? 'reservation' : 'borrow'));
            }
        };
        $trigger(); $trigger();
        verify(count($db->rows) === 1 && smsLogMetadata($db->rows[0])['state'] === 'simulated', 'event stores once without network: ' . $case);
        $row = $db->rows[0] + ['full_name' => 'Mock Borrower','created_at' => '2026-10-28 10:00:00'];
        verify($row['user_id'] === 1 && $row['phone'] === '09123456789', 'event selects linked user and normalized phone: ' . $case);
        $message = $case === 'password_reset'
            ? "BCIHS Library: Your password reset code is 123456. It expires in 15 minutes. If you didn't request this, you can ignore this message."
            : $row['message'];
        foreach (['unisms','semaphore'] as $provider) {
            $mockConfig = ['provider' => $provider,'mode' => 'live','live_enabled' => true,'key' => 'MOCK_ONLY','sender' => 'School',
                'unisms_verified_networks' => ['09123456789' => 'dito']];
            $attempts = 0;
            if ($provider === 'unisms') $mockConfig['mode'] = 'simulate';
            $result = uniMockDispatch($mockConfig, $row['phone'], $message, static function ($payload) use ($provider, $message, &$attempts): array {
                $attempts++;
                if ($provider === 'unisms') {
                    if ($payload['body']['recipient'] !== '+639123456789' || $payload['body']['content'] !== $message) throw new RuntimeException('Wrong UniSMS target/template');
                    return ['http' => 201,'body' => '{"message":{"status":"pending","recipient":"+639123456789","reference_id":"msg_event-mock"}}'];
                }
                if ($payload['number'] !== '09123456789' || $payload['message'] !== $message) throw new RuntimeException('Wrong Semaphore target/template');
                return ['http' => 200,'body' => '[{"message_id":900,"recipient":"09123456789","status":"Queued"}]'];
            });
            verify($attempts === ($provider === 'unisms' ? 0 : 1) && $result['state'] === ($provider === 'unisms' ? 'simulated' : 'queued'), 'shared event preview or mock adapter preserves template/recipient: ' . $case . ' / ' . $provider);
            $row['response'] = json_encode(array_merge(smsLogMetadata($row), $result, ['sms_version' => 1,'provider' => $provider]));
            verify(smsPublicLog($row)['provider'] === $provider && smsPublicLog($row)['status'] === $result['state'], 'same staff log projection: ' . $case . ' / ' . $provider);
        }
    }
    $message = smsEventMessage('account_approved');
    verify(strlen($message) === 79 && preg_match('/^[A-Za-z0-9 :.,]+$/D', $message) === 1 && strlen($message) <= 160, 'planned approval is 79 basic GSM-7 characters, one standard segment, no links or Unicode');
    $longMessage = str_repeat('A',161) . ' example.com';
    $result = uniMockDispatch(['provider' => 'semaphore','mode' => 'live','live_enabled' => true,'key' => 'MOCK_ONLY'], '09181234567', $longMessage,
        static fn($payload) => ['http' => 200,'body' => '[{"message_id":901,"recipient":"09181234567","status":"Queued"}]']);
    verify($result['state'] === 'queued', 'UniSMS network, link and length restrictions do not leak into Semaphore');
} finally {
    putenv($savedProvider === false ? 'BCIHS_SMS_PROVIDER' : 'BCIHS_SMS_PROVIDER=' . $savedProvider);
    putenv($savedMode === false ? 'BCIHS_SMS_MODE' : 'BCIHS_SMS_MODE=' . $savedMode);
    putenv($savedLive === false ? 'BCIHS_SMS_LIVE_ENABLED' : 'BCIHS_SMS_LIVE_ENABLED=' . $savedLive);
}
echo 'Completed ' . ($checks - $start) . " additional UniSMS mock checks. No database or provider connection.\n";
