<?php
// Included only by the existing owned, schema-only SMS acceptance runner.
if (getenv('BCIHS_PHASE8_ISOLATED') !== '1' || !getenv('BCIHS_SMS_ACCEPTANCE_ROOT')) throw new RuntimeException('Isolated environment required.');
(function () use ($pdo, $staff, $studentSession, $teacherSession, $visitorSession, $libSession, $base): void {
    $root = getenv('BCIHS_SMS_ACCEPTANCE_ROOT');
    $calls = static fn(): int => is_file($root.'/mock-calls.jsonl') ? count(file($root.'/mock-calls.jsonl')) : 0;
    $configure = static function (string $provider, bool $live = false) use ($root): void {
        $config=['provider'=>$provider,'mode'=>$live?'live':'simulate','live_enabled'=>$live,
            'sender'=>'MockSchool','unisms_secret'=>'SYNTHETIC_ONLY','unisms_verified_networks'=>['09170000109'=>'globe']];
        file_put_contents($root.'/includes/sms-local.php','<?php return '.var_export($config,true).';');
        file_put_contents($root.'/includes/semaphore-api-key.php',"<?php return 'SYNTHETIC_ONLY';");
    };
    $state = static function () use ($staff): array { [, $data]=http('sms-settings','GET',null,$staff);return $data; };
    $change = static function (bool $allowed) use ($state,$staff): array {
        $data=$state();return post('sms-settings',['allowed'=>$allowed,'version'=>$data['permission']['version'],'confirm_enable'=>$allowed],$staff);
    };
    $probe = static function (string $event, string $id, string $message = 'BCIHS Library: Mock notification.') use ($root): array {
        $input=json_encode(['phone'=>'09170000109','event'=>$event,'event_id'=>$id,'message'=>$message]);
        $p=proc_open([PHP_BINARY,'-d','disable_functions=curl_exec',$root.'/qa-sms-send.php',$input],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]);$body=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        check(proc_close($p)===0 && $error==='','isolated engine probe exits safely');
        return json_decode($body,true,512,JSON_THROW_ON_ERROR);
    };
    [$code]=http('sms-settings','POST',['allowed'=>true,'version'=>0,'confirm_enable'=>true]);check($code===401,'unauthenticated setting change refused');
    foreach ([$studentSession,$teacherSession,$visitorSession] as $session) {
        [$code]=http('sms-settings','GET',null,$session);check($code===403,'borrower settings read refused');
        post('sms-settings',['allowed'=>false,'version'=>0],$session,403);
    }
    [$code,$view]=http('sms-settings','GET',null,$libSession);check($code===200 && !$view['can_manage'] && $view['audit']===[],'librarian can view status, never administrator audit');
    post('sms-settings',['allowed'=>true,'version'=>$view['permission']['version'],'confirm_enable'=>true],$libSession,403);
    [$code]=http('sms-settings','POST',['allowed'=>false,'version'=>0],$staff,false);check($code===403,'missing CSRF refused');
    $data=$state();
    post('sms-settings',['allowed'=>'false','version'=>$data['permission']['version']],$staff,422);
    post('sms-settings',['allowed'=>true,'version'=>$data['permission']['version']],$staff,422);
    $off=$change(false);check(!$off['permission']['allowed'] && $off['delivery_mode']==='Disabled','OFF is reflected as Disabled');
    $audit=$pdo->query('SELECT * FROM sms_notification_audit ORDER BY id DESC LIMIT 1')->fetch();
    check((int)$audit['admin_id']>0 && (int)$audit['previous_allowed']===1 && (int)$audit['new_allowed']===0 && $audit['changed_at']!==null,'audit records administrator, previous/new state, revision and UTC timestamp');
    foreach (['simulation','unisms','semaphore'] as $provider) {
        $configure($provider,true);$before=$calls();
        foreach (['account_approved','borrow_ready','borrow_rejected','checkout','reservation_ready','password_reset'] as $event) {
            $id='permission-off-'.$provider.'-'.$event;
            check(!$probe($event,$id)['success'],'OFF suppresses '.$provider.' / '.$event);
            $q=$pdo->prepare('SELECT response,phone,message FROM sms_logs WHERE JSON_UNQUOTE(JSON_EXTRACT(response,"$.event_key"))=:key');
            $q->execute(['key'=>hash('sha256',$event.':'.$id)]);$log=$q->fetch();$meta=json_decode($log['response'],true);
            check($meta['state']==='skipped' && $meta['reason']==='notifications_disabled' && $meta['request_attempted']===false && $log['phone']==='(suppressed)','disabled audit is safe, explicit, and has no API request');
        }
        check($calls()===$before,'OFF makes zero '.$provider.' transport calls');
    }
    // Real business endpoints still complete while permission is OFF.
    $configure('semaphore',true);$before=$calls();
    $uid=user('student','SMS-OFF-WORKFLOW','pending');
    $pdo->prepare('UPDATE users SET phone=:phone WHERE id=:id')->execute(['phone'=>'09170000109','id'=>$uid]);
    post('user-approval-action',['id'=>$uid,'status'=>'accepted'],$staff);
    $borrower=login('SMS-OFF-WORKFLOW');$book=book();
    $request=post('borrow-request-process',['book_id'=>$book],$borrower)['id'];
    post('borrow-request-action',['id'=>$request,'action'=>'approve'],$staff);
    post('borrow-request-action',['id'=>$request,'action'=>'checkout'],$staff);
    post('borrow-request-action',['id'=>$request,'action'=>'return'],$staff);
    $rejected=post('borrow-request-process',['book_id'=>$book],$borrower)['id'];
    post('borrow-request-action',['id'=>$rejected,'action'=>'reject'],$staff);
    $reservedBook=book();$pdo->exec('UPDATE books SET total_copies=0 WHERE id='.$reservedBook);
    $slot=(new DateTimeImmutable('next monday 10:00',new DateTimeZone('Asia/Manila')))->format('Y-m-d\TH:i');
    $reservation=post('reservation-process',['book_id'=>$reservedBook,'pickup_at'=>$slot],$borrower)['id'];
    $pdo->exec('UPDATE books SET total_copies=1 WHERE id='.$reservedBook);
    post('reservation-action',['id'=>$reservation,'action'=>'ready'],$staff);
    post('reservation-action',['id'=>$reservation,'action'=>'fulfill'],$staff);
    post('reservation-action',['id'=>$reservation,'action'=>'return'],$staff);
    check($calls()===$before,'OFF preserves real account, borrowing and reservation transitions with zero transport');
    $q=$pdo->prepare('SELECT response FROM sms_logs WHERE user_id=:id');$q->execute(['id'=>$uid]);$offLogs=$q->fetchAll();
    check(count($offLogs)===6,'OFF real workflows produce all six expected event audits');
    foreach($offLogs as $log){$meta=json_decode($log['response'],true);check($meta['reason']==='notifications_disabled' && $meta['request_attempted']===false,'real OFF hook audit records suppressed submission');}
    $before=$calls();$on=$change(true);
    post('sms-settings',['allowed'=>false,'version'=>$off['permission']['version']],$staff,409);
    $configure('semaphore',true);
    check(!$probe('account_approved','permission-off-semaphore-account_approved')['success'] && $calls()===$before,'re-enable never replays previously suppressed event');
    $configure('simulation');
    check($probe('account_approved','permission-simulation')['success'] && $calls()===$before,'ON with simulation uses zero transport calls');
    foreach (['unisms','semaphore'] as $provider) {
        $configure($provider,true);$before=$calls();
        check($probe('account_approved','permission-live-'.$provider)['success'] && $calls()===$before+1,'ON allows authorized mocked '.$provider.' dispatch');
        check(!$probe('account_approved','permission-live-'.$provider)['success'] && $calls()===$before+1,'duplicate protection retained for '.$provider);
    }
    $configure('semaphore',true);$before=$calls();
    $input=json_encode(['phone'=>'09170000109','event'=>'checkout','event_id'=>'concurrent-sms','message'=>'BCIHS Library: Mock checkout.']);
    $jobs=[];
    for($i=0;$i<2;$i++) {
        $p=proc_open([PHP_BINARY,'-d','disable_functions=curl_exec',$root.'/qa-sms-send.php',$input],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]);$jobs[]=[$p,$pipes];
    }
    foreach($jobs as [$p,$pipes]){stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($p)===0,'concurrent submit process completes');}
    check($calls()===$before+1,'simultaneous duplicate submissions contact mock transport once');
    // Two independent staff sessions compete using the same revision.
    $other=login('phase8-admin');$data=$state();$multi=curl_multi_init();$handles=[];
    foreach([$staff,$other] as $session) {
        $c=curl_init($base.'/backend/sms-settings.php');
        curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$session['cookie'],
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.$session['token']],
            CURLOPT_POSTFIELDS=>json_encode(['allowed'=>false,'version'=>$data['permission']['version']])]);
        curl_multi_add_handle($multi,$c);$handles[]=$c;
    }
    do {curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,1);}while($running);
    $codes=[];foreach($handles as $c){$codes[]=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_multi_remove_handle($multi,$c);curl_close($c);}curl_multi_close($multi);sort($codes);
    check($codes===[200,409],'concurrent setting changes accept one revision and reject stale competitor');
    $change(true);
    // OFF waits for an already admitted transport to finish before reporting success.
    $configure('semaphore',true);$before=$calls();file_put_contents($root.'/mock-fault','delay');
    $input=json_encode(['phone'=>'09170000109','event'=>'checkout','event_id'=>'inflight-sms','message'=>'BCIHS Library: Mock checkout.']);
    $job=proc_open([PHP_BINARY,'-d','disable_functions=curl_exec',$root.'/qa-sms-send.php',$input],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);
    for($i=0;$i<100 && !is_file($root.'/mock-inflight');$i++)usleep(50000);
    check(is_file($root.'/mock-inflight'),'mock transport reached in-flight state');
    $disabled=$change(false);
    $body=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    check(proc_close($job)===0 && $errors==='' && json_decode($body,true)['success']===true,'admitted mock transport completed before OFF');
    unlink($root.'/mock-fault');unlink($root.'/mock-inflight');
    check(!$disabled['permission']['allowed'] && $calls()===$before+1,'OFF succeeds after the single in-flight attempt');
    check(!$probe('checkout','post-disable-sms')['success'] && $calls()===$before+1,'no new transport starts after OFF completed');
    $change(true);
    // Missing and malformed rows cannot send. Restore only this disposable setting row.
    $saved=$pdo->query('SELECT * FROM sms_notification_settings WHERE id=1')->fetch();
    $pdo->exec('DELETE FROM sms_notification_settings WHERE id=1');$before=$calls();
    check(!$probe('checkout','permission-missing')['success'] && $calls()===$before,'missing row fails closed');
    $data=$state();check(!$data['permission']['available'] && $data['delivery_mode']==='Disabled','missing row reported as unavailable');
    post('sms-settings',['allowed'=>true,'version'=>0,'confirm_enable'=>true],$staff,503);
    $pdo->prepare('INSERT INTO sms_notification_settings(id,allow_notifications,version) VALUES(1,:allow,:version)')->execute(['allow'=>$saved['allow_notifications'],'version'=>$saved['version']]);
    $pdo->exec('ALTER TABLE sms_notification_settings MODIFY version VARCHAR(30) NOT NULL');
    $pdo->exec('UPDATE sms_notification_settings SET version="broken" WHERE id=1');
    check(!$probe('checkout','permission-corrupt')['success'] && $calls()===$before,'corrupt row fails closed');
    $pdo->prepare('UPDATE sms_notification_settings SET version=:version WHERE id=1')->execute(['version'=>$saved['version']]);
    $pdo->exec('ALTER TABLE sms_notification_settings MODIFY version INT UNSIGNED NOT NULL DEFAULT 0');
    $pdo->exec('RENAME TABLE sms_notification_settings TO owned_saved_sms_settings');
    check(!$probe('checkout','permission-missing-table')['success'] && $calls()===$before,'missing schema fails closed');
    $pdo->exec('RENAME TABLE owned_saved_sms_settings TO sms_notification_settings');
    // A failed audit insert must roll back the setting itself.
    $saved=$state();$pdo->exec('RENAME TABLE sms_notification_audit TO owned_saved_sms_audit');
    $before=$calls();
    check(!$probe('checkout','permission-missing-audit')['success'] && $calls()===$before,'missing audit dependency fails closed before transport');
    post('sms-settings',['allowed'=>false,'version'=>$saved['permission']['version']],$staff,503);
    $pdo->exec('RENAME TABLE owned_saved_sms_audit TO sms_notification_audit');
    check($state()['permission']===$saved['permission'],'unavailable audit leaves permission and revision unchanged');
    $pdo->exec("CREATE TRIGGER owned_sms_audit_failure BEFORE INSERT ON sms_notification_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
    post('sms-settings',['allowed'=>false,'version'=>$saved['permission']['version']],$staff,500);
    $pdo->exec('DROP TRIGGER owned_sms_audit_failure');
    check($state()['permission']===$saved['permission'],'audit insert failure rolls back the preceding permission UPDATE and revision');
    $change(false);
    $recoveryBefore=$pdo->query('SELECT id,reset_code,reset_code_expires FROM users ORDER BY id')->fetchAll();
    post('forgot-password-request',['phone'=>'09170000000','role'=>'student'],session(),503);
    check($pdo->query('SELECT id,reset_code,reset_code_expires FROM users ORDER BY id')->fetchAll()===$recoveryBefore,'OFF recovery never issues or stores a code');
    $change(true);$configure('simulation');
    post('forgot-password-request',['phone'=>'09170000000','role'=>'student'],session(),503);
    check($pdo->query('SELECT id,reset_code,reset_code_expires FROM users ORDER BY id')->fetchAll()===$recoveryBefore,'simulation recovery never issues or stores a code');
    [$code,$logs]=http('sms-logs-data','GET',null,$staff);check($code===200 && $logs['notification_status']['delivery_mode']==='Simulation','SMS logs exposes honest effective permission/mode');
    check(!str_contains(json_encode($state()),'SYNTHETIC_ONLY') && !str_contains(json_encode($state()),'09170000109'),'settings response never exposes private credentials or numbers');
    echo "SMS administrator permission integration checks completed with isolated file-only mock transports.\n";
})();
