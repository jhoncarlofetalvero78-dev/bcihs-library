<?php
if (PHP_SAPI !== 'cli' || getenv('BCIHS_PHASE8_ISOLATED') !== '1'
    || !preg_match('/^bcihs_phase8_test_[a-f0-9]{12}$/D', getenv('BCIHS_DB_NAME') ?: '')
    || ($argv[1]??'') !== 'http://127.0.0.1:8898') exit("Refusing: validated disposable environment required.\n");
require __DIR__.'/../includes/db_connect.php';
$base=$argv[1]; $checks=0; $cookies=[]; $password=getenv('BCIHS_PHASE8_PASSWORD') ?: 'Phase8-Only!'.bin2hex(random_bytes(6));
function check(bool $ok,string $label):void { global $checks; if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo 'PASS: '.$label."\n"; }
function http(string $endpoint,string $method='GET',?array $data=null,?array $session=null,bool $csrf=true):array {
    global $base;
    $parts=explode('?', $endpoint, 2);
    $c=curl_init($base.'/backend/'.$parts[0].'.php'.(isset($parts[1])?'?'.$parts[1]:''));
    $headers=[];$responseHeaders=[];
    $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_CUSTOMREQUEST=>$method];
    if($session){$options[CURLOPT_COOKIEFILE]=$session['cookie'];$options[CURLOPT_COOKIEJAR]=$session['cookie'];}
    if($data!==null){
        if(in_array($endpoint,['login-process','admin-login-process','register-process'],true)) $options[CURLOPT_POSTFIELDS]=http_build_query($data);
        else {$options[CURLOPT_POSTFIELDS]=json_encode($data);$headers[]='Content-Type: application/json';}
    }
    if($csrf && isset($session['token']))$headers[]='X-CSRF-Token: '.$session['token'];
    $options[CURLOPT_HEADERFUNCTION]=static function($curl,$line)use(&$responseHeaders){$responseHeaders[]=$line;return strlen($line);};
    $options[CURLOPT_HTTPHEADER]=$headers;curl_setopt_array($c,$options);
    $body=curl_exec($c);if($body===false)throw new RuntimeException('Local HTTP failed.');
    $status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
    return [$status,json_decode($body,true),$body,$responseHeaders];
}
function session():array {global $cookies;$file=tempnam(sys_get_temp_dir(),'phase8-cookie-');chmod($file,0600);$cookies[]=$file;return ['cookie'=>$file];}
function login(string $id):array {global $password;$s=session();[$code]=http('login-process','POST',['identifier'=>$id,'password'=>$password],$s);check($code===302,'sign-in redirects');[$code,$data]=http('books-data','GET',null,$s);check($code===200,'authenticated catalog');$s['token']=$data['csrfToken'];return $s;}
function user(string $role,string $id,string $status='accepted'):int {global $pdo,$password;$pdo->prepare('INSERT INTO users(full_name,student_id,id_number,email,year_section,department,phone,password_hash,role,account_status) VALUES(:name,:student,:employee,:email,"Grade 10 — A","Faculty",:phone,:hash,:role,:status)')->execute(['name'=>'Phase8 '.$role,'student'=>$role==='student'?$id:null,'employee'=>in_array($role,['teacher','librarian'],true)?$id:null,'email'=>$role==='visitor'?$id:null,'phone'=>$role==='student'?'09170000000':null,'hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>$role,'status'=>$status]);return (int)$pdo->lastInsertId();}
function post(string $endpoint,array $data,array $s,int $expected=200):array {[$code,$body]=http($endpoint,'POST',$data,$s);check($code===$expected,$endpoint.' returns '.$expected);return $body??[];}
function book():int {global $pdo;$pdo->exec('INSERT INTO books(title,author,total_copies,ddc,call_number) VALUES("Phase8 book","Test author",2,"500","500 T1")');return (int)$pdo->lastInsertId();}
try {
    check($pdo->query('SELECT DATABASE()')->fetchColumn()===getenv('BCIHS_DB_NAME'),'CLI database isolation');
    check((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===0,'no real users imported');
    $pdo->exec('INSERT INTO attendance_settings(id,daily_visit_limit,occupancy_limit,track_departures) VALUES(1,NULL,NULL,0)');
    $pdo->exec('INSERT INTO reservation_settings(id,daily_limit,slot_limit) VALUES(1,10,NULL)');
    $pdo->prepare('INSERT INTO admins(username,password_hash) VALUES("phase8-admin",:hash)')->execute(['hash'=>password_hash($password,PASSWORD_DEFAULT)]);
    $student=user('student','PH8-STUDENT');$librarian=user('librarian','PH8-LIB');$visitor=user('visitor','phase8@example.invalid');
    $staff=login('phase8-admin');
    [$status,$permission]=http('sms-settings','GET',null,$staff);
    check($status===200 && !$permission['permission']['allowed'] && $permission['delivery_mode']==='Disabled','new SMS permission defaults OFF');
    post('sms-settings',['allowed'=>true,'version'=>$permission['permission']['version'],'confirm_enable'=>true],$staff);
    $teacherRegistration=session();[$status,$teacherForm]=http('registration-token','GET',null,$teacherRegistration);
    check($status===200,'teacher registration acquires CSRF token');
    [$status]=http('register-process','POST',['role'=>'teacher','full_name'=>'Phase8 teacher','id_number'=>'PH8-TEACHER','department'=>'Faculty','phone'=>'09170000004','password'=>$password,'confirm_password'=>$password,'csrf_token'=>$teacherForm['csrfToken']],$teacherRegistration);
    check($status===302,'teacher registration submits through actual endpoint');
    $teacherRow=$pdo->query('SELECT id,role,account_status,student_id,id_number FROM users WHERE id_number="PH8-TEACHER"')->fetch(PDO::FETCH_ASSOC);
    check($teacherRow && $teacherRow['role']==='teacher' && $teacherRow['account_status']==='pending' && $teacherRow['student_id']===null,'teacher registers pending with faculty ID, never as student or staff');
    $teacher=(int)$teacherRow['id'];
    [$status]=http('login-process','POST',['identifier'=>'PH8-TEACHER','password'=>$password],$teacherRegistration);
    [$status,$pendingSession]=http('session-status','GET',null,$teacherRegistration);check(!$pendingSession['loggedIn'],'pending teacher cannot authenticate');
    post('user-approval-action',['id'=>$teacher,'status'=>'accepted'],$staff);
    $studentSession=login('PH8-STUDENT');$teacherSession=login('PH8-TEACHER');$libSession=login('PH8-LIB');$visitorSession=login('phase8@example.invalid');
    [$status]=http('all-users-data','GET',null,$teacherSession);check($status===403,'teacher is not staff');
    [$status]=http('all-users-data','GET',null,$libSession);check($status===200,'approved librarian staff access');
    [$status,$data]=http('books-data','GET',null,$visitorSession);check($data['canBorrow']===false,'visitor catalog is read only');
    $created=post('book-save',['title'=>'Phase8 CRUD "title"','author'=>'QA author','ddc'=>'500','call_number'=>'500 Q1','total_copies'=>2,'isbn'=>'9780132350884'],$staff)['id'];
    post('book-save',['id'=>$created,'title'=>'Phase8 edited title','total_copies'=>3],$staff);
    post('book-save',['title'=>'Duplicate ISBN','author'=>'Author','isbn'=>'9780132350884','total_copies'=>1],$staff,409);
    post('book-save',['title'=>'Invalid DDC','author'=>'Author','ddc'=>'500 Q1','total_copies'=>1],$staff,422);
    post('book-save',['title'=>'Unauthorized book','author'=>'Author','total_copies'=>1],$teacherSession,403);
    post('book-delete',['id'=>$created],$staff);
    $upload=tempnam(sys_get_temp_dir(),'phase8-image-');
    $multipart=function(string $endpoint,array $fields,string $path,string $name) use($base,$staff):array {
        $c=curl_init($base.'/backend/'.$endpoint.'.php');
        $fields['photo']=new CURLFile($path,'application/octet-stream',$name);
        curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$staff['cookie'],CURLOPT_HTTPHEADER=>['X-CSRF-Token: '.$staff['token']],CURLOPT_POSTFIELDS=>$fields]);
        $body=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return[$status,json_decode($body,true)];
    };
    try{
        file_put_contents($upload,'<?php echo "not an image";');
        [$status]=$multipart('book-save',['title'=>'Rejected upload','author'=>'Author','total_copies'=>1],$upload,'payload.php');check($status===422,'executable upload rejected by content validation');
        file_put_contents($upload,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jk1sAAAAASUVORK5CYII='));
        [$status,$uploaded]=$multipart('book-save',['title'=>'Safe image book','author'=>'Author','total_copies'=>1],$upload,'untrusted-name.php');check($status===200,'actual PNG upload accepted');
        $imagePath=$pdo->query('SELECT image_path FROM books WHERE id='.(int)$uploaded['id'])->fetchColumn();check((bool)preg_match('#^uploads/books/[a-f0-9]{32}\.png$#D',$imagePath),'server chooses non-executable random image path');
        post('book-delete',['id'=>$uploaded['id']],$staff);
        [$status,$imageForm]=http('announcement-management-data','GET',null,$staff);
        [$status,$imageNotice]=$multipart('announcement-save',['title'=>'Phase8 image notice','body'=>'Image validation fixture','category'=>'Notice','submission_key'=>$imageForm['submissionToken']],$upload,'unsafe-original.php');
        check($status===200,'announcement PNG upload accepted through actual multipart endpoint');
        $noticePath=$pdo->query('SELECT image_path FROM announcements WHERE id='.(int)$imageNotice['id'])->fetchColumn();
        check((bool)preg_match('#^uploads/announcements/[a-f0-9]{32}\.png$#D',$noticePath),'announcement upload uses safe random image path');
        [$status,$editImageForm]=http('announcement-management-data','GET',null,$staff);
        post('announcement-save',['id'=>$imageNotice['id'],'title'=>'Phase8 edited notice','body'=>'Updated published fixture','category'=>'Notice','submission_key'=>$editImageForm['submissionToken']],$staff);
        check($pdo->query('SELECT image_path FROM announcements WHERE id='.(int)$imageNotice['id'])->fetchColumn()===$noticePath,'announcement edit preserves existing uploaded image');
        post('announcement-delete',['id'=>$imageNotice['id']],$staff);
    }finally{unlink($upload);}
    $book=book();
    post('borrow-request-process',['book_id'=>$book],$visitorSession,403);
    [$status]=http('borrow-request-process','POST',['book_id'=>$book],$studentSession,false);check($status===403,'borrow CSRF enforced');
    $request=post('borrow-request-process',['book_id'=>$book],$studentSession)['id'];
    post('borrow-request-process',['book_id'=>$book],$studentSession,409);
    post('borrow-request-action',['id'=>$request,'action'=>'approve'],$staff);
    check((int)$pdo->query('SELECT borrowed_copies FROM books WHERE id='.$book)->fetchColumn()===0,'approval does not check out');
    check($pdo->query('SELECT due_date FROM borrow_requests WHERE id='.$request)->fetchColumn()===null,'approval has no due date');
    $qr=post('pickup-qr-data',['id'=>$request],$studentSession);
    parse_str(parse_url($qr['url'],PHP_URL_QUERY),$query);$token=$query['token'];
    check((bool)preg_match('/^[a-f0-9]{64}$/D',$token),'secure request-specific QR');
    [$status,$verification]=http('pickup-verification-data?token='.$token,'GET',null,$staff);
    check($status===200 && $verification['eligible'],'staff QR verification');
    post('borrow-request-action',['id'=>$request,'action'=>'checkout','pickup_token'=>$token],$staff);
    post('borrow-request-action',['id'=>$request,'action'=>'checkout','pickup_token'=>$token],$staff,409);
    check((int)$pdo->query('SELECT borrowed_copies FROM books WHERE id='.$book)->fetchColumn()===1,'checkout increments once');
    check((bool)$pdo->query('SELECT due_date FROM borrow_requests WHERE id='.$request)->fetchColumn(),'checkout assigns due date');
    post('borrow-request-action',['id'=>$request,'action'=>'return'],$staff);
    post('borrow-request-action',['id'=>$request,'action'=>'return'],$staff,409);
    check((int)$pdo->query('SELECT borrowed_copies FROM books WHERE id='.$book)->fetchColumn()===0,'return restores inventory once');
    $teacherRequest=post('borrow-request-process',['book_id'=>$book],$teacherSession)['id'];
    post('borrow-request-action',['id'=>$teacherRequest,'action'=>'approve'],$libSession);
    post('borrow-request-action',['id'=>$teacherRequest,'action'=>'checkout'],$libSession);
    post('borrow-request-action',['id'=>$teacherRequest,'action'=>'return'],$libSession);
    [$status,$teacherHistory]=http('borrowing-history-data','GET',null,$teacherSession);
    check($status===200 && count(array_filter($teacherHistory['requests']??[],fn($r)=>(int)$r['id']===(int)$teacherRequest && $r['status']==='returned'))===1,'teacher sees returned loan in own history');
    [$status,$teacherReport]=http('reports-data?report=users&period=month','GET',null,$staff);
    check($status===200 && count(array_filter($teacherReport['records'],fn($r)=>$r['role']==='teacher' && $r['status']==='accepted' && (int)$r['accounts']===1))===1,'reports classify the registered approved teacher separately from students');
    [$status,$loanReport]=http('reports-data?report=loans&period=month','GET',null,$staff);
    check($status===200 && count(array_filter($loanReport['records'],fn($r)=>$r['borrower_name']==='Phase8 teacher' && $r['status']==='returned'))>=1,'returned teacher loan appears in circulation report');
    post('user-delete',['id'=>$teacher],$staff,409);
    post('book-delete',['id'=>$book],$staff,409);
    // Independent staff sessions issue parallel operations against one available copy.
    $raceBook=book();$pdo->exec('UPDATE books SET total_copies=1 WHERE id='.$raceBook);
    $raceStudent=post('borrow-request-process',['book_id'=>$raceBook],$studentSession)['id'];
    $raceTeacher=post('borrow-request-process',['book_id'=>$raceBook],$teacherSession)['id'];
    $parallel=function(array $jobs) use($base):array {
        $multi=curl_multi_init();$handles=[];
        foreach($jobs as [$id,$action,$session]){
            $c=curl_init($base.'/backend/borrow-request-action.php');curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,
                CURLOPT_COOKIEFILE=>$session['cookie'],CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.$session['token']],CURLOPT_POSTFIELDS=>json_encode(['id'=>$id,'action'=>$action])]);
            curl_multi_add_handle($multi,$c);$handles[]=$c;
        }
        do{curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,0.05);}while($running);
        $codes=[];foreach($handles as $c){$codes[]=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_multi_remove_handle($multi,$c);curl_close($c);}curl_multi_close($multi);sort($codes);return $codes;
    };
    check($parallel([[$raceStudent,'approve',$staff],[$raceTeacher,'approve',$libSession]])===[200,409],'parallel approvals allocate only one copy');
    $winner=(int)$pdo->query('SELECT id FROM borrow_requests WHERE book_id='.$raceBook.' AND status="awaiting_pickup"')->fetchColumn();
    check((int)$pdo->query('SELECT COUNT(*) FROM borrow_requests WHERE book_id='.$raceBook.' AND status="awaiting_pickup"')->fetchColumn()===1,'one pickup hold after parallel approval');
    check($parallel([[$winner,'checkout',$staff],[$winner,'checkout',$libSession]])===[200,409],'parallel checkout creates one loan');
    check((int)$pdo->query('SELECT borrowed_copies FROM books WHERE id='.$raceBook)->fetchColumn()===1,'parallel checkout preserves stock');
    post('borrow-request-action',['id'=>$winner,'action'=>'return'],$staff);
    // Reservation waitlist, scheduling, physical handover, and cancellation.
    $reservedBook=book();$pdo->exec('UPDATE books SET total_copies=1 WHERE id='.$reservedBook);
    $teacherLoan=post('borrow-request-process',['book_id'=>$reservedBook],$teacherSession)['id'];
    post('borrow-request-action',['id'=>$teacherLoan,'action'=>'approve'],$staff);
    post('borrow-request-action',['id'=>$teacherLoan,'action'=>'checkout'],$staff);
    $pickup=(new DateTimeImmutable('next monday 10:00',new DateTimeZone('Asia/Manila')))->format('Y-m-d\TH:i');
    post('reservation-process',['book_id'=>$reservedBook,'pickup_at'=>'2020-01-01T10:00'],$studentSession,422);
    $reservation=post('reservation-process',['book_id'=>$reservedBook,'pickup_at'=>$pickup],$studentSession)['id'];
    post('reservation-process',['book_id'=>$reservedBook,'pickup_at'=>$pickup],$studentSession,409);
    post('reservation-action',['id'=>$reservation,'action'=>'ready'],$staff,409);
    post('reservation-action',['id'=>$reservation,'action'=>'schedule','pickup_at'=>$pickup],$studentSession);
    post('borrow-request-action',['id'=>$teacherLoan,'action'=>'return'],$staff);
    post('reservation-action',['id'=>$reservation,'action'=>'ready'],$staff);
    check((int)$pdo->query('SELECT borrowed_copies FROM books WHERE id='.$reservedBook)->fetchColumn()===0,'reservation ready holds without borrowing');
    post('reservation-action',['id'=>$reservation,'action'=>'schedule','pickup_at'=>$pickup],$studentSession,409);
    post('reservation-action',['id'=>$reservation,'action'=>'fulfill'],$staff);
    post('reservation-action',['id'=>$reservation,'action'=>'fulfill'],$staff,409);
    post('reservation-action',['id'=>$reservation,'action'=>'return'],$staff);
    post('reservation-action',['id'=>$reservation,'action'=>'return'],$staff,409);
    $pdo->exec('UPDATE books SET total_copies=0 WHERE id='.$reservedBook);
    $cancelled=post('reservation-process',['book_id'=>$reservedBook,'pickup_at'=>$pickup],$teacherSession)['id'];
    post('reservation-action',['id'=>$cancelled,'action'=>'cancel'],$teacherSession);
    // Existing identity snapshots support teachers and external walk-ins without borrower promotion.
    $pdo->prepare('UPDATE users SET department=:department WHERE id=:id')->execute(['department'=>str_repeat('Faculty ',15),'id'=>$teacher]);
    [$status,$attendance]=http('attendance-data','GET',null,$staff);check($status===200,'attendance loads');
    $visit=post('attendance-add',['mode'=>'registered','user_id'=>$teacher,'submission_key'=>$attendance['formToken']],$staff);
    post('attendance-add',['mode'=>'registered','user_id'=>$teacher,'submission_key'=>$attendance['formToken']],$staff);
    check((int)$pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn()===1,'attendance form replay inserts once');
    check(str_starts_with($pdo->query('SELECT year_section_snapshot FROM attendance')->fetchColumn(),'Teacher/Faculty'),'teacher attendance not labeled a student');
    [$status,$lookup]=http('attendance-students?search=PH8-TEACHER','GET',null,$staff);check($status===200 && $lookup['students'][0]['role']==='teacher','teacher lookup supports employee ID');
    post('attendance-add',['mode'=>'registered','user_id'=>$teacher,'submission_key'=>$visit['formToken']],$staff,409);
    post('attendance-add',['mode'=>'registered','user_id'=>$teacher,'submission_key'=>$visit['formToken'],'confirm_repeat'=>true],$staff);
    check((int)$pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn()===2,'explicit repeated visit creates only the requested second visit');
    post('attendance-settings',['daily_visit_limit'=>2],$staff);
    [$status,$quotaForm]=http('attendance-data','GET',null,$staff);
    post('attendance-add',['mode'=>'manual','student_name'=>'Quota fixture','year_level'=>'Visitor','section'=>'N/A','submission_key'=>$quotaForm['formToken']],$staff,409);
    post('attendance-settings',['daily_visit_limit'=>null],$staff);
    [$status,$attendance]=http('attendance-data','GET',null,$staff);
    $walk=post('attendance-add',['mode'=>'manual','student_name'=>'Phase8 external visitor','year_level'=>'Visitor','section'=>'N/A','submission_key'=>$attendance['formToken']],$staff);
    post('attendance-time-out',['id'=>$walk['id'],'confirm_departure'=>true],$staff,410);
    check($pdo->query('SELECT time_out FROM attendance WHERE id='.(int)$walk['id'])->fetchColumn()===null,'retired departure endpoint cannot write a timestamp');
    // All generic reports work; snapshots ignore irrelevant invalid dates.
    foreach(['inventory','loans','returns','requests','overdue','reservations','attendance','users'] as $report){
        [$status,$reportData]=http('reports-data?report='.$report.'&period=month','GET',null,$staff);
        check($status===200 && is_array($reportData['records']),$report.' report source');
    }
    [$status]=http('reports-data?report=inventory&period=custom&from=2026-12-01&to=2026-01-01','GET',null,$staff);check($status===200,'snapshot ignores reversed irrelevant dates');
    [$status]=http('reports-data?report=users&period=custom&from=2026-12-01&to=2026-01-01','GET',null,$staff);check($status===422,'date report rejects reversed dates');
    [$status,$announcementForm]=http('announcement-management-data','GET',null,$staff);
    $notice=post('announcement-save',['title'=>'Phase8 "Readers" & café','body'=>'Literal <script>notice</script>','category'=>'Notice','submission_key'=>$announcementForm['submissionToken']],$staff);
    [$status,$notices]=http('announcements-data');check($status===200 && count($notices['announcements'])===1,'published announcement visible');
    post('announcement-delete',['id'=>$notice['id']],$staff);
    require __DIR__.'/client-workflows.php';
    if (getenv('BCIHS_SMS_ACCEPTANCE_ROOT')) {
        require __DIR__.'/sms-permission-workflows.php';
        require __DIR__.'/sms-acceptance-workflows.php';
        require __DIR__.'/flexible-due-workflows.php';
    }
    $profile=post('profile-update',['full_name'=>'Phase8 teacher changed','phone'=>'09170000002','department'=>'Science','email'=>'discarded@example.invalid'],$teacherSession);
    check($profile['success']===true && $pdo->query('SELECT department FROM users WHERE id='.$teacher)->fetchColumn()==='Science','teacher profile persists permitted fields');
    [$status,$visitorUpdate]=http('profile-update','POST',['full_name'=>'Visitor','phone'=>'09170000003','email'=>'phase8-admin'],$visitorSession);
    check($status===422,'visitor email validation rejects invalid identifier');
    // Registration obtains the real CSRF form token, then approval uses the same staff workflow.
    $registration=session();[$status,$data]=http('registration-token','GET',null,$registration);check($status===200,'registration token');
    [$status]=http('register-process','POST',['role'=>'student','full_name'=>'Phase8 new','student_id'=>'PH8-NEW','grade_level'=>'10','section'=>'A','phone'=>'09170000001','password'=>$password,'confirm_password'=>$password,'csrf_token'=>$data['csrfToken']],$registration);
    check($status===302,'registration redirects');$pending=(int)$pdo->query('SELECT id FROM users WHERE student_id="PH8-NEW"')->fetchColumn();
    check($pending>0 && $pdo->query('SELECT account_status FROM users WHERE id='.$pending)->fetchColumn()==='pending','registration remains pending');
    post('user-approval-action',['id'=>$pending,'status'=>'accepted'],$staff);post('user-approval-action',['id'=>$pending,'status'=>'accepted'],$staff,409);
    // Simulation never issues an undeliverable challenge. Live recovery is exercised with isolated mocks.
    $recoveryBefore=$pdo->query('SELECT reset_code FROM users WHERE id='.$student)->fetchColumn();
    post('forgot-password-request',['phone'=>'+63 917 000 0000','role'=>'student'],$studentSession,503);
    check($pdo->query('SELECT reset_code FROM users WHERE id='.$student)->fetchColumn()===$recoveryBefore,'unavailable recovery leaves account challenge unchanged');
    post('reset-password-process',['phone'=>'09170000000','role'=>'student','code'=>'123456','password'=>'New-Phase8-Password'],$studentSession,422);
    $pdo->prepare('UPDATE users SET password_hash=:hash WHERE id=:id')->execute(['hash'=>password_hash('Changed-by-test',PASSWORD_DEFAULT),'id'=>$teacher]);
    [$status]=http('books-data','GET',null,$teacherSession);check($status===401,'credential change rejects an already-bound session');
    $pdo->prepare('INSERT INTO admins(username,password_hash) VALUES("PH8-LIB",:hash)')->execute(['hash'=>password_hash($password,PASSWORD_DEFAULT)]);
    $ambiguous=session();[$status]=http('login-process','POST',['identifier'=>'PH8-LIB','password'=>$password],$ambiguous);
    [$status,$ambiguousStatus]=http('admin-session-status','GET',null,$ambiguous);check(!$ambiguousStatus['loggedIn'],'colliding admin/user identifier cannot select privilege');
    $pdo->exec('DELETE FROM admins WHERE username="PH8-LIB"'); // Remove this run's intentional collision fixture only.
    if (!getenv('BCIHS_SMS_ACCEPTANCE_ROOT')) {
        check((int)$pdo->query('SELECT COUNT(*) FROM sms_logs WHERE status<>"skipped"')->fetchColumn()===0,'all notifications simulated or safely skipped');
    } else {
        check(is_file(getenv('BCIHS_SMS_ACCEPTANCE_ROOT').'/mock-calls.jsonl'),'SMS acceptance used the isolated capture transport');
    }
    echo 'Completed '.$checks.' isolated Phase 8 workflow checks. Zero live provider calls; disposable database only.'."\n";
} finally {foreach($cookies as $file)if(is_file($file))unlink($file);}
