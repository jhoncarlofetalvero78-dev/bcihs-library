<?php
require_once __DIR__ . '/../scripts/local-cli.php';
require_once __DIR__ . '/../includes/borrowing.php';
require_once __DIR__ . '/../scripts/local-backup.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8888/bcihs-library', '/') . '/';
$host = parse_url($base, PHP_URL_HOST);
if (!in_array($host, ['127.0.0.1','localhost','::1'], true) || !extension_loaded('curl')) {
    fwrite(STDERR, "Use a localhost URL and PHP cURL.\n"); exit(1);
}
$prefix = 'PHASE7A-TEST-' . bin2hex(random_bytes(6));
$password = bin2hex(random_bytes(24));
$books = $users = $admins = $cookies = [];
$checks = 0;

function verify(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL; $checks++;
}
function http(string $endpoint, string $method = 'GET', $data = null, ?array $session = null, bool $csrf = true): array {
    global $base;
    $parts = explode('?', $endpoint, 2);
    $curl = curl_init($base . 'backend/' . $parts[0] . '.php' . (isset($parts[1]) ? '?' . $parts[1] : ''));
    $headers = [];
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    if ($session) { curl_setopt($curl, CURLOPT_COOKIEFILE, $session['cookie']); curl_setopt($curl, CURLOPT_COOKIEJAR, $session['cookie']); }
    if ($data !== null) {
        if (is_array($data) && !in_array($endpoint,['login-process','register-process'],true)) $data = json_encode($data);
        if (is_string($data)) $headers[] = 'Content-Type: application/json';
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    }
    if ($csrf && isset($session['token'])) $headers[] = 'X-CSRF-Token: ' . $session['token'];
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    $body = curl_exec($curl);
    if ($body === false) throw new RuntimeException('Local HTTP request failed.');
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($session) curl_setopt($curl, CURLOPT_COOKIELIST, 'FLUSH');
    curl_close($curl);
    return [$status, json_decode($body, true)];
}
function signIn(string $identifier): array {
    global $password, $cookies;
    $cookie = tempnam(sys_get_temp_dir(), 'bcihs-phase2b-'); $cookies[] = $cookie;
    $session = ['cookie' => $cookie];
    [$status] = http('login-process', 'POST', ['identifier' => $identifier, 'password' => $password], $session);
    verify($status === 302, 'fixture sign-in redirects');
    [$status, $data] = http('books-data', 'GET', null, $session);
    verify($status === 200 && isset($data['csrfToken']), 'fixture session receives a catalog token');
    $session['token'] = $data['csrfToken']; return $session;
}
function newUser(string $role = 'student'): array {
    global $pdo, $prefix, $password, $users;
    $email = strtolower($prefix . '-' . count($users)) . '@example.invalid';
    $pdo->prepare('INSERT INTO users (full_name,email,password_hash,role,account_status) VALUES (:name,:email,:hash,:role,"accepted")')
        ->execute(['name' => $prefix . ' ' . $role, 'email' => $email, 'hash' => password_hash($password,PASSWORD_DEFAULT), 'role' => $role]);
    $id=(int)$pdo->lastInsertId(); $users[]=$id;
    return ['id'=>$id,'session'=>signIn($email)];
}
function newBook(int $total = 1): int {
    global $pdo,$prefix,$books;
    $pdo->prepare('INSERT INTO books (title,author,total_copies,ddc,call_number) VALUES (:title,"Local Test Author",:total,"500","500 T123")')
        ->execute(['title'=>$prefix.' Book '.count($books),'total'=>$total]);
    $id=(int)$pdo->lastInsertId(); $books[]=$id; return $id;
}
function requestLoan(int $book, array $student): int {
    [$status,$data]=http('borrow-request-process','POST',['book_id'=>$book],$student['session']);
    verify($status===200 && isset($data['id']),'student creates a pending request'); return (int)$data['id'];
}
function action(int $id,string $action,array $staff,int $expected=200): array {
    [$status,$data]=http('borrow-request-action','POST',['id'=>$id,'action'=>$action],$staff);
    verify($status===$expected, 'borrow action '.$action.' returns '.$expected); return $data ?? [];
}
function reservationAction(int $id,string $action,array $staff,int $expected=200): void {
    [$status]=http('reservation-action','POST',['id'=>$id,'action'=>$action],$staff);
    verify($status===$expected,'reservation action '.$action.' returns '.$expected);
}
function stock(int $id,int $borrowed,int $held,array $session): void {
    global $pdo;
    [$status,$data]=http('books-data','GET',null,$session);
    $book=array_values(array_filter($data['books']??[],static fn($row)=>(int)$row['id']===$id))[0]??null;
    verify($status===200 && $book && (int)$book['borrowed_copies']===$borrowed && (int)$book['held_copies']===$held
        && (int)$book['available_copies']===(int)$book['total_copies']-$borrowed
        && (int)$book['requestable_copies']===(int)$book['total_copies']-$borrowed-$held,'physical, borrowed, held and requestable counts agree');
    $stmt=$pdo->prepare('SELECT (SELECT COUNT(*) FROM borrow_requests WHERE book_id=:borrow AND status IN ("approved","checked_out")) + (SELECT COUNT(*) FROM reservations WHERE book_id=:reservation AND status="fulfilled")');
    $stmt->execute(['borrow'=>$id,'reservation'=>$id]);
    verify((int)$stmt->fetchColumn()===$borrowed,'outstanding loan rows match borrowed inventory');
}

require_once __DIR__ . '/../includes/reporting.php';

require_once __DIR__ . '/../includes/account-management.php';
$announcements=[];$visits=[];$testFiles=[];
$backup=backupLocalDatabase($pdo,'/Users/macbookprom116512/.codex/backups/bcihs-library','/Applications/MAMP/Library/bin/mysql80/bin/mysqldump','phase7a-tests');
$baseline=localRowSnapshot($pdo);
function accountAction(array $staff,int $id,string $status,int $expected=200): void {
    [$code]=http('user-approval-action','POST',['id'=>$id,'status'=>$status],$staff);verify($code===$expected,'account '.$status.' returns '.$expected);
}
function announce(array $staff,array $payload,int $expected=200): array {
    [$code,$form]=http('announcement-management-data','GET',null,$staff);verify($code===200,'staff announcement form loads');
    [$code,$result]=http('announcement-save','POST',$payload+['submission_key'=>$form['submissionToken']],$staff);
    verify($code===$expected,'announcement save returns '.$expected);return $result??[];
}
try {
    $staffUser=newUser('librarian');$staff=$staffUser['session'];$student=newUser();$teacher=newUser('teacher');
    $pdo->prepare('INSERT INTO admins(username,password_hash)VALUES(:name,:hash)')->execute(['name'=>$prefix,'hash'=>password_hash($password,PASSWORD_DEFAULT)]);$admins[]=(int)$pdo->lastInsertId();$admin=signIn($prefix);
    foreach(['all-users-data','announcement-management-data'] as $endpoint){
        [$code]=http($endpoint);verify($code===401,'anonymous staff listing rejected');
        foreach([$student['session'],$teacher['session']] as $session){[$code]=http($endpoint,'GET',null,$session);verify($code===403,'patron staff listing rejected');}
    }
    foreach(['user-approval-action','user-delete','announcement-save','announcement-delete','profile-update'] as $endpoint){
        [$code]=http($endpoint,'POST',[]);verify($code===401,'anonymous write rejected');
        [$code]=http($endpoint,'POST',[],$staff,false);verify($code===403,'missing CSRF rejected');
        [$code]=http($endpoint,'GET',null,$staff);verify($code===405,'write endpoint rejects GET');
    }
    foreach(['user-approval-action','announcement-save','announcement-delete'] as $endpoint){[$code]=http($endpoint,'POST',[],$student['session']);verify($code===403,'student cannot manage staff records');}
    [$code,$list]=http('all-users-data','GET',null,$admin);verify($code===200 && isset($list['csrfToken'],$list['serverDate']),'admin users listing and time available');
    verify(!isset($list['users'][0]['password_hash'],$list['users'][0]['reset_code']),'user list omits credential fields');
    $pending=newUser();$pdo->prepare('UPDATE users SET account_status="pending" WHERE id=:id')->execute(['id'=>$pending['id']]);
    accountAction($staff,$pending['id'],'accepted');accountAction($admin,$pending['id'],'accepted',409);
    accountAction($staff,$pending['id'],'rejected',409);
    $pdo->prepare('UPDATE users SET account_status="pending" WHERE id=:id')->execute(['id'=>$pending['id']]);
    accountAction($staff,$pending['id'],'rejected');accountAction($staff,$pending['id'],'rejected',409);
    [$code,$profile]=http('profile-data','GET',null,$pending['session']);verify($code===401,'rejected existing session cannot read profile');
    accountAction($staff,$pending['id'],'accepted');
    foreach([[],['id'=>[],'status'=>'accepted'],['id'=>$student['id'],'status'=>'unknown']] as $invalid){[$code]=http('user-approval-action','POST',$invalid,$staff);verify($code===($invalid===[]?400:422),'invalid approval input rejected');}
    [$code]=http('user-delete','POST',['id'=>$staffUser['id']],$staff);verify($code===409,'librarian cannot delete own account');
    $book=newBook();
    foreach(['borrow_requests','reservations','attendance','sms_logs','announcements'] as $table){
        $u=newUser();$id=$u['id'];
        if($table==='borrow_requests')$pdo->prepare('INSERT INTO borrow_requests(user_id,book_id,status)VALUES(:user,:book,"rejected")')->execute(['user'=>$id,'book'=>$book]);
        elseif($table==='reservations')$pdo->prepare('INSERT INTO reservations(user_id,book_id,status)VALUES(:user,:book,"cancelled")')->execute(['user'=>$id,'book'=>$book]);
        elseif($table==='attendance'){$pdo->prepare('INSERT INTO attendance(user_id,student_name,year_level,section)VALUES(:user,:name,"Test","Test")')->execute(['user'=>$id,'name'=>$prefix]);$visits[]=(int)$pdo->lastInsertId();}
        elseif($table==='sms_logs')$pdo->prepare('INSERT INTO sms_logs(user_id,phone,message,status)VALUES(:user,"","Fixture","skipped")')->execute(['user'=>$id]);
        else {$pdo->prepare('INSERT INTO announcements(title,body,posted_by)VALUES(:title,"Fixture",:user)')->execute(['title'=>$prefix,'user'=>$id]);$announcements[]=(int)$pdo->lastInsertId();}
        [$code]=http('user-delete','POST',['id'=>$id],$staff);verify($code===409,'deletion preserves '.$table.' reference');
        [$code,$listing]=http('all-users-data','GET',null,$staff);$entry=array_values(array_filter($listing['users'],static fn($r)=>(int)$r['id']===$id))[0];verify((int)$entry['has_history']===1,'user list explains protected '.$table.' history');
    }
    $empty=newUser();[$code]=http('user-delete','POST',['id'=>$empty['id']],$staff);verify($code===200,'history-free account deletion allowed');[$code]=http('user-delete','POST',['id'=>$empty['id']],$staff);verify($code===404,'repeated delete not falsely successful');
    [$code,$profile]=http('profile-data','GET',null,$student['session']);verify($code===200 && $profile['role']==='student' && isset($profile['csrfToken']),'approved student profile consistent');
    [$code]=http('profile-update','POST',['full_name'=>$prefix.' Updated','year_section'=>'Grade 10 — New','phone'=>'+639123456789','student_id'=>'FORGED'],$student['session']);verify($code===200,'approved profile updates supported fields');
    [$code,$profile]=http('profile-data','GET',null,$student['session']);verify($profile['full_name']===$prefix.' Updated' && $profile['year_section']==='Grade 10 — New' && $profile['student_id']!=='FORGED','saved profile matches Users; immutable identifier cannot be overwritten');
    [$code,$profile]=http('profile-data','GET',null,$admin);verify($profile['role']==='admin' && $profile['full_name']===$prefix && $profile['readOnly'],'standalone admin profile reads admins table only');
    [$code]=http('profile-update','POST',['full_name'=>'Wrong account','phone'=>'09123456789'],$admin);verify($code===403,'admin profile cannot edit a colliding patron ID');
    foreach([['full_name'=>[]],['full_name'=>str_repeat('x',151)],['full_name'=>'Name','phone'=>'bad']] as $invalid){[$code]=http('profile-update','POST',$invalid,$teacher['session']);verify($code===422,'profile validation refuses malformed details');}
    $body="Long text <script>alert(1)</script>\n".str_repeat('Readable Unicode café announcement. ',100);
    $created=announce($staff,['title'=>$prefix.' Notice','body'=>$body,'category'=>'Notice']);$announcements[]=$id=(int)$created['id'];
    [$code,$public]=http('announcements-data');$row=array_values(array_filter($public['announcements'],static fn($r)=>(int)$r['id']===$id))[0];
    verify($code===200 && $row['body']===trim($body) && $row['title']===$prefix.' Notice','new publication immediately visible on public endpoint');
    verify(!isset($row['posted_by'],$row['email']),'public announcements exclude staff private details');
    $originalDate=$row['created_at'];
    $updated=announce($staff,['id'=>$id,'title'=>$prefix.' Edited','body'=>'Edited text','category'=>'Event']);
    [$code,$public]=http('announcements-data');$row=array_values(array_filter($public['announcements'],static fn($r)=>(int)$r['id']===$id))[0];verify($row['body']==='Edited text' && $row['created_at']===$originalDate,'editing preserves publication date and updates public content');
    [$code,$form]=http('announcement-management-data','GET',null,$staff);
    $payload=['submission_key'=>$form['submissionToken'],'title'=>$prefix.' Retry','body'=>'Safe retry','category'=>'General'];
    [$code,$first]=http('announcement-save','POST',$payload,$staff);$announcements[]=(int)$first['id'];
    [$code,$second]=http('announcement-save','POST',$payload,$staff);verify($code===200 && $second['replayed'] && $first['id']===$second['id'],'same submission retry creates only one announcement');
    $payload['body']='Different';[$code]=http('announcement-save','POST',$payload,$staff);verify($code===409,'used submission cannot be repurposed');
    foreach([['title'=>[],'body'=>'Body'],['title'=>str_repeat('x',201),'body'=>'Body'],['title'=>'Title','body'=>[]],['title'=>'Title','body'=>str_repeat('a',60001)],['title'=>'Title','body'=>'Body','category'=>'Hidden'],['id'=>[],'title'=>'Title','body'=>'Body']] as $invalid)announce($staff,$invalid,422);
    [$code]=http('announcement-save','POST','{broken',$staff);verify($code===400,'malformed announcement JSON rejected');
    [$code]=http('announcement-delete','POST',['id'=>$id],$staff);verify($code===200,'staff removes own disposable publication');
    [$code,$public]=http('announcements-data');verify(!array_filter($public['announcements'],static fn($r)=>(int)$r['id']===$id),'removed announcement disappears from student/public endpoint');
    [$code]=http('announcement-delete','POST',['id'=>$id],$staff);verify($code===404,'repeated removal reports missing record');
    $image=tempnam(sys_get_temp_dir(),'phase7a-image-');$testFiles[]=$image;
    file_put_contents($image,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a1XkAAAAASUVORK5CYII='));
    $upload=static function(array $fields,string $path,string $mime)use($staff,$base):array {
        [, $form]=http('announcement-management-data','GET',null,$staff);
        $curl=curl_init($base.'backend/announcement-save.php');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$staff['cookie'],
            CURLOPT_HTTPHEADER=>['X-CSRF-Token: '.$staff['token']],CURLOPT_POSTFIELDS=>$fields+['submission_key'=>$form['submissionToken'],'photo'=>new CURLFile($path,$mime,'fixture.png')]]);
        $body=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);return[$code,json_decode($body,true)];
    };
    [$code,$photoResult]=$upload(['title'=>$prefix.' Photo','body'=>'Photo fixture','category'=>'General'],$image,'image/png');
    verify($code===200,'validated multipart image upload succeeds');$announcements[]=$photoId=(int)$photoResult['id'];
    $q=$pdo->prepare('SELECT image_path FROM announcements WHERE id=:id');$q->execute(['id'=>$photoId]);$photoPath=$q->fetchColumn();
    verify(preg_match('#^uploads/announcements/[a-f0-9]{32}\.png$#',$photoPath) && is_file(dirname(__DIR__).'/'.$photoPath),'photo uses safe generated local path');
    announce($staff,['id'=>$photoId,'title'=>$prefix.' Photo edited','body'=>'Keep photo','category'=>'Notice']);
    $q->execute(['id'=>$photoId]);verify($q->fetchColumn()===$photoPath,'editing without a new upload preserves photo');
    [$code,$replaced]=$upload(['id'=>$photoId,'title'=>$prefix.' Photo replaced','body'=>'Replacement','category'=>'General'],$image,'image/png');
    $q->execute(['id'=>$photoId]);$replacement=$q->fetchColumn();verify($code===200 && $replacement!==$photoPath && !is_file(dirname(__DIR__).'/'.$photoPath),'replacement retires old photo only after save');
    [$code]=http('announcement-delete','POST',['id'=>$photoId],$staff);verify($code===200 && !is_file(dirname(__DIR__).'/'.$replacement),'announcement removal cleans its own generated photo');
    $bad=tempnam(sys_get_temp_dir(),'phase7a-bad-');$testFiles[]=$bad;file_put_contents($bad,'<?php echo "not an image";');
    [$code]=$upload(['title'=>$prefix.' Invalid photo','body'=>'Invalid'],$bad,'image/png');verify($code===422,'spoofed image extension and MIME rejected');
    file_put_contents($bad,str_repeat('x',5*1024*1024+1));
    [$code]=$upload(['title'=>$prefix.' Oversized photo','body'=>'Invalid'],$bad,'image/png');verify($code===422,'oversized photo rejected');
    foreach(['student','teacher','librarian','visitor'] as $role){
        $identifier=$prefix.'-'.$role;
        $payload=['role'=>$role,'csrf_token'=>$staff['token'],'full_name'=>$prefix.' Registered '.$role,'password'=>$password,'confirm_password'=>$password,'phone'=>'09123456789',
            'student_id'=>$identifier,'id_number'=>$identifier,'email'=>strtolower($identifier).'@example.invalid','grade_level'=>'Grade 10','section'=>'Test','department'=>'Science'];
        [$code]=http('register-process','POST',$payload,$staff);verify($code===302,'registration redirects for '.$role);
        $q=$pdo->prepare('SELECT id,role,account_status,phone FROM users WHERE full_name=:name');$q->execute(['name'=>$payload['full_name']]);$registered=$q->fetch();
        verify($registered && $registered['role']===$role && $registered['account_status']==='pending','new '.$role.' registration requires approval');$users[]=(int)$registered['id'];
        $identifier=$role==='visitor'?$payload['email']:$identifier;
        $cookie=tempnam(sys_get_temp_dir(),'phase7a-login-');$cookies[]=$cookie;$login=['cookie'=>$cookie];
        [$code]=http('login-process','POST',['identifier'=>$identifier,'password'=>$password],$login);verify($code===302,'unapproved sign-in returns redirect');
        [$code,$session]=http('session-status','GET',null,$login);verify(!$session['loggedIn'],'pending '.$role.' cannot gain portal access');
        accountAction($admin,(int)$registered['id'],'accepted');
        [$code]=http('login-process','POST',['identifier'=>$identifier,'password'=>$password],$login);
        [$code,$session]=http('session-status','GET',null,$login);verify($session['loggedIn'] && $session['role']===$role,'approved '.$role.' can access intended portal');
        $q=$pdo->prepare('SELECT COUNT(*) FROM users WHERE full_name=:name');$q->execute(['name'=>$payload['full_name']]);$before=(int)$q->fetchColumn();
        http('register-process','POST',$payload,$staff);$q->execute(['name'=>$payload['full_name']]);verify((int)$q->fetchColumn()===$before,'duplicate '.$role.' registration creates no extra account');
    }
    foreach([['full_name'=>[]],['role'=>[]],['password'=>'short'],['phone'=>'bad'],['csrf_token'=>'bad'],['full_name'=>str_repeat('x',151)]] as $invalid){
        $payload=['role'=>'student','csrf_token'=>$staff['token'],'full_name'=>$prefix.' Invalid','password'=>$password,'confirm_password'=>$password,'phone'=>'09123456789','student_id'=>$prefix.'-INVALID','year_section'=>'Grade 7 — Test'];
        http('register-process','POST',array_replace($payload,$invalid),$staff);
        $q=$pdo->prepare('SELECT COUNT(*) FROM users WHERE student_id=:id');$q->execute(['id'=>$prefix.'-INVALID']);verify((int)$q->fetchColumn()===0,'invalid registration creates no user');
    }
    $q=$pdo->prepare('SELECT COUNT(*) FROM sms_logs WHERE user_id IN ('.implode(',',array_fill(0,count($users),'?')).') AND status="sent"');$q->execute($users);verify((int)$q->fetchColumn()===0,'registration, review and announcement tests send no SMS');
    $pdo->prepare('UPDATE users SET role="teacher" WHERE id=:id')->execute(['id'=>$staffUser['id']]);
    [$code]=http('announcement-management-data','GET',null,$staff);verify($code===403,'stale librarian role cannot manage announcements');
    [$code]=http('user-approval-action','POST',['id'=>$student['id'],'status'=>'accepted'],$staff);verify($code===403,'stale librarian role cannot review users');
}catch(Throwable $e){fwrite(STDERR,$e instanceof PDOException?"Local Phase 7A fixture operation failed.\n":$e->getMessage().PHP_EOL);$failed=true;}
finally {
    if($pdo->inTransaction())$pdo->rollBack();$pdo->beginTransaction();
    foreach($announcements as $id){
        $q=$pdo->prepare('SELECT image_path FROM announcements WHERE id=:id AND title LIKE :prefix');$q->execute(['id'=>$id,'prefix'=>$prefix.'%']);$path=$q->fetchColumn();
        $pdo->prepare('DELETE FROM announcements WHERE id=:id AND title LIKE :prefix')->execute(['id'=>$id,'prefix'=>$prefix.'%']);
        if($path && preg_match('#^uploads/announcements/[a-f0-9]{32}\.(jpg|png|webp)$#',$path) && is_file(dirname(__DIR__).'/'.$path))unlink(dirname(__DIR__).'/'.$path);
    }
    foreach($visits as $id)$pdo->prepare('DELETE FROM attendance WHERE id=:id AND student_name=:name')->execute(['id'=>$id,'name'=>$prefix]);
    foreach($books as $id){foreach(['borrow_requests','reservations'] as $table)$pdo->prepare('DELETE FROM '.$table.' WHERE book_id=:id')->execute(['id'=>$id]);$pdo->prepare('DELETE FROM books WHERE id=:id')->execute(['id'=>$id]);}
    foreach($users as $id){$pdo->prepare('DELETE FROM sms_logs WHERE user_id=:id')->execute(['id'=>$id]);$pdo->prepare('DELETE FROM users WHERE id=:id')->execute(['id'=>$id]);}
    foreach($admins as $id)$pdo->prepare('DELETE FROM admins WHERE id=:id')->execute(['id'=>$id]);
    if(localRowSnapshot($pdo)!==$baseline){$pdo->rollBack();throw new RuntimeException('Original data mismatch; cleanup rolled back.');}
    $pdo->commit();foreach($cookies as $file)if(is_file($file))unlink($file);
    foreach($testFiles as $file)if(is_file($file))unlink($file);
    verify(localRowSnapshot($pdo)===$baseline,'all original rows and policies unchanged after fixture cleanup');
}
echo 'Completed '.$checks." Phase 7A API checks.\n";exit(isset($failed)?1:0);
