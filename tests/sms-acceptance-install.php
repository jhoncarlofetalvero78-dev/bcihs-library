<?php
// Invoked only by the schema-only disposable QA runner, never by the application.
if (!$smsAcceptance || !$created || realpath($directory) !== $directory
    || !preg_match('/^bcihs_phase8_test_[a-f0-9]{12}$/D', basename($directory))) {
    throw new RuntimeException('Owned disposable environment required.');
}
$path = $directory.'/includes/sms.php';
$code = file_get_contents($path);
$start = strpos($code, 'function smsTransport(');
$end = strpos($code, 'function smsStorageState(', $start);
if ($start === false || $end === false) throw new RuntimeException('Mock transport injection did not match.');
$mock = <<<'PHP'
function smsTransport(array $payload): array
{
    // No socket, cURL or real credentials: persist synthetic payloads locally only.
    $root = getenv('BCIHS_SMS_ACCEPTANCE_ROOT');
    if (!$root || realpath($root) !== dirname(__DIR__)) throw new RuntimeException('Isolation mismatch.');
    file_put_contents($root.'/mock-calls.jsonl', json_encode($payload)."\n", FILE_APPEND | LOCK_EX);
    $fault = @file_get_contents($root.'/mock-fault');
    if ($fault === 'delay') {file_put_contents($root.'/mock-inflight','started');usleep(1500000);}
    if ($fault === 'timeout') return ['error'=>true];
    if ($fault === 'malformed') return ['http'=>200,'body'=>'not JSON'];
    if ($fault === 'exit') exit;
    if (($payload['provider'] ?? '') === 'unisms') {
        return ['http'=>201,'body'=>json_encode(['message'=>['status'=>'pending',
            'recipient'=>$payload['body']['recipient'],'reference_id'=>'msg_isolated-mock']])];
    }
    return ['http'=>200,'body'=>json_encode([['message_id'=>900,
        'recipient'=>$payload['number'],'status'=>'Queued']])];
}

PHP;
file_put_contents($path, substr($code,0,$start).$mock.substr($code,$end));
// Legacy permission probes use a copy-only acceptance guard stub. The actual
// production policy runs unchanged in the workflow matrix; the real one-attempt
// acceptance guard runs in its separate concurrency/fault-injection suite.
$path = $directory.'/includes/sms-live-test.php';
$code = file_get_contents($path);
$code = str_replace(['function smsReserveLiveTest(', 'function smsRestoreLiveTest('],
    ['function acceptanceUnusedReserve(', 'function acceptanceUnusedRestore('], $code, $count);
if ($count !== 2) throw new RuntimeException('Guard-copy injection did not match.');
file_put_contents($path, $code."\nfunction smsReserveLiveTest(array \$config, string \$phone, string \$message, array \$context): ?string { return null; }\nfunction smsRestoreLiveTest(array \$config): bool { return true; }\n");
// CLI-only submission probe in the disposable copy, for permission/race integration tests.
file_put_contents($directory.'/qa-sms-send.php', <<<'PROBE'
<?php
if (PHP_SAPI !== 'cli' || getenv('BCIHS_PHASE8_ISOLATED') !== '1'
    || realpath(getenv('BCIHS_SMS_ACCEPTANCE_ROOT') ?: '') !== __DIR__
    || !preg_match('/^bcihs_phase8_test_[a-f0-9]{12}$/D', getenv('BCIHS_DB_NAME') ?: '')) exit(1);
require __DIR__.'/includes/db_connect.php';
require __DIR__.'/includes/sms.php';
$input=json_decode($argv[1]??'',true,512,JSON_THROW_ON_ERROR);
echo json_encode(['success'=>send_sms($pdo,null,$input['phone'],$input['message'],$input['event'],$input['event_id'])]);
PROBE);
