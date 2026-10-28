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
post('attendance-settings',['daily_visit_limit'=>null,'occupancy_limit'=>null,'track_departures'=>true],$staff);
[$code,$attendanceForm]=http('attendance-data','GET',null,$staff);
$walk=post('attendance-add',['mode'=>'manual','student_name'=>'Client external fixture','year_level'=>'External visitor','visitor_type'=>'External visitor','section'=>'','organization'=>'','submission_key'=>$attendanceForm['formToken']],$staff);
$visit=$pdo->query('SELECT * FROM attendance WHERE id='.(int)$walk['id'])->fetch();check($visit['user_id']===null && $visit['student_identifier']===null && $visit['section']==='','walk-in needs no ID, photo, organization or purpose');
$occupancy=static fn()=>(int)$pdo->query('SELECT COUNT(*) FROM attendance WHERE tracks_departure=1 AND time_out IS NULL')->fetchColumn();$before=$occupancy();post('attendance-time-out',['id'=>$walk['id'],'confirm_departure'=>true],$staff);check($occupancy()===$before-1,'confirmed Time Out decrements occupancy');post('attendance-time-out',['id'=>$walk['id'],'confirm_departure'=>true],$staff,409);
