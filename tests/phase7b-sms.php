<?php
// Database-free suite: PDO storage is an in-memory fake; transport is mocked.
require_once __DIR__ . '/../includes/sms.php';
$checks = 0;
function verify(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    $checks++; echo 'PASS: ' . $message . PHP_EOL;
}
class SmsMemoryPDO extends PDO {
    public array $rows = [];
    public bool $transaction = false, $fail = false, $lockAvailable = true, $failUpdate = false;
    public mixed $permission = ['allow_notifications'=>'1','version'=>'1'];
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $statement=$this->prepare($query);$statement->execute();return $statement;
    }
    public string $due = '2026-11-11 10:30:00';
    public function __construct() {}
    public function inTransaction(): bool {return $this->transaction;}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if ($this->fail) throw new RuntimeException('Simulated storage failure.');
        return new SmsMemoryStatement($this, $query);
    }
    public function lastInsertId(?string $name = null): string|false {return (string)count($this->rows);}
}
class SmsMemoryStatement extends PDOStatement {
    private mixed $result = false;
    public function __construct(private SmsMemoryPDO $db, private string $sql) {}
    public function execute(?array $params = null): bool {
        $params = $params ?? [];
        if ($this->sql==='SELECT DATABASE()') $this->result='mock_sms_database';
        elseif ($this->sql==='SELECT id FROM sms_notification_audit LIMIT 0') $this->result=false;
        elseif (str_contains($this->sql,'FROM sms_notification_settings')) $this->result=$this->db->permission;
        elseif (str_contains($this->sql, 'GET_LOCK')) $this->result = $this->db->lockAvailable ? 1 : 0;
        elseif (str_contains($this->sql, 'RELEASE_LOCK')) $this->result = 1;
        elseif (str_contains($this->sql, 'JSON_VALID')) {
            $this->result = false;
            foreach ($this->db->rows as $row) if ((json_decode($row['response'],true)['event_key']??null)===$params['key']) $this->result=['id'=>$row['id']];
        } elseif (str_starts_with($this->sql,'INSERT')) {
            $this->db->rows[]=['id'=>count($this->db->rows)+1,'user_id'=>$params['user'],'phone'=>$params['phone'],'message'=>$params['message'],'status'=>'skipped','response'=>$params['response']];
        } elseif (str_starts_with($this->sql,'UPDATE')) {
            if ($this->db->failUpdate) throw new RuntimeException('Interrupted final log write.');
            $i=$params['id']-1;$this->db->rows[$i]['status']=$params['status'];$this->db->rows[$i]['response']=$params['response'];
        } elseif (str_contains($this->sql,'SELECT phone')) $this->result='09123456789';
        elseif (str_contains($this->sql,'SELECT due_date')) $this->result=$this->db->due;
        else throw new RuntimeException('Unexpected fake SQL: '.$this->sql);
        return true;
    }
    public function fetchColumn(int $column = 0): mixed {return $this->result;}
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {return $this->result;}
}
$originalMode=getenv('BCIHS_SMS_MODE');$originalLive=getenv('BCIHS_SMS_LIVE_ENABLED');
$originalProvider=getenv('BCIHS_SMS_PROVIDER');putenv('BCIHS_SMS_PROVIDER=simulation');
putenv('BCIHS_SMS_MODE=simulate');putenv('BCIHS_SMS_LIVE_ENABLED=0');
try {
    foreach(['09123456789','+639123456789','639123456789','(0912) 345-6789'] as $phone) verify(smsPhone($phone)==='09123456789','Philippine mobile format normalized');
    foreach([null,'','123','091234567890','09123456789,09999999999','+12025550123',[],"09123456789\nBAD"] as $phone) verify(smsPhone($phone)===null,'invalid/multi-recipient phone rejected');
    $calls=0;$transport=function($payload)use(&$calls){$calls++;throw new RuntimeException('Simulation must never call transport.');};
    $result=smsDispatch(['mode'=>'simulate','key'=>'FAKE_TEST_KEY','live_enabled'=>true],'09123456789','BCIHS Library: Safe test.',$transport);
    verify($result['state']==='simulated' && $calls===0,'simulation bypasses transport even with a key and live flag');
    verify(smsConfig()['mode']==='simulate' && smsConfig()['key']==='','simulation does not load a private provider key');
    verify(smsDispatch(['mode'=>'disabled'],'09123456789','Message',$transport)['state']==='skipped' && $calls===0,'unsupported mode fails closed');
    verify(smsDispatch(['mode'=>'live','live_enabled'=>false],'09123456789','Message',$transport)['state']==='skipped','live mode alone cannot send');
    verify(smsDispatch(['mode'=>'live','live_enabled'=>true,'key'=>''],'09123456789','Message',$transport)['reason']==='missing_provider_credentials','missing credentials handled without transport');
    verify(smsDispatch(['mode'=>'simulate'],'bad','Message',$transport)['state']==='skipped','invalid phone skipped before simulation');
    verify(smsDispatch(['mode'=>'simulate'],'09123456789','',$transport)['reason']==='invalid_message','empty message rejected');
    $config=['mode'=>'live','live_enabled'=>true,'key'=>'FAKE_ONLY','sender'=>'School'];
    foreach(['Queued'=>'queued','Pending'=>'pending','Sent'=>'accepted','Failed'=>'failed','Refunded'=>'failed','Delivered'=>'unknown'] as $provider=>$expected) {
        $r=smsDispatch($config,'09123456789','Message',static function($payload)use($provider){
            if($payload['number']!=='09123456789' || $payload['apikey']!=='FAKE_ONLY' || $payload['sendername']!=='School')throw new RuntimeException('Wrong payload');
            return ['http'=>200,'body'=>json_encode([['message_id'=>123,'recipient'=>'639123456789','status'=>$provider]])];
        });
        verify($r['state']===$expected,'mock provider '.$provider.' interpreted as '.$expected);
        verify($r['state']!=='delivered','provider outcome never invents handset delivery');
    }
    foreach([400=>'provider_http_rejected',401=>'provider_authentication_rejected',403=>'provider_authentication_rejected',429=>'provider_rate_limited',500=>'provider_unavailable'] as $http=>$reason) {
        $r=smsDispatch($config,'09123456789','Message',static fn($payload)=>['http'=>$http,'body'=>'FAKE_KEY_AND_PERSONAL_DATA']);
        verify($r['state']===($http>=500?'unknown':'failed') && $r['reason']===$reason && !str_contains(json_encode($r),'FAKE_KEY'),'mock HTTP failure produces safe reason without raw provider data');
    }
    foreach (['Insufficient balance','Unapproved sender name','Invalid number'] as $error) {
        $r=smsDispatch($config,'09123456789','Message',static fn($payload)=>['http'=>400,'body'=>json_encode(['error'=>$error,'private'=>'MUST_NOT_LOG'])]);
        verify($r['state']==='failed' && $r['reason']==='provider_http_rejected' && !str_contains(json_encode($r),'MUST_NOT_LOG'),'Semaphore validation rejection stays safe: '.$error);
    }
    $r=smsDispatch($config,'09123456789','Message',static fn($payload)=>['http'=>0,'body'=>false]);
    verify($r['state']==='unknown','missing HTTP response has an uncertain outcome');
    foreach(['not json','{}','[]','[{"message_id":1,"recipient":"09999999999","status":"Sent"}]','[{"recipient":"09123456789","status":"Queued"}]','[{"message_id":1,"recipient":"09123456789","status":[]}]'] as $body) {
        $r=smsDispatch($config,'09123456789','Message',static fn($payload)=>['http'=>202,'body'=>$body]);
        verify($r['state']==='unknown','HTTP success with unsupported content is not accepted/delivered');
    }
    $r=smsDispatch($config,'09123456789','Message',static fn($payload)=>['error'=>true]);verify($r['state']==='unknown','transport timeout leaves acceptance unknown, preventing blind retries');
    $r=smsDispatch($config,'09123456789','Message',static function($payload){throw new RuntimeException('SECRET');});verify($r['state']==='unknown' && !str_contains(json_encode($r),'SECRET'),'transport exceptions do not expose data');
    foreach(['account_approved','borrow_ready','borrow_rejected','checkout','reservation_ready'] as $event) {
        $details=['title'=>'A book','due_date'=>'2026-11-11 10:30:00','pickup_at'=>'2026-10-29 09:00:00'];
        $message=smsEventMessage($event,$details);
        verify(str_starts_with($message,'BCIHS Library:') && !str_contains($message,'TEST'),'event template is identified and not silently ignored as TEST');
        verify(!str_contains($message,'delivered'),'event template makes no SMS delivery claim');
    }
    verify(str_contains(smsEventMessage('borrow_ready',['title'=>'   ']), '"your book"'), 'blank title uses a meaningful fallback');
    verify(str_contains(smsEventMessage('checkout',['due_date'=>'2026-11-11 10:30:00']),'Nov 11, 2026 10:30 AM (PHT)'),'checkout uses exact stored due date');
    verify(str_contains(smsEventMessage('borrow_ready'),'Not yet checked out'),'approval message distinguishes hold from loan');
    verify(str_contains(smsEventMessage('reservation_ready'),'ready for pickup') && !str_contains(smsEventMessage('reservation_ready'),'1970'),'unscheduled reservation does not invent a date');
    foreach(['due_reminder','overdue','return','announcement_broadcast'] as $event){try{smsEventMessage($event);$blocked=false;}catch(DomainException $e){$blocked=true;}verify($blocked,'optional event disabled: '.$event);}
    try{smsEventMessage('checkout',['due_date'=>'2026-02-30 10:00:00']);$blocked=false;}catch(DomainException $e){$blocked=true;}verify($blocked,'invalid stored due date cannot become a false template');
    $db=new SmsMemoryPDO();
    notifyLibraryEvent($db,1,'account_approved',1,[],'account');
    verify(count($db->rows)===1 && smsLogMetadata($db->rows[0])['state']==='simulated','account approval saved as simulated in fake storage');
    notifyLibraryEvent($db,1,'account_approved',1,[],'account');verify(count($db->rows)===1,'repeated same event produces no duplicate simulated send/log');
    foreach(['borrow_ready','borrow_rejected','reservation_ready'] as $event) notifyLibraryEvent($db,1,$event,20,['title'=>'Book','pickup_at'=>null]);
    notifyCheckoutEvent($db,1,20,'Book');notifyCheckoutEvent($db,1,20,'Book','reservation');
    verify(count($db->rows)===6,'distinct events and borrow/reservation sources have independent keys');
    verify(str_contains($db->rows[4]['message'],'Nov 11, 2026 10:30 AM'),'checkout notification reads stored due date after commit');
    $db->transaction=true;notifyLibraryEvent($db,1,'account_approved',99);verify(count($db->rows)===6,'notification never executes inside business transaction');$db->transaction=false;
    send_sms($db,1,'bad','Message','borrow_ready','bad-phone');verify(smsLogMetadata(end($db->rows))['state']==='skipped','invalid recipient is recorded honestly');
    send_sms($db,1,'09123456789','Code 123456','password_reset','reset-1');verify(!str_contains(end($db->rows)['message'],'123456'),'password reset secret never stored in staff log');
    $db->lockAvailable=false;$before=count($db->rows);send_sms($db,1,'09123456789','Message','checkout','busy');verify(count($db->rows)===$before,'another request holding event lock prevents duplicate attempt');$db->lockAvailable=true;
    $db->fail=true;verify(send_sms($db,1,'09123456789','Message')===false,'logging failure cannot throw or corrupt main operation');$db->fail=false;
    verify(smsLogMetadata(['status'=>'sent','response'=>'RAW SECRET'])['state']==='legacy_sent','historical sent remains unverified without modifying row');
    verify(!str_contains(json_encode(smsLogMetadata(['status'=>'failed','response'=>'RAW SECRET'])),'SECRET'),'raw historical response is excluded from safe status');
    $public=smsPublicLog(['id'=>1,'full_name'=>'Borrower','phone'=>'09123456789','message'=>'BCIHS Library: Your password reset code is 123456',
        'status'=>'sent','response'=>'PRIVATE_PROVIDER_API_KEY_RESPONSE','created_at'=>'2026-10-28 09:00:00']);
    verify($public['phone']==='*******6789' && !str_contains(json_encode($public),'09123456789'),'public log masks original phone');
    verify(!str_contains(json_encode($public),'123456') && !str_contains(json_encode($public),'PRIVATE_PROVIDER'),'legacy OTP and raw provider data cannot leave log API');
    $db->failUpdate=true;send_sms($db,1,'09123456789','Message','borrow_ready','partial-log');$before=count($db->rows);$db->failUpdate=false;
    verify(smsLogMetadata(end($db->rows))['reason']==='attempt_reserved_not_delivery','interrupted final log retains a conservative reserved attempt');
    send_sms($db,1,'09123456789','Message','borrow_ready','partial-log');verify(count($db->rows)===$before,'interrupted attempts are not automatically sent again');
    foreach(['backend/borrow-request-action.php','backend/reservation-action.php','backend/user-approval-action.php'] as $file){
        $source=file_get_contents(__DIR__.'/../'.$file);$commit=strpos($source,'$pdo->commit();');$notify=strpos($source,'notifyLibraryEvent(');
        verify($commit!==false && $notify>$commit,'actual endpoint notification hook follows transaction commit: '.$file);
    }
    foreach ([false,['allow_notifications'=>'2','version'=>'1'],['allow_notifications'=>'1','version'=>'bad']] as $permission) {
        $db->permission=$permission;
        verify(!smsPermissionState($db)['allowed'],'missing/corrupt permission fails closed');
    }
    $db->permission=['allow_notifications'=>'0','version'=>'2'];
    send_sms($db,1,'09123456789','Message','account_approved','off-test');
    verify(smsLogMetadata(end($db->rows))['reason']==='notifications_disabled' && end($db->rows)['phone']==='(suppressed)','OFF is safely logged without retaining a phone');
    $db->permission=['allow_notifications'=>'1','version'=>'3'];
    $before=count($db->rows);send_sms($db,1,'09123456789','Message','account_approved','off-test');
    verify(count($db->rows)===$before,'disabled event key cannot be replayed');
    $logs=file_get_contents(__DIR__.'/../backend/sms-logs-data.php');
    verify(str_contains($logs,'requireBookAccess($pdo, true)') && str_contains($logs,"!== 'GET'"),'staff logs use current database permissions and GET-only access');
    verify(str_contains($logs,'smsPublicLog($row)') && !str_contains($logs,"'response' =>"),'actual staff API uses tested privacy projection');
    $reset=file_get_contents(__DIR__.'/../backend/forgot-password-request.php');
    verify(!str_contains($reset,'has been sent by SMS') && str_contains($reset,'Simulation mode sends no SMS'),'password reset response makes no false sending/delivery promise');
    echo "Completed {$checks} Phase 7B simulated/mock PHP checks. No database connection or network request was made.\n";
} finally {
    putenv($originalMode===false?'BCIHS_SMS_MODE':'BCIHS_SMS_MODE='.$originalMode);
    putenv($originalLive===false?'BCIHS_SMS_LIVE_ENABLED':'BCIHS_SMS_LIVE_ENABLED='.$originalLive);
    putenv($originalProvider===false?'BCIHS_SMS_PROVIDER':'BCIHS_SMS_PROVIDER='.$originalProvider);
}
