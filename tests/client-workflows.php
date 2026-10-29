<?php
// Included only inside the validated disposable Phase 8 environment.
if(getenv('BCIHS_PHASE8_ISOLATED')!=='1')throw new RuntimeException('Isolated environment required.');
foreach(['PH8-STUDENT','PH8-TEACHER','phase8@example.invalid'] as $identifier){
    foreach(['admin-login-process','login-process'] as $loginEndpoint){
    $s=session();[$code]=http($loginEndpoint,'POST',['identifier'=>$identifier,'password'=>$password,'pickup'=>$token],$s);
    check($code===302,'staff-only login redirects borrower denial');[$code,$state]=http('session-status','GET',null,$s);check(!$state['loggedIn'],'borrower cannot authenticate through staff endpoint');
    [$code,$details]=http('pickup-verification-data?token='.$token,'GET',null,$s);check($code===401 && !isset($details['request']),'no borrower data disclosed before staff auth');
    }
}
foreach(['PH8-LIB','phase8-admin'] as $identifier){
    $s=session();[$code,,,$responseHeaders]=http('admin-login-process','POST',['identifier'=>$identifier,'password'=>$password,'pickup'=>$token,'return'=>'https://example.invalid'], $s);
    check(in_array('Location: ../pickup-verification.html?token='.$token."\r\n",$responseHeaders,true),'staff returns only to validated internal QR URL');
    check($code===302,'authorized staff login redirects');[$code,$state]=http('admin-session-status','GET',null,$s);check($state['loggedIn'],'staff backend authenticates admin/librarian');
    [$code,$details]=http('pickup-verification-data?token='.$token,'GET',null,$s);check($code===200 && !$details['eligible'],'completed QR stays ineligible after staff login');
    [$code]=http('pickup-verification-data?token=bad','GET',null,$s);check($code===422,'staff invalid QR rejected');
}
foreach([$studentSession,$teacherSession,$visitorSession] as $borrower){[$code,$details]=http('pickup-verification-data?token='.$token,'GET',null,$borrower);check($code===403 && !isset($details['request']),'every borrower role denied verification details');}
foreach([$studentSession,$teacherSession,$visitorSession] as $borrower) post('borrow-request-action',['id'=>$request,'action'=>'checkout','pickup_token'=>$token],$borrower,403);
foreach([[$student,'student-login@example.invalid'],[$teacher,'teacher-login@example.invalid']] as [$id,$email]){
    $pdo->prepare('UPDATE users SET email=:email WHERE id=:id')->execute(['email'=>$email,'id'=>$id]);
    $emailSession=login($email);[$status,$identity]=http('session-status','GET',null,$emailSession);
    check($status===200 && $identity['loggedIn'],'registered email authenticates its exact borrower account');
}
post('user-approval-action',['id'=>$student,'status'=>'rejected'],$staff,409);
check($pdo->query('SELECT account_status FROM users WHERE id='.$student)->fetchColumn()==='accepted','approved-account rejection is forbidden without changing status');
foreach([null,$studentSession,$teacherSession] as $borrower){[$code]=http('statistical-report?month=2026-09&months=3&school_year=2026-2027','GET',null,$borrower);check(in_array($code,[401,403],true),'official report staff-only');}
[$code,$stat]=http('statistical-report?month=2026-09&months=3&school_year=2026-2027','GET',null,$staff);check($code===200 && count($stat['rows'])===13,'official empty-period categories');check($stat['total']===0,'no fabricated historical circulation');
$currentMonth=date('Y-m');$from=$currentMonth.'-01 00:00:00';$until=(new DateTimeImmutable($from))->modify('+1 month')->format('Y-m-d H:i:s');
$counts=$pdo->prepare('SELECT COUNT(*) FROM borrow_requests WHERE status IN ("approved","checked_out","returned") AND COALESCE(checked_out_at,CASE WHEN status IN ("approved","returned") THEN approved_at END)>=:start AND COALESCE(checked_out_at,CASE WHEN status IN ("approved","returned") THEN approved_at END)<:end');$counts->execute(['start'=>$from,'end'=>$until]);$expected=(int)$counts->fetchColumn();
$counts=$pdo->prepare('SELECT COUNT(*) FROM reservations WHERE status IN ("fulfilled","returned") AND fulfilled_at>=:start AND fulfilled_at<:end');$counts->execute(['start'=>$from,'end'=>$until]);$expected+=(int)$counts->fetchColumn();
[$code,$populated]=http('statistical-report?month='.$currentMonth.'&months=3&school_year=2026-2027','GET',null,$staff);check($code===200 && $expected>0 && $populated['total']===$expected,'official populated total matches independent borrowing/reservation SQL counts');
[$code]=http('statistical-report','POST',['prepared_name'=>'Injected'], $staff,false);check($code===403,'signatory changes require CSRF');
post('statistical-report',['prepared_name'=>"Invalid\nname"],$staff,422);
[$code]=http('statistical-report?month=2026-09&months=3&school_year=2026-2027','GET',null,$staff);check($code===200,'invalid signatory config cannot corrupt or create empty settings');
$settings=['prepared_name'=>'=QA name','prepared_title'=>'QA librarian','noted_name'=>'Noted QA','noted_title'=>'Reviewer','approved_name'=>'','approved_title'=>''];
post('statistical-report',$settings,$staff);[$code,$stat]=http('statistical-report?month=2026-09&months=3&school_year=2026-2027','GET',null,$libSession);check($stat['settings']['prepared_name']==='=QA name','staff-controlled signatories persist in private isolated config');
[$code,,$xlsx]=http('statistical-report?month=2026-09&months=3&school_year=2026-2027&format=xlsx','GET',null,$staff);check($code===200 && str_starts_with($xlsx,'PK'),'authenticated XLSX download');
$file=tempnam(sys_get_temp_dir(),'client-xlsx-');try{file_put_contents($file,$xlsx);$zip=new ZipArchive();check($zip->open($file)===true && $zip->locateName('xl/charts/chart1.xml')!==false,'download includes native chart');$zip->close();}finally{unlink($file);}
// Real write checks remain confined to this owned database.
post('attendance-settings',['daily_visit_limit'=>null],$staff);
[$code,$attendanceForm]=http('attendance-data','GET',null,$staff);
$walk=post('attendance-add',['mode'=>'manual','student_name'=>'Client external fixture','year_level'=>'External visitor','visitor_type'=>'External visitor','section'=>'','organization'=>'','submission_key'=>$attendanceForm['formToken']],$staff);
$visit=$pdo->query('SELECT * FROM attendance WHERE id='.(int)$walk['id'])->fetch();check($visit['user_id']===null && $visit['student_identifier']===null && $visit['section']==='','walk-in needs no ID, photo, organization or purpose');
post('attendance-time-out',['id'=>$walk['id'],'confirm_departure'=>true],$staff,410);
check($pdo->query('SELECT time_out FROM attendance WHERE id='.(int)$walk['id'])->fetchColumn()===null,'retired departure action leaves the new Time In intact');
// Legacy flags and timestamps stay in storage but never reactivate departure behavior.
$pdo->exec('UPDATE attendance_settings SET track_departures=1,occupancy_limit=1 WHERE id=1');
$pdo->exec('UPDATE attendance SET tracks_departure=1,time_out=DATE_ADD(time_in,INTERVAL 30 MINUTE) WHERE id='.(int)$walk['id']);
$historical=$pdo->query('SELECT * FROM attendance WHERE id='.(int)$walk['id'])->fetch();
post('attendance-time-out',['id'=>$walk['id'],'confirm_departure'=>true],$staff,410);
check($pdo->query('SELECT * FROM attendance WHERE id='.(int)$walk['id'])->fetch()===$historical,'historical departure columns and timestamps remain byte-identical');
post('attendance-settings',['daily_visit_limit'=>null,'track_departures'=>true],$staff,422);
post('attendance-settings',['daily_visit_limit'=>null,'occupancy_limit'=>1],$staff,422);
[$code,$form]=http('attendance-data','GET',null,$libSession);
check($code===200 && !isset($form['settings']['track_departures']) && !isset($form['inside']),'active attendance exposes only Time In and daily policy');
$newWalk=post('attendance-add',['mode'=>'manual','student_name'=>'Time In only visitor','year_level'=>'External visitor','section'=>'','submission_key'=>$form['formToken']],$libSession);
$newRow=$pdo->query('SELECT * FROM attendance WHERE id='.(int)$newWalk['id'])->fetch();
check((int)$newRow['tracks_departure']===0 && $newRow['time_out']===null,'legacy tracking and occupancy settings cannot affect new Time In visits');
[$code,$form]=http('attendance-data','GET',null,$staff);
post('attendance-add',['mode'=>'registered','user_id'=>$student,'submission_key'=>$form['formToken']],$staff);
[$code,$repeatForm]=http('attendance-data','GET',null,$staff);
[$code,$repeat]=http('attendance-add','POST',['mode'=>'registered','user_id'=>$student,'submission_key'=>$repeatForm['formToken']],$staff);
check($code===409 && ($repeat['code']??'')==='repeat_visit_confirmation_required','fresh same-day submissions require explicit return-visit confirmation');
$repeatVisit=post('attendance-add',['mode'=>'registered','user_id'=>$student,'confirm_repeat'=>true,'submission_key'=>$repeatForm['formToken']],$staff);
$replay=post('attendance-add',['mode'=>'registered','user_id'=>$student,'confirm_repeat'=>true,'submission_key'=>$repeatForm['formToken']],$staff);
check($repeatVisit['id']===$replay['id'] && $replay['replayed'],'repeat-submit idempotency preserves one visit');
foreach([null,$studentSession,$teacherSession,$visitorSession] as $unauthorized){
 [$code]=http('attendance-data','GET',null,$unauthorized);check(in_array($code,[401,403],true),'attendance read requires staff');
 [$code]=http('attendance-add','POST',['mode'=>'manual'],$unauthorized);check(in_array($code,[401,403],true),'attendance write requires staff');
}
[$code]=http('attendance-settings','POST',['daily_visit_limit'=>null],$staff,false);check($code===403,'daily policy still requires CSRF');

[$code,$attendanceReport]=http('reports-data?report=attendance&period=month','GET',null,$staff);
check($code===200 && !array_key_exists('Current occupancy (all dates)',$attendanceReport['summary']),'attendance report is arrival-based without occupancy');
foreach($attendanceReport['records'] as $row)check(!array_key_exists('time_out',$row) && !array_key_exists('tracks_departure',$row) && !array_key_exists('status',$row),'active report omits departure fields without altering history');
[$code]=http('reports-data?report=attendance&status=inside','GET',null,$staff);check($code===422,'retired report status cannot be selected');
[$code,$dashboard]=http('dashboard-data','GET',null,$staff);check($code===200 && !array_key_exists('occupancy',$dashboard['current']),'dashboard has no inferred occupancy');
$todayCount=(int)$pdo->query('SELECT COUNT(*) FROM attendance WHERE DATE(time_in)=CURDATE()')->fetchColumn();
post('attendance-settings',['daily_visit_limit'=>$todayCount+1],$staff);
$jobs=[];$multi=curl_multi_init();
foreach([[$staff,'Daily final slot A'],[$libSession,'Daily final slot B']] as [$session,$name]){
 [, $form]=http('attendance-data','GET',null,$session);
 $c=curl_init($base.'/backend/attendance-add.php');
 curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$session['cookie'],CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.$session['token']],CURLOPT_POSTFIELDS=>json_encode(['mode'=>'manual','student_name'=>$name,'year_level'=>'External visitor','section'=>'','submission_key'=>$form['formToken']])]);
 curl_multi_add_handle($multi,$c);$jobs[]=$c;
}
do{curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,1);}while($running);
$codes=[];foreach($jobs as $c){$codes[]=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_multi_remove_handle($multi,$c);curl_close($c);}curl_multi_close($multi);sort($codes);
check($codes===[200,409],'simultaneous arrivals cannot exceed the final daily slot');
check((int)$pdo->query('SELECT COUNT(*) FROM attendance WHERE DATE(time_in)=CURDATE()')->fetchColumn()===$todayCount+1,'daily concurrency inserts only one visit');
post('attendance-settings',['daily_visit_limit'=>null],$staff);
// Saved announcements are published rows; order by publication time and then ID.
$notices=[];
foreach(['First public notice','Second public notice','Latest public notice'] as $title){
 [, $announcementForm]=http('announcement-management-data','GET',null,$staff);
 $notice=post('announcement-save',['title'=>$title,'body'=>str_repeat('Long notice content. ',100),'category'=>'Notice','submission_key'=>$announcementForm['submissionToken']],$staff);
 $notices[]=$notice['id'];
 $pdo->exec('UPDATE announcements SET created_at="2026-10-28 12:00:00" WHERE id='.(int)$notice['id']);
}
[$code,$latest]=http('announcements-data?latest=1','GET');check($code===200 && count($latest['announcements'])===1 && (int)$latest['announcements'][0]['id']===(int)end($notices),'homepage gets only the newest published notice with deterministic tie order');
[$code,$twoLatest]=http('announcements-data?latest=2','GET');check($code===200 && array_map('intval',array_column($twoLatest['announcements'],'id'))===array_reverse(array_slice($notices,-2)),'homepage gets the two newest published notices in deterministic order');
[$code]=http('announcements-data?latest=3','GET');check($code===422,'unsupported announcement limits are rejected');
$pdo->exec('UPDATE announcements SET created_at="2026-10-29 12:00:00" WHERE id='.(int)$notices[0]);
[$code,$dateLatest]=http('announcements-data?latest=1','GET');check((int)$dateLatest['announcements'][0]['id']===(int)$notices[0],'publication date takes precedence over record ID');
$pdo->exec('UPDATE announcements SET created_at="2026-10-28 12:00:00" WHERE id='.(int)$notices[0]);
[$code,$collection]=http('announcements-data','GET');check($code===200 && count($collection['announcements'])===3,'View All retains the complete published collection');
post('announcement-delete',['id'=>array_pop($notices)],$staff);
[$code,$latest]=http('announcements-data?latest=1','GET');check((int)$latest['announcements'][0]['id']===(int)end($notices),'removed latest notice falls back to the next visible notice');
foreach($notices as $id)post('announcement-delete',['id'=>$id],$staff);
[$code,$empty]=http('announcements-data?latest=1','GET');check($code===200 && $empty['announcements']===[],'homepage latest endpoint returns a clean empty collection');
