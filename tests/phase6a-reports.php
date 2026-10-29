<?php
require_once __DIR__ . '/../scripts/local-cli.php';
require_once __DIR__ . '/../includes/borrowing.php';
require_once __DIR__ . '/../scripts/local-backup.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8888/bcihs-library', '/') . '/';
$host = parse_url($base, PHP_URL_HOST);
if (!in_array($host, ['127.0.0.1','localhost','::1'], true) || !extension_loaded('curl')) {
    fwrite(STDERR, "Use a localhost URL and PHP cURL.\n"); exit(1);
}
$prefix = 'PHASE6A-TEST-' . bin2hex(random_bytes(6));
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
function report(array $staff,string $type,array $filters=[]): array {
    [$status,$data]=http('reports-data?'.http_build_query(['report'=>$type]+$filters),'GET',null,$staff);
    verify($status===200 && isset($data['records'],$data['summary'],$data['current']),$type.' report loads');return $data;
}
function fixtureRows(array $data): array {global $prefix;return array_values(array_filter($data['records'],static fn($r)=>str_contains(json_encode($r),$prefix)));}
function equalCurrent(array $staff): void {
    [$code,$dashboard]=http('dashboard-data','GET',null,$staff);$report=report($staff,'inventory');
    verify($code===200 && $dashboard['current']===$report['current'],'Dashboard and Reports share identical current definitions');
    [$code,$catalog]=http('books-data','GET',null,$staff);
    foreach(['borrowed_copies','held_copies','available_copies','requestable_copies'] as $field)verify(array_sum(array_column($catalog['books'],$field))===$report['current'][$field],'catalog agrees with report '.$field);
}
$backup=backupLocalDatabase($pdo,'/Users/macbookprom116512/.codex/backups/bcihs-library','/Applications/MAMP/Library/bin/mysql80/bin/mysqldump','phase6a-tests');
$baseline=localRowSnapshot($pdo);$visits=[];
try {
    $staffUser=newUser('librarian');$staff=$staffUser['session'];$a=newUser();$b=newUser();$teacher=newUser('teacher');
    $pdo->prepare('INSERT INTO admins(username,password_hash)VALUES(:name,:hash)')->execute(['name'=>$prefix,'hash'=>password_hash($password,PASSWORD_DEFAULT)]);$admins[]=(int)$pdo->lastInsertId();$admin=signIn($prefix);
    $book=newBook(3);$reservedBook=newBook(1);
    foreach(['reports-data','dashboard-data','borrowing-report-data','book-monthly-stats'] as $endpoint) {
        [$code]=http($endpoint);verify($code===401,'anonymous '.$endpoint.' refused');
        foreach([$a['session'],$teacher['session']] as $session){[$code]=http($endpoint,'GET',null,$session);verify($code===403,'nonstaff '.$endpoint.' refused');}
        [$code]=http($endpoint,'POST',[],$staff);verify($code===405,'report endpoint refuses writes');
    }
    foreach(['report=unknown','report[]=x','period=unknown','period=custom&from=2026-02-30&to=2026-03-01','period=custom&from=2026-03-01&to=2026-02-01','period=custom&from[]=x&to=2026-03-01','search[]=x','status=invalid','basis=invalid','search='.str_repeat('x',101)] as $query) {
        [$code]=http('reports-data?'.$query,'GET',null,$staff);verify($code===422,'invalid reporting filter rejected');
    }
    foreach(['today','week','month'] as $preset) {
        $range=report($staff,'loans',['period'=>$preset])['range'];
        verify($range===reportRange(['period'=>$preset]),'server Philippine '.$preset.' boundaries agree');
        if($preset==='week')verify((new DateTimeImmutable($range['from']))->format('N')==='1' && (new DateTimeImmutable($range['to']))->format('N')==='7','week runs Monday through Sunday');
    }
    equalCurrent($staff);
    $loan=requestLoan($book,$a);$before=report($staff,'inventory')['current'];action($loan,'approve',$staff);
    $after=report($staff,'inventory')['current'];verify($after['held_copies']===$before['held_copies']+1 && $after['borrowed_copies']===$before['borrowed_copies'],'approval is a pickup hold, not a borrowing');
    $search=['search'=>$prefix];verify(!fixtureRows(report($staff,'loans',$search)),'awaiting pickup excluded from checkout report');
    $requests=fixtureRows(report($staff,'requests',$search));verify(count($requests)===1 && $requests[0]['status']==='awaiting_pickup','request report distinguishes new approval');
    action($loan,'checkout',$staff);equalCurrent($staff);
    $rows=fixtureRows(report($staff,'loans',$search));verify(count($rows)===1 && $rows[0]['source']==='borrow' && $rows[0]['borrowed_at']!==null,'real checkout appears exactly once with timestamp');
    $pdo->prepare('UPDATE borrow_requests SET due_date=DATE_SUB(CURDATE(),INTERVAL 1 DAY) WHERE id=:id')->execute(['id'=>$loan]);
    $overdue=report($staff,'overdue',$search);verify(count($overdue['records'])===1 && $overdue['records'][0]['overdue'],'active past-due loan is overdue');
    $pdo->prepare('UPDATE borrow_requests SET due_date=CURDATE() WHERE id=:id')->execute(['id'=>$loan]);verify(!report($staff,'overdue',$search)['records'],'due today is not overdue');
    $pdo->prepare('UPDATE borrow_requests SET checked_out_at=:date WHERE id=:id')->execute(['date'=>(new DateTimeImmutable('first day of last month'))->format('Y-m-d').' 09:00:00','id'=>$loan]);
    action($loan,'return',$staff);
    verify(!fixtureRows(report($staff,'loans',$search)),'checkout event outside period excluded even when returned now');
    $returned=fixtureRows(report($staff,'returns',$search));verify(count($returned)===1 && $returned[0]['returned_at']!==null,'return event in period included despite earlier checkout');
    verify(!report($staff,'overdue',$search)['records'],'returned loan no longer overdue');equalCurrent($staff);
    $pickup=new DateTimeImmutable('tomorrow 09:00:00');while((int)$pickup->format('N')>5)$pickup=$pickup->modify('+1 day');
    $pdo->prepare('INSERT INTO reservations(user_id,book_id,status,pickup_at)VALUES(:user,:book,"waiting",:pickup)')->execute(['user'=>$b['id'],'book'=>$reservedBook,'pickup'=>$pickup->format('Y-m-d H:i:s')]);$reservation=(int)$pdo->lastInsertId();
    [$code]=http('reservation-action','POST',['id'=>$reservation,'action'=>'ready'],$staff);verify($code===200,'reservation ready allocates fixture copy');
    $ready=report($staff,'inventory')['current'];verify($ready['held_copies']===$before['held_copies']+1 && $ready['borrowed_copies']===$before['borrowed_copies'],'ready reservation is held rather than borrowed');
    [$code]=http('reservation-action','POST',['id'=>$reservation,'action'=>'fulfill'],$staff);verify($code===200,'reservation checkout succeeds');
    $rows=fixtureRows(report($staff,'loans',$search));verify(count($rows)===1 && $rows[0]['record_key']==='reservation:'.$reservation,'reservation-origin checkout counted once with distinct source key');
    [$code,$legacy]=http('borrowing-report-data','GET',null,$staff);verify(count(array_filter($legacy['borrowings'],static fn($r)=>$r['source']==='reservation' && (int)$r['id']===$reservation))===1,'legacy report endpoint uses same source identity');
    [$code]=http('reservation-action','POST',['id'=>$reservation,'action'=>'return'],$staff);verify($code===200,'reservation return succeeds');equalCurrent($staff);
    $rows=fixtureRows(report($staff,'returns',$search));verify(count($rows)===2 && count(array_unique(array_column($rows,'record_key')))===2,'both return origins counted exactly once');
    foreach(['waiting','ready','fulfilled','returned','cancelled'] as $status) {
        $pdo->prepare('INSERT INTO reservations(user_id,book_id,status,pickup_at)VALUES(:user,:book,:status,:pickup)')->execute(['user'=>$a['id'],'book'=>$book,'status'=>$status,'pickup'=>'2026-01-12 09:00:00']);
        $r=report($staff,'reservations',$search+['status'=>$status]);verify(count($r['records'])>=1 && count(array_filter($r['records'],static fn($row)=>$row['status']!==$status))===0,'reservation '.$status.' status filter');
    }
    $r=report($staff,'reservations',$search+['basis'=>'pickup_at','period'=>'custom','from'=>'2026-01-12','to'=>'2026-01-12']);verify(count($r['records'])===5,'pickup date filter uses schedule rather than creation time');
    $r=report($staff,'reservations',$search+['period'=>'custom','from'=>'2026-01-12','to'=>'2026-01-12']);verify(!$r['records'],'creation date filter does not silently switch to pickup date');
    $insert=$pdo->prepare('INSERT INTO attendance(user_id,student_name,year_level,section,student_identifier,time_in,tracks_departure,time_out)VALUES(:user,:name,"Grade 10","Test",:identifier,:time,:tracking,:out)');
    $today=date('Y-m-d');$yesterday=(new DateTimeImmutable('yesterday'))->format('Y-m-d');
    foreach([[$a['id'],$yesterday.' 23:59:59',0,null],[$a['id'],$today.' 00:00:00',0,null],[$a['id'],$today.' 09:00:00',1,$today.' 09:30:00'],[null,$today.' 10:00:00',0,null]] as [$uid,$time,$tracking,$out]) {
        $insert->execute(['user'=>$uid,'name'=>$prefix.' Visitor','identifier'=>$prefix.'-ID','time'=>$time,'tracking'=>$tracking,'out'=>$out]);$visits[]=(int)$pdo->lastInsertId();
    }
    $r=report($staff,'attendance',$search+['period'=>'today']);verify(count($r['records'])===3 && $r['summary']['Unique registered people']===1 && $r['summary']['Registered repeat visits on same day']===1 && $r['summary']['Manual / unlinked visits']===1,'attendance visit, identity, repeat and manual counts distinct');
    verify($r['daily'][$today]===3 && !array_key_exists('time_out',$r['records'][0]),'attendance daily totals retain all arrivals without departure fields');
    [$code]=http('reports-data?'.http_build_query(['report'=>'attendance']+$search+['period'=>'today','status'=>'completed']),'GET',null,$staff);verify($code===422,'retired departure status filter is refused');
    [$code,$attendance]=http('attendance-data?date='.$today,'GET',null,$staff);verify($attendance['summary']['visits']===$r['current']['attendance_today']['visits'],'report current attendance agrees with Phase 5 endpoint');
    $r=report($staff,'attendance',$search+['period'=>'custom','from'=>$yesterday,'to'=>$yesterday]);verify(count($r['records'])===1,'Philippine midnight boundary excludes next day');
    $r=report($staff,'users',['period'=>'month']);verify(!isset($r['records'][0]['email'],$r['records'][0]['full_name'],$r['records'][0]['password_hash']),'user statistics are grouped without confidential identities');
    $pdo->prepare('UPDATE users SET account_status="pending" WHERE id=:id')->execute(['id'=>$a['id']]);$r=report($staff,'users',['status'=>'pending']);verify($r['summary']['Accounts']>=1,'account approval statistics reflect database status');
    foreach(['loans','returns','requests','reservations','attendance','users'] as $type)verify(!report($staff,$type,['period'=>'custom','from'=>'2000-01-01','to'=>'2000-01-01'])['records'],'empty '.$type.' range returns zero rows');
    $r=report($staff,'inventory',['period'=>'custom','from'=>'2000-01-01','to'=>'2000-01-01']);verify(count($r['records'])>0 && str_contains($r['scope'],'does not apply'),'inventory remains explicitly current snapshot');
    [$code,$monthly]=http('book-monthly-stats','GET',null,$staff);verify($code===200 && !isset($monthly['total'],$monthly['available']) && count($monthly['checkouts'])===6,'monthly stats publish actual circulation, not reconstructed physical inventory');
    $pdo->prepare('INSERT INTO borrow_requests(user_id,book_id,status,requested_at,approved_at,due_date)VALUES(:user,:book,"approved",:requested,NOW(),DATE_ADD(NOW(),INTERVAL 1 DAY))')
        ->execute(['user'=>$b['id'],'book'=>$book,'requested'=>(new DateTimeImmutable('first day of last month'))->format('Y-m-d').' 09:00:00']);
    $legacyId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE books SET borrowed_copies=1 WHERE id=:id')->execute(['id'=>$book]);
    $r=report($staff,'loans',$search+['period'=>'today']);
    $legacyRows=array_values(array_filter($r['records'],static fn($row)=>$row['record_key']==='borrow:'.$legacyId));
    verify(count($legacyRows)===1 && $legacyRows[0]['legacy_checkout'],'documented legacy approved loan uses preserved original checkout date');
    $r=report($staff,'requests',$search+['period'=>'today']);verify(!array_filter($r['records'],static fn($row)=>(int)$row['id']===$legacyId),'request creation and checkout event dates stay distinct');
    $pdo->prepare('UPDATE books SET borrowed_copies=0 WHERE id=:id')->execute(['id'=>$book]);
    $pdo->prepare('UPDATE books SET borrowed_copies=1 WHERE id=:id')->execute(['id'=>$reservedBook]);
    verify(!report($staff,'inventory')['current']['inventory_matches_loans'],'per-title mismatches cannot cancel out in aggregate totals');
    $pdo->prepare('UPDATE books SET borrowed_copies=1 WHERE id=:id')->execute(['id'=>$book]);
    $pdo->prepare('UPDATE books SET borrowed_copies=0 WHERE id=:id')->execute(['id'=>$reservedBook]);
    $pdo->prepare('INSERT INTO borrow_requests(user_id,book_id,status)VALUES(:user,:book,"checked_out")')->execute(['user'=>$b['id'],'book'=>$book]);
    $undatedId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE books SET borrowed_copies=2 WHERE id=:id')->execute(['id'=>$book]);
    $readOnlyBefore=localRowSnapshot($pdo);$r=report($staff,'loans',$search+['period'=>'today']);
    verify($r['current']['undated_loan_records']>=1 && !array_filter($r['records'],static fn($row)=>$row['source']==='borrow' && (int)$row['id']===$undatedId),'missing checkout timestamp is flagged, not fabricated as an actual checkout event');
    verify(localRowSnapshot($pdo)===$readOnlyBefore,'report generation never repairs or rewrites source records');
    $r=report($staff,'attendance',$search+['period'=>'custom','from'=>$yesterday,'to'=>$today]);
    verify($r['summary']['Total visits']===4 && $r['summary']['Unique registered people']===1 && $r['summary']['Days with visits']===2,'multi-day attendance distinguishes distinct students from student-day visits');
    $pdo->prepare('UPDATE users SET role="teacher" WHERE id=:id')->execute(['id'=>$staffUser['id']]);
    [$code]=http('reports-data','GET',null,$staff);verify($code===403,'stale librarian role cannot view reports');
} catch(Throwable $e){fwrite(STDERR,$e instanceof PDOException?"Local reporting fixture database operation failed.\n":$e->getMessage().PHP_EOL);$failed=true;}
finally {
    if($pdo->inTransaction())$pdo->rollBack();$pdo->beginTransaction();
    foreach($visits as $id)$pdo->prepare('DELETE FROM attendance WHERE id=:id AND student_name=:name')->execute(['id'=>$id,'name'=>$prefix.' Visitor']);
    foreach($books as $id){foreach(['borrow_requests','reservations'] as $table)$pdo->prepare('DELETE FROM '.$table.' WHERE book_id=:id')->execute(['id'=>$id]);$pdo->prepare('DELETE FROM books WHERE id=:id')->execute(['id'=>$id]);}
    foreach($users as $id){$pdo->prepare('DELETE FROM sms_logs WHERE user_id=:id')->execute(['id'=>$id]);$pdo->prepare('DELETE FROM users WHERE id=:id')->execute(['id'=>$id]);}
    foreach($admins as $id)$pdo->prepare('DELETE FROM admins WHERE id=:id')->execute(['id'=>$id]);
    if(localRowSnapshot($pdo)!==$baseline){$pdo->rollBack();throw new RuntimeException('Original rows changed; reporting fixture cleanup rolled back.');}
    $pdo->commit();foreach($cookies as $file)if(is_file($file))unlink($file);
    verify(localRowSnapshot($pdo)===$baseline,'all original records and settings unchanged after reporting fixture cleanup');
}
echo 'Completed '.$checks." Phase 6A API checks.\n";exit(isset($failed)?1:0);
