<?php
require_once __DIR__ . '/../scripts/local-cli.php';
require_once __DIR__ . '/../includes/borrowing.php';
require_once __DIR__ . '/../scripts/local-backup.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8888/bcihs-library', '/') . '/';
$host = parse_url($base, PHP_URL_HOST);
if (!in_array($host, ['127.0.0.1','localhost','::1'], true) || !extension_loaded('curl')) {
    fwrite(STDERR, "Use a localhost URL and PHP cURL.\n"); exit(1);
}
$prefix = 'PHASE5-TEST-' . bin2hex(random_bytes(6));
$password = bin2hex(random_bytes(24));
$books = $users = $admins = $cookies = [];
require_once __DIR__ . '/../includes/attendance.php';
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
        if (is_array($data) && $endpoint !== 'login-process') $data = json_encode($data);
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
    $cookie = tempnam(sys_get_temp_dir(), 'bcihs-phase5-'); $cookies[] = $cookie;
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
function data(array $staff, string $query = ''): array {
    [$status,$body]=http('attendance-data'.$query,'GET',null,$staff);
    verify($status===200 && isset($body['formToken']), 'attendance data and fresh form token available'); return $body;
}
function enter(array $staff, array $payload, int $expected=200): array {
    $token=data($staff)['formToken'];
    [$status,$body]=http('attendance-add','POST',$payload+['submission_key'=>$token],$staff);
    verify($status===$expected,'Time In returns '.$expected);return $body;
}
function policy(array $staff, $daily=null, $occupancy=null, bool $tracking=false, int $expected=200): void {
    [$status]=http('attendance-settings','POST',['daily_visit_limit'=>$daily,'occupancy_limit'=>$occupancy,'track_departures'=>$tracking],$staff);
    verify($status===$expected,'attendance policy returns '.$expected);
}
function departure(array $staff,int $id,int $expected=200): void {
    [$status]=http('attendance-time-out','POST',['id'=>$id,'confirm_departure'=>true],$staff);
    verify($status===$expected,'confirmed departure returns '.$expected);
}
function capacityRace(array $jobs): array {
    global $pdo,$dsn,$options,$base;
    $observer=new PDO($dsn,DB_USER,DB_PASS,$options);
    $pdo->beginTransaction(); $pdo->query('SELECT id FROM attendance_settings WHERE id=1 FOR UPDATE');
    $multi=curl_multi_init();$handles=[];
    try {
        foreach($jobs as [$session,$payload]) {
            $curl=curl_init($base.'backend/attendance-add.php');
            curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_TIMEOUT=>12,CURLOPT_COOKIEFILE=>$session['cookie'],CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.$session['token']]]);
            curl_multi_add_handle($multi,$curl);$handles[]=$curl;
        }
        $deadline=microtime(true)+4;$waiting=0;
        do {
            curl_multi_exec($multi,$running);
            $waiting=(int)$observer->query("SELECT COUNT(*) FROM performance_schema.data_locks WHERE OBJECT_SCHEMA='bcihs_library' AND OBJECT_NAME='attendance_settings' AND LOCK_STATUS='WAITING'")->fetchColumn();
            if($waiting<count($jobs))curl_multi_select($multi,.05);
        } while($waiting<count($jobs) && $running && microtime(true)<$deadline);
        verify($waiting>=count($jobs),'independent HTTP sessions overlap while waiting on attendance policy lock');
        $pdo->commit();
        do {curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,.1);}while($running);
        return array_map(static fn($h)=>[curl_getinfo($h,CURLINFO_RESPONSE_CODE),json_decode(curl_multi_getcontent($h),true)],$handles);
    } finally {if($pdo->inTransaction())$pdo->rollBack();foreach($handles as $h){curl_multi_remove_handle($multi,$h);curl_close($h);}curl_multi_close($multi);}
}
$baseline=localRowSnapshot($pdo);
$original=attendanceSettings($pdo);
$today=$pdo->query('SELECT CURDATE()')->fetchColumn();
if(attendanceCounts($pdo,$today)['visits'] || attendanceOccupancy($pdo)) {fwrite(STDERR,"Existing attendance today/open visits found. Stop local activity; do not change their policy.\n");exit(1);}
try {
    $staffUser=newUser('librarian');$staff=$staffUser['session'];
    $staff2=newUser('librarian')['session'];
    $student=newUser();$other=newUser();$teacher=newUser('teacher');
    $pdo->prepare('UPDATE users SET full_name=:name,student_id=:student,year_section=:year WHERE id=:id')->execute(['name'=>$prefix.' Same Name','student'=>$prefix.'-001','year'=>'Grade 12 – Long section snapshot','id'=>$student['id']]);
    $pdo->prepare('UPDATE users SET full_name=:name WHERE id=:id')->execute(['name'=>$prefix.' Same Name','id'=>$other['id']]);
    $pdo->prepare('INSERT INTO admins(username,password_hash)VALUES(:name,:hash)')->execute(['name'=>$prefix,'hash'=>password_hash($password,PASSWORD_DEFAULT)]);
    $admins[]=(int)$pdo->lastInsertId();$admin=signIn($prefix);
    foreach(['attendance-data','attendance-students?search=xx'] as $endpoint) {
        [$code]=http($endpoint);verify($code===401,'anonymous read refused');
        [$code]=http($endpoint,'GET',null,$student['session']);verify($code===403,'student read refused');
    }
    foreach(['attendance-add','attendance-time-out','attendance-settings'] as $endpoint) {
        [$code]=http($endpoint,'POST',[]);verify($code===401,'anonymous write refused');
        [$code]=http($endpoint,'POST',[],$student['session']);verify($code===403,'student write refused');
        [$code]=http($endpoint,'POST',[],$teacher['session']);verify($code===403,'teacher write refused');
        [$code]=http($endpoint,'POST',[],$staff,false);verify($code===403,'missing CSRF refused');
        [$code]=http($endpoint,'GET',null,$staff);verify($code===405,'write endpoint rejects GET');
        [$code]=http($endpoint,'POST','{broken',$staff);verify($code===400,'malformed JSON refused');
    }
    $first=data($admin);verify($first['settings']['daily_visit_limit']===null && $first['settings']['occupancy_limit']===null && !(int)$first['settings']['track_departures'],'unlimited Time In-only defaults');
    verify($first['serverToday']===$today && $first['timezone']==='Asia/Manila','server Philippine date used');
    foreach(['?date=2026-02-30','?date[]=x','?view=bogus','?search[]=x','?date=9999-12-31'] as $query){[$code]=http('attendance-data'.$query,'GET',null,$staff);verify($code===422,'invalid filter refused');}
    foreach(['a',str_repeat('x',101)] as $query){[$code]=http('attendance-students?search='.urlencode($query),'GET',null,$staff);verify($code===422,'invalid lookup refused');}
    [$code,$lookup]=http('attendance-students?search='.urlencode($prefix.' Same Name'),'GET',null,$staff);
    verify($code===200 && count($lookup['students'])===2 && $lookup['students'][0]['id']!==$lookup['students'][1]['id'],'same-name students remain distinct identities');
    verify(!isset($lookup['students'][0]['email'],$lookup['students'][0]['password_hash']),'lookup excludes private contact and credentials');
    [$code,$lookup]=http('attendance-students?search='.urlencode($prefix.'-001'),'GET',null,$staff);verify(count($lookup['students'])===1 && (int)$lookup['students'][0]['id']===$student['id'],'lookup by student ID works');
    $token=data($staff)['formToken'];$payload=['mode'=>'registered','user_id'=>$student['id'],'submission_key'=>$token,'student_name'=>'Spoofed'];
    [$code,$saved]=http('attendance-add','POST',$payload,$staff);verify($code===200,'registered student Time In saved');$id=(int)$saved['id'];
    $row=$pdo->query('SELECT * FROM attendance WHERE id='.$id)->fetch();
    verify((int)$row['user_id']===$student['id'] && $row['student_name']===$prefix.' Same Name' && $row['student_identifier']===$prefix.'-001','identity snapshot comes from account, not typed name');
    verify($row['year_section_snapshot']==='Grade 12 – Long section snapshot' && $row['time_out']===null && !(int)$row['tracks_departure'],'year/section snapshot retained without truncation or fabricated departure');
    verify(abs(strtotime($row['time_in'])-time())<5,'Time In is current Philippine server time');
    [$code,$retry]=http('attendance-add','POST',$payload,$staff);verify($code===200 && $retry['replayed'] && (int)$retry['id']===$id,'lost-response retry returns same visit without duplication');
    $payload['user_id']=$other['id'];[$code]=http('attendance-add','POST',$payload,$staff);verify($code===409,'used form key cannot be repurposed');
    enter($staff,['user_id'=>$student['id']],409);
    $repeat=enter($staff,['user_id'=>$student['id'],'confirm_repeat'=>true]);verify((int)$repeat['id']!==$id,'explicit legitimate Time In-only repeat visit permitted');
    departure($staff,$id,409);
    $manual=['mode'=>'manual','student_name'=>$prefix.' Visitor','year_level'=>'Visitor','section'=>'N/A'];
    enter($staff,$manual);enter($staff,$manual);
    $d=data($staff);verify($d['today']['visits']===4 && $d['today']['registered_students']===1 && $d['today']['walk_in_visits']===2,'daily visits, unique registered students and manual visits counted separately');
    verify($d['inside']===0,'Time In-only visits never count as current occupants');
    foreach([[],['mode'=>'x'],['user_id'=>[]],['user_id'=>$teacher['id']],['mode'=>'manual','student_name'=>[]],$manual+['submission_key'=>'bad']] as $invalid)enter($staff,$invalid,422);
    foreach(['student_name','year_level','section'] as $field){$invalid=$manual;$invalid[$field]='';enter($staff,$invalid,422);$invalid[$field]=str_repeat('x',151);enter($staff,$invalid,422);}
    foreach([0,-1,'1.5','abc',65536,[],true] as $limit) {
        [$code]=http('attendance-settings','POST',['daily_visit_limit'=>$limit,'track_departures'=>false],$staff);
        verify($code===422,'invalid capacity value refused');
    }
    [$code]=http('attendance-settings','POST',['track_departures'=>'false'],$staff);verify($code===422,'tracking flag must be a boolean');
    [$code,$literal]=http('attendance-students?search='.urlencode($prefix.'%'),'GET',null,$staff);verify($code===200 && !$literal['students'],'lookup treats wildcard characters literally');
    policy($staff,null,1,false,422);
    policy($staff,3,null,false,409);
    policy($staff,4);enter($staff,$manual,409);
    policy($staff,null,null,true);
    $open=enter($staff,['user_id'=>$student['id'],'confirm_repeat'=>true]);$openId=(int)$open['id'];
    enter($staff2,['user_id'=>$student['id'],'confirm_repeat'=>true],409);
    policy($staff,null,null,false,409);
    [$code]=http('attendance-time-out','POST',['id'=>$openId],$staff);verify($code===422,'departure requires explicit confirmation');
    departure($staff2,$openId);$out=$pdo->query('SELECT time_out FROM attendance WHERE id='.$openId)->fetchColumn();
    departure($staff,$openId,409);verify($pdo->query('SELECT time_out FROM attendance WHERE id='.$openId)->fetchColumn()===$out,'repeated departure cannot overwrite original timestamp');
    $again=enter($staff,['user_id'=>$student['id'],'confirm_repeat'=>true]);departure($staff,(int)$again['id']);
    policy($staff,null,1,true);
    $jobs=[];foreach([$staff,$staff2] as $session)$jobs[]=[$session,$manual+['submission_key'=>data($session)['formToken']]];
    $race=capacityRace($jobs);$codes=array_column($race,0);sort($codes);verify($codes===[200,409] && attendanceOccupancy($pdo)===1,'concurrent different visitors cannot exceed final occupancy place');
    $win=$race[0][0]===200?$race[0][1]:$race[1][1];departure($staff,(int)$win['id']);
    $current=attendanceCounts($pdo,$today)['visits'];policy($staff,$current+1,null,true);
    $jobs=[];foreach([$staff,$staff2] as $session)$jobs[]=[$session,$manual+['submission_key'=>data($session)['formToken']]];
    $race=capacityRace($jobs);$codes=array_column($race,0);sort($codes);verify($codes===[200,409] && attendanceCounts($pdo,$today)['visits']===$current+1,'concurrent visitors cannot exceed final daily visit place');
    $win=$race[0][0]===200?$race[0][1]:$race[1][1];departure($staff,(int)$win['id']);policy($staff,null,null,true);
    $jobs=[];foreach([$staff,$staff2] as $session)$jobs[]=[$session,['user_id'=>$other['id'],'submission_key'=>data($session)['formToken']]];
    $race=capacityRace($jobs);$codes=array_column($race,0);sort($codes);verify($codes===[200,409],'concurrent same-identity visits cannot create two open visits');
    $win=$race[0][0]===200?$race[0][1]:$race[1][1];$overnight=(int)$win['id'];
    $pdo->prepare('UPDATE attendance SET time_in=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE id=:id AND user_id=:user')->execute(['id'=>$overnight,'user'=>$other['id']]);
    $d=data($staff,'?view=inside');verify(count($d['records'])===1 && (int)$d['records'][0]['id']===$overnight && $d['inside']===1,'inside view includes yesterday’s still-open visit');
    $yesterday=(new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');$d=data($staff,'?date='.$yesterday);verify(count($d['records'])===1 && $d['summary']['visits']===1,'historical Philippine date filter accurate');
    $d=data($staff,'?search='.urlencode($prefix.'-001'));verify(count($d['records'])===4 && $d['summary']['visits']>count($d['records']),'search by ID filters rows without altering daily summary');
    $d=data($staff,'?view=completed');verify(count($d['records'])===4 && (int)$d['records'][0]['duration_seconds']>=0,'completed history retains nonnegative visit durations');
    $d=data($staff,'?view=time_in_only');verify(count($d['records'])===4,'legacy-compatible Time In-only filter');
    [$code]=http('user-delete','POST',['id'=>$student['id']],$staff);verify($code===409,'Users preserves accounts with attendance history');
    $pdo->prepare('UPDATE users SET account_status="pending" WHERE id=:id')->execute(['id'=>$student['id']]);enter($staff,['user_id'=>$student['id'],'confirm_repeat'=>true],422);
    [$code,$lookup]=http('attendance-students?search='.urlencode($prefix.'-001'),'GET',null,$staff);verify(!$lookup['students'],'unapproved account excluded from lookup');
    $pdo->prepare('UPDATE users SET role="teacher" WHERE id=:id')->execute(['id'=>$staffUser['id']]);[$code]=http('attendance-settings','POST',[],$staff);verify($code===403,'stale librarian session rechecked against current database role');
    departure($admin,$overnight);policy($admin);
} catch(Throwable $e){fwrite(STDERR,$e instanceof PDOException?"Local fixture database operation failed.\n":$e->getMessage().PHP_EOL);$failed=true;}
finally {
    if($pdo->inTransaction())$pdo->rollBack();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM attendance WHERE student_name LIKE :prefix')->execute(['prefix'=>$prefix.'%']);
    foreach($users as $id)$pdo->prepare('DELETE FROM users WHERE id=:id')->execute(['id'=>$id]);
    foreach($admins as $id)$pdo->prepare('DELETE FROM admins WHERE id=:id')->execute(['id'=>$id]);
    $pdo->prepare('UPDATE attendance_settings SET daily_visit_limit=:daily,occupancy_limit=:occupancy,track_departures=:tracking WHERE id=1')->execute(['daily'=>$original['daily_visit_limit'],'occupancy'=>$original['occupancy_limit'],'tracking'=>$original['track_departures']]);
    if(localRowSnapshot($pdo)!==$baseline){$pdo->rollBack();throw new RuntimeException('Fixture cleanup failed original-row verification; changes rolled back.');}
    $pdo->commit();foreach($cookies as $file)if(is_file($file))unlink($file);
    verify(localRowSnapshot($pdo)===$baseline,'all original rows and attendance policy unchanged after fixture cleanup');
}
echo 'Completed '.$checks." Phase 5 checks.\n";exit(isset($failed)?1:0);
