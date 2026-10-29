<?php
// Safe demonstration: no config, credentials, database, or network dependency.
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
require_once __DIR__ . '/../includes/sms-core.php';
$events = ['account_approved','borrow_ready','borrow_rejected','checkout','reservation_ready'];
$phone = $argv[1] ?? '09123456789';
$details = ['title' => 'Demonstration Book', 'due_date' => '2026-11-11 10:30:00', 'pickup_at' => '2026-10-29 09:00:00'];
foreach ($events as $event) {
    $message = smsEventMessage($event, $details);
    $result = smsDispatch(['mode' => 'simulate'], $phone, $message, static function() {
        throw new LogicException('Simulation must not call a transport.');
    });
    echo json_encode(['event' => $event, 'test_recipient' => smsPhone($phone), 'message' => $message,
        'result' => $result, 'database_written' => false], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
}
