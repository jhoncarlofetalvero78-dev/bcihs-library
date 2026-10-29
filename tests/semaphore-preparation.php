<?php
if (function_exists('curl_exec')) exit("Run with curl_init,curl_exec,curl_multi_init disabled.\n");
require_once __DIR__ . '/../includes/sms.php';
$checks=0;
function semaphoreCheck(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: '.$label);
    $checks++;
}
$config=['provider'=>'semaphore','mode'=>'live','live_enabled'=>true,'key'=>'SYNTHETIC_ONLY',
    'sender'=>'BCIHSLIB','semaphore_sender_status'=>'pending','live_test'=>null];
$calls=0;
$mock=static function(array $payload)use(&$calls):array{
    $calls++;
    semaphoreCheck($payload['sendername']==='BCIHSLIB' && $payload['apikey']==='SYNTHETIC_ONLY','existing Semaphore field mapping unchanged');
    return ['http'=>200,'body'=>'[{"message_id":900,"recipient":"09181234567","status":"Queued"}]'];
};
$permission=['available'=>true,'allowed'=>true];
foreach (['pending','invalid',false,''] as $status) {
    $config['semaphore_sender_status']=$status;
    $result=smsDispatch($config,'09181234567',smsEventMessage('account_approved'),$mock);
    semaphoreCheck($result['reason']==='semaphore_sender_not_approved' && $calls===0,'unapproved configured sender blocks transport');
    semaphoreCheck(smsDeliveryStatus($permission,$config)['delivery_mode']==='Disabled','pending sender never reports live readiness');
}
$config['semaphore_sender_status']='approved';
semaphoreCheck(smsDispatch($config,'09181234567',smsEventMessage('account_approved'),$mock)['state']==='queued' && $calls===1,'explicitly approved mock Semaphore remains functional');
semaphoreCheck(smsDeliveryStatus($permission,$config)['delivery_mode']==='Live','approved mock configuration reports readiness');
$config['mode']='simulate';$config['semaphore_sender_status']='pending';
semaphoreCheck(smsDispatch($config,'09181234567',smsEventMessage('account_approved'),$mock)['state']==='simulated' && $calls===1,'pending sender still allows zero-credit simulation');
$uni=['provider'=>'unisms','mode'=>'live','live_enabled'=>true,'key'=>'SYNTHETIC_ONLY','sender'=>'BCIHSLIB',
    'semaphore_sender_status'=>'pending','unisms_delivery'=>'production','unisms_production_authorized'=>true,
    'live_test'=>null,'unisms_verified_networks'=>['09911234567'=>'dito']];
foreach (['pending','approved'] as $status) {
    $uni['semaphore_sender_status']=$status;
    $result=smsDispatch($uni,'09911234567',smsEventMessage('account_approved'),static function($payload):array{
        semaphoreCheck($payload['provider']==='unisms' && $payload['body']['sender_id']==='Unisoft','inactive Semaphore sender never changes active UniSMS payload');
        return ['http'=>201,'body'=>'{"message":{"status":"pending","recipient":"+639911234567","reference_id":"msg_semaphore-preparation"}}'];
    },['event'=>'account_approved','event_id'=>'account:900','user_id'=>900]);
    semaphoreCheck($result['state']==='pending' && smsDeliveryStatus($permission,$uni)['provider']==='unisms','approval metadata alone never switches provider');
}
echo "Completed {$checks} Semaphore preparation checks. Mock transports only; zero database or provider connections.\n";
