<?php
// Database-free: synthetic configuration, no provider transport available.
if (function_exists('curl_exec')) exit("Disable curl_init,curl_exec,curl_multi_init before running this suite.\n");
require_once __DIR__ . '/../includes/sms.php';
$checks = 0;
function policyCheck(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
}
$config = ['provider'=>'unisms','mode'=>'live','live_enabled'=>true,'key'=>'SYNTHETIC_ONLY',
    'unisms_delivery'=>'production','unisms_production_authorized'=>true,'live_test'=>null,
    'unisms_verified_networks'=>['09171234567'=>'globe']];
$calls = 0;
$mock = static function (array $payload) use (&$calls): array {
    $calls++;
    policyCheck($payload['secret']==='SYNTHETIC_ONLY' && $payload['provider']==='unisms', 'selected adapter credential binding');
    policyCheck($payload['body']['recipient']==='+639171234567' && $payload['body']['sender_id']==='Unisoft', 'single private target and default sender');
    return ['http'=>201,'body'=>json_encode(['message'=>['status'=>'pending','recipient'=>$payload['body']['recipient'],'reference_id'=>'msg_policy-mock']])];
};
$context = ['event'=>'account_approved','event_id'=>'account:900','user_id'=>900];
$message = smsEventMessage('account_approved');
foreach ([
    'account_approved'=>['account:900',$message],
    'borrow_ready'=>['borrow:901',smsEventMessage('borrow_ready',['title'=>'Physics'])],
    'borrow_rejected'=>['borrow:902',smsEventMessage('borrow_rejected',['title'=>'Physics'])],
    'reservation_ready'=>['reservation:903',smsEventMessage('reservation_ready',['title'=>'Physics','pickup_at'=>'2026-10-30 10:00:00'])],
    'checkout'=>['borrow:904',smsEventMessage('checkout',['title'=>'Physics','due_date'=>'2026-11-11 10:00:00'])],
    'reserved_checkout'=>['reservation:905',smsEventMessage('checkout',['title'=>'Physics','due_date'=>'2026-11-11 10:00:00'])],
    'password_reset'=>['reset:900:0123456789',"BCIHS Library: Your password reset code is 123456. It expires in 15 minutes. If you didn't request this, ignore this message."],
] as $event=>[$id,$text]) {
    $bound = ['event'=>$event==='reserved_checkout'?'checkout':$event,'event_id'=>$id,'user_id'=>900];
    $before=$calls;
    $result=smsDispatch($config,'09171234567',$text,$mock,$bound);
    policyCheck($calls===$before+1 && $result['state']==='pending', 'supported production workflow: '.$event);
    policyCheck(!str_contains(json_encode($result),'SYNTHETIC_ONLY') && !str_contains(json_encode($result),'09171234567'), 'safe result: '.$event);
}
$never=static function():array {throw new RuntimeException('Unauthorized mock transport reached.');};
foreach ([
    'missing production approval'=>static function(&$c,&$ctx){unset($c['unisms_production_authorized']);},
    'false production approval'=>static function(&$c,&$ctx){$c['unisms_production_authorized']=false;},
    'string production approval'=>static function(&$c,&$ctx){$c['unisms_production_authorized']='true';},
    'unknown policy'=>static function(&$c,&$ctx){$c['unisms_delivery']='automatic';},
    'armed test conflicts'=>static function(&$c,&$ctx){$c['live_test']=['enabled'=>true];},
    'old consumed scope conflicts'=>static function(&$c,&$ctx){$c['live_test']=['enabled'=>false,'event_id'=>'account:364'];},
    'unsupported event'=>static function(&$c,&$ctx){$ctx['event']='legacy';},
    'wrong account binding'=>static function(&$c,&$ctx){$ctx['event_id']='account:901';},
    'missing account'=>static function(&$c,&$ctx){unset($ctx['user_id']);},
    'wrong request binding'=>static function(&$c,&$ctx){$ctx=['event'=>'checkout','event_id'=>'account:900','user_id'=>900];},
    'wrong OTP binding'=>static function(&$c,&$ctx){$ctx=['event'=>'password_reset','event_id'=>'reset:901:0123456789','user_id'=>900];},
    'missing live gate'=>static function(&$c,&$ctx){$c['live_enabled']=false;},
] as $label=>$change) {
    $c=$config;$ctx=$context;$change($c,$ctx);
    $result=smsDispatch($c,'09171234567',$message,$never,$ctx);
    policyCheck($result['state']==='skipped','fail closed: '.$label);
}
$acceptance=$config;unset($acceptance['unisms_delivery']);
policyCheck(smsDispatch($acceptance,'09171234567',$message,$never,$context)['reason']==='live_test_authorization_invalid','missing policy defaults to protected acceptance');
$simulated=$config;$simulated['mode']='simulate';
policyCheck(smsDispatch($simulated,'09171234567',$message,$never,$context)['state']==='simulated','simulation remains independent');
policyCheck(smsDispatch($config,'09181234567',$message,$never,$context)['reason']==='unisms_network_not_verified','production retains network restriction');
policyCheck(smsDispatch($config,'09171234567','Visit example.com',$never,$context)['reason']==='unisms_links_not_allowed','production retains link restriction');
foreach ([str_repeat('A',161),str_repeat('^',81),str_repeat('中',71),str_repeat('😀',36)] as $text) {
    policyCheck(smsDispatch($config,'09171234567',$text,$never,$context)['state']==='skipped','credit segment limit applies before transport');
}
policyCheck(smsUniSegments(str_repeat('A',160))===1 && smsUniSegments(str_repeat('^',80))===1,'GSM-7 character and extension boundaries');
policyCheck(smsUniSegments(str_repeat('中',70))===1 && smsUniSegments(str_repeat('😀',35))===1,'Unicode uses UTF-16 units');
policyCheck(strlen($message)===79 && smsUniSegments($message)===1,'approved acceptance content fits one segment');
foreach ([0,500,503] as $http) {
    $attempts=0;
    $result=smsDispatch($config,'09171234567',$message,static function()use(&$attempts,$http){$attempts++;return ['http'=>$http,'body'=>'PRIVATE_RAW_RESPONSE'];},$context);
    policyCheck($result['state']==='unknown' && $attempts===1,'uncertain outcome has one submission and no fallback');
    policyCheck(!str_contains(json_encode($result),'PRIVATE_RAW_RESPONSE'),'provider body withheld');
}
$permission=['available'=>true,'allowed'=>true];
policyCheck(smsDeliveryStatus($permission,$config)['delivery_mode']==='Live','separate production authorization is recognized');
policyCheck(smsDeliveryStatus(['available'=>true,'allowed'=>false],$config)['delivery_mode']==='Disabled','admin permission OFF wins');
$notAuthorized=$config;$notAuthorized['unisms_production_authorized']=false;
policyCheck(smsDeliveryStatus($permission,$notAuthorized)['delivery_mode']==='Disabled','production private approval required');
echo "Completed {$checks} UniSMS production policy checks. Mock transports only; no database or provider requests.\n";
