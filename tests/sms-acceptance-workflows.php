<?php
if (getenv('BCIHS_PHASE8_ISOLATED') !== '1' || !getenv('BCIHS_SMS_ACCEPTANCE_ROOT')) {
    throw new RuntimeException('Disposable SMS environment required.');
}
require_once __DIR__.'/../includes/sms-core.php';
$mockRoot = getenv('BCIHS_SMS_ACCEPTANCE_ROOT');
$calls = static function () use ($mockRoot): array {
    return array_map(static fn($line)=>json_decode($line,true),
        file_exists($mockRoot.'/mock-calls.jsonl') ? file($mockRoot.'/mock-calls.jsonl', FILE_IGNORE_NEW_LINES) : []);
};
$selectProvider = static function (string $provider) use ($mockRoot): void {
    $networks=[];for($i=1;$i<=8;$i++) $networks['0917000010'.$i]='globe';
    $config=['provider'=>$provider,'mode'=>$provider==='simulation'?'simulate':'live',
        'live_enabled'=>$provider!=='simulation','sender'=>'MockSchool',
        'unisms_secret'=>'SYNTHETIC_ONLY','unisms_verified_networks'=>$networks];
    file_put_contents($mockRoot.'/includes/sms-local.php','<?php return '.var_export($config,true).';');
    file_put_contents($mockRoot.'/includes/semaphore-api-key.php',"<?php return 'SYNTHETIC_ONLY';");
};
$assertNotification = static function (string $provider,int $uid,string $phone,string $event,string $eventId,string $message,int $before) use ($pdo,$calls): void {
    $q=$pdo->prepare('SELECT * FROM sms_logs WHERE JSON_UNQUOTE(JSON_EXTRACT(response,"$.event_key"))=:key');
    $q->execute(['key'=>hash('sha256',$event.':'.$eventId)]);$rows=$q->fetchAll();
    check(count($rows)===1,'one connected log: '.$provider.' / '.$event.' / '.$eventId);
    $row=$rows[0];$meta=smsLogMetadata($row);
    check((int)$row['user_id']===$uid && $row['phone']===$phone,'exact linked account and stored phone');
    check($row['message']===($event==='password_reset'?'BCIHS Library: Password reset code [redacted].':$message),'exact template and secret redaction');
    check($meta['provider']===$provider && $meta['state']===match($provider){'unisms'=>'pending','semaphore'=>'queued',default=>'simulated'},'honest selected-provider log state');
    $all=$calls();check(count($all)===$before+($provider==='simulation'?0:1),'exact mock submission count');
    if($provider!=='simulation') {
        $payload=end($all);
        check(($provider==='unisms'?$payload['body']['recipient']:$payload['number'])===($provider==='unisms'?'+63'.substr($phone,1):$phone),'provider recipient payload');
        check(($provider==='unisms'?$payload['body']['content']:$payload['message'])===$message,'actual provider payload template');
        check($provider==='unisms'?$payload['body']['sender_id']==='Unisoft':$payload['sendername']==='MockSchool','provider sender payload');
    }
};
$slot=(new DateTimeImmutable('next monday 10:00',new DateTimeZone('Asia/Manila')))->format('Y-m-d\TH:i');
foreach(['simulation','unisms','semaphore'] as $index=>$provider) {
    $selectProvider($provider);$accounts=[];
    foreach(['student','teacher'] as $j=>$role) {
        $phone='0917000010'.($index*2+$j+1);$identifier='SMS-'.$index.'-'.$role;
        $s=session();[, $form]=http('registration-token','GET',null,$s);
        $data=['role'=>$role,'full_name'=>'Isolated SMS '.$role,'phone'=>$phone,'password'=>$password,
            'confirm_password'=>$password,'csrf_token'=>$form['csrfToken']];
        if($role==='student')$data+=['student_id'=>$identifier,'year_section'=>'Grade 10 A'];
        else $data+=['id_number'=>$identifier,'department'=>'Faculty'];
        [$code]=http('register-process','POST',$data,$s);check($code===302,'actual '.$role.' registration');
        $q=$pdo->prepare('SELECT id FROM users WHERE phone=:phone');$q->execute(['phone'=>$phone]);$uid=(int)$q->fetchColumn();check($uid>0,'isolated registered account exists');
        $before=count($calls());post('user-approval-action',['id'=>$uid,'status'=>'accepted'],$staff);
        $assertNotification($provider,$uid,$phone,'account_approved','account:'.$uid,smsEventMessage('account_approved'),$before);
        post('user-approval-action',['id'=>$uid,'status'=>'accepted'],$staff,409);check(count($calls())===$before+($provider==='simulation'?0:1),'repeat approval no transmission');
        $accounts[$role]=[$uid,$phone,login($identifier)];
    }
    foreach(['visitor','librarian'] as $role) {
        $uid=user($role,'SMS-'.$index.'-'.$role,'pending');$phone='09170000108';
        $pdo->exec('UPDATE users SET phone="'.$phone.'" WHERE id='.$uid);
        $before=count($calls());post('user-approval-action',['id'=>$uid,'status'=>'accepted'],$staff);
        $assertNotification($provider,$uid,$phone,'account_approved','account:'.$uid,smsEventMessage('account_approved'),$before);
    }
    [$uid,$phone,$borrower]=$accounts['student'];$b=book();
    $id=post('borrow-request-process',['book_id'=>$b],$borrower)['id'];
    $before=count($calls());post('borrow-request-action',['id'=>$id,'action'=>'approve'],$borrower,403);check(count($calls())===$before,'borrower unauthorized staff action no SMS');
    post('borrow-request-action',['id'=>$id,'action'=>'approve'],$staff);
    $assertNotification($provider,$uid,$phone,'borrow_ready','borrow:'.$id,smsEventMessage('borrow_ready',['title'=>'Phase8 book']),$before);
    check($pdo->query('SELECT due_date FROM borrow_requests WHERE id='.(int)$id)->fetchColumn()===null,'pickup approval has no due date');
    $before=count($calls());post('borrow-request-action',['id'=>$id,'action'=>'checkout'],$staff);
    $due=$pdo->query('SELECT due_date FROM borrow_requests WHERE id='.(int)$id)->fetchColumn();
    $assertNotification($provider,$uid,$phone,'checkout','borrow:'.$id,smsEventMessage('checkout',['title'=>'Phase8 book','due_date'=>$due]),$before);
    post('borrow-request-action',['id'=>$id,'action'=>'checkout'],$staff,409);check(count($calls())===$before+($provider==='simulation'?0:1),'repeat checkout no transmission');
    $before=count($calls());post('borrow-request-action',['id'=>$id,'action'=>'return'],$staff);check(count($calls())===$before,'return intentionally has no SMS');
    $id=post('borrow-request-process',['book_id'=>$b],$borrower)['id'];$before=count($calls());post('borrow-request-action',['id'=>$id,'action'=>'reject'],$staff);
    $assertNotification($provider,$uid,$phone,'borrow_rejected','borrow:'.$id,smsEventMessage('borrow_rejected',['title'=>'Phase8 book']),$before);
    [$uid,$phone,$borrower]=$accounts['teacher'];$b=book();$pdo->exec('UPDATE books SET total_copies=0 WHERE id='.$b);
    $id=post('reservation-process',['book_id'=>$b,'pickup_at'=>$slot],$borrower)['id'];$before=count($calls());
    post('reservation-action',['id'=>$id,'action'=>'ready'],$staff,409);check(count($calls())===$before,'failed stock transition sends no SMS');
    $pdo->exec('UPDATE books SET total_copies=1 WHERE id='.$b);post('reservation-action',['id'=>$id,'action'=>'ready'],$staff);
    $pickup=$pdo->query('SELECT pickup_at FROM reservations WHERE id='.(int)$id)->fetchColumn();
    $assertNotification($provider,$uid,$phone,'reservation_ready','reservation:'.$id,smsEventMessage('reservation_ready',['title'=>'Phase8 book','pickup_at'=>$pickup]),$before);
    $before=count($calls());post('reservation-action',['id'=>$id,'action'=>'fulfill'],$staff);
    $due=$pdo->query('SELECT due_date FROM reservations WHERE id='.(int)$id)->fetchColumn();
    $assertNotification($provider,$uid,$phone,'checkout','reservation:'.$id,smsEventMessage('checkout',['title'=>'Phase8 book','due_date'=>$due]),$before);
    post('reservation-action',['id'=>$id,'action'=>'fulfill'],$staff,409);check(count($calls())===$before+($provider==='simulation'?0:1),'repeat reservation handover sends no SMS');
    $before=count($calls());$reset=session();post('forgot-password-request',['phone'=>$phone,'role'=>'teacher'],$reset);
    $marker=$pdo->query('SELECT reset_code FROM users WHERE id='.$uid)->fetchColumn();
    $assertNotification($provider,$uid,$phone,'password_reset','reset:'.$uid.':'.$marker,"BCIHS Library: Your password reset code is 123456. It expires in 15 minutes. If you didn't request this, ignore this message.",$before);
    post('forgot-password-request',['phone'=>$phone,'role'=>'teacher'],$reset,429);check(count($calls())===$before+($provider==='simulation'?0:1),'recovery rate limit prevents repeat submission');
}
// Interrupted transports reserve their log before transport; switching provider
// and repeating the same business action must never create a second attempt.
foreach(['timeout','malformed','exit'] as $fault) {
    $selectProvider('semaphore');
    $uid=user('student','SMS-FAULT-'.$fault,'pending');
    $pdo->exec('UPDATE users SET phone="09170000108" WHERE id='.$uid);
    file_put_contents($mockRoot.'/mock-fault',$fault);
    $before=count($calls());post('user-approval-action',['id'=>$uid,'status'=>'accepted'],$staff);
    check($pdo->query('SELECT account_status FROM users WHERE id='.$uid)->fetchColumn()==='accepted','committed approval survives '.$fault);
    $q=$pdo->prepare('SELECT * FROM sms_logs WHERE user_id=:id');$q->execute(['id'=>$uid]);$row=$q->fetch();
    check(smsLogMetadata($row)['state']===($fault==='exit'?'pending':'unknown'),'uncertain log remains honest after '.$fault);
    unlink($mockRoot.'/mock-fault');$selectProvider('unisms');
    post('user-approval-action',['id'=>$uid,'status'=>'accepted'],$staff,409);
    check(count($calls())===$before+1,'no retry or cross-provider replay after '.$fault);
}
$selectProvider('semaphore');
$b=book();$id=post('borrow-request-process',['book_id'=>$b],$studentSession)['id'];
$before=count($calls());check($parallel([[$id,'approve',$staff],[$id,'approve',$libSession]])===[200,409],'simultaneous approval has one committed transition');
check(count($calls())===$before+1,'simultaneous approval submits one mock message');
$before=count($calls());$logsBefore=(int)$pdo->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn();
foreach(['09170000999','09170000108'] as $phone) {
    post('forgot-password-request',['phone'=>$phone],session());
    check(count($calls())===$before && (int)$pdo->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn()===$logsBefore,'unknown or ambiguous recovery never selects an arbitrary recipient');
}
post('forgot-password-request',['phone'=>'invalid'],session(),422);
[$code,$logs]=http('sms-logs-data','GET',null,$staff);check($code===200 && count($logs['logs'])>0,'staff SMS Logs load all mock states');
foreach($logs['logs'] as $row) {
    check(!preg_match('/09[0-9]{9}/', $row['phone']) && !isset($row['response']),'public logs mask full phones and exclude raw responses');
    if($row['event']==='password_reset') check(!str_contains($row['message'],'123456'),'public recovery log redacts the code');
}
foreach([$studentSession,$teacherSession] as $s) {
    [$code]=http('sms-logs-data','GET',null,$s);check($code===403,'borrower cannot read staff SMS logs');
}
$selectProvider('simulation');
echo "SMS connected workflow matrix complete; all transports were isolated file-only mocks.\n";
