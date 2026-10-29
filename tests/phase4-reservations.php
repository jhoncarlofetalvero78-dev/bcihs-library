<?php
require_once __DIR__ . '/../scripts/local-cli.php';
require_once __DIR__ . '/../includes/borrowing.php';
require_once __DIR__ . '/../scripts/local-backup.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8888/bcihs-library', '/') . '/';
$host = parse_url($base, PHP_URL_HOST);
if (!in_array($host, ['127.0.0.1','localhost','::1'], true) || !extension_loaded('curl')) {
    fwrite(STDERR, "Use a localhost URL and PHP cURL.\n"); exit(1);
}
$prefix = 'PHASE4-TEST-' . bin2hex(random_bytes(6));
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
function race(int $book,array $jobs): array {
    global $pdo,$dsn,$options,$base;
    $observer=new PDO($dsn,DB_USER,DB_PASS,$options);
    try {$observer->query('SELECT 1 FROM performance_schema.data_locks LIMIT 1');$inspect=true;} catch(PDOException $e){$inspect=false;}
    $pdo->beginTransaction(); $lock=$pdo->prepare('SELECT id FROM books WHERE id=:id FOR UPDATE');$lock->execute(['id'=>$book]);
    $multi=curl_multi_init();$handles=[];
    try {
        foreach($jobs as [$endpoint,$payload,$session]){
            $curl=curl_init($base.'backend/'.$endpoint.'.php');
            curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_TIMEOUT=>10,CURLOPT_COOKIEFILE=>$session['cookie'],CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.$session['token']]]);
            curl_multi_add_handle($multi,$curl);$handles[]=$curl;
        }
        $deadline=microtime(true)+4;$waiting=0;
        do {
            curl_multi_exec($multi,$running);
            if($inspect){$q=$observer->prepare('SELECT COUNT(*) FROM performance_schema.data_locks WHERE OBJECT_SCHEMA="bcihs_library" AND OBJECT_NAME="books" AND LOCK_STATUS="WAITING" AND LOCK_DATA=:id');$q->execute(['id'=>(string)$book]);$waiting=(int)$q->fetchColumn();}
            if($waiting<count($jobs))curl_multi_select($multi,0.05);
        }while($inspect && $waiting<count($jobs) && $running && microtime(true)<$deadline);
        if($inspect)verify($waiting>=count($jobs),'independent sessions concurrently wait on the same book row');
        else echo "NOTE: lock inspection unavailable; concurrent HTTP requests still tested.\n";
        $pdo->commit();
        do{curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,0.1);}while($running);
        return array_map(static fn($curl)=>[curl_getinfo($curl,CURLINFO_RESPONSE_CODE),json_decode(curl_multi_getcontent($curl),true)],$handles);
    }finally{if($pdo->inTransaction())$pdo->rollBack();foreach($handles as $curl){curl_multi_remove_handle($multi,$curl);curl_close($curl);}curl_multi_close($multi);}
}
function reserve(int $book, array $user, string $pickup, int $expected = 200): ?int {
    [$status, $data] = http('reservation-process', 'POST', ['book_id'=>$book,'pickup_at'=>$pickup], $user['session']);
    verify($status === $expected, 'reserve returns '.$expected);
    return isset($data['id']) ? (int)$data['id'] : null;
}
function settings(array $staff, $daily, $slot, int $expected = 200): void {
    [$status] = http('reservation-settings','POST',['daily_limit'=>$daily,'slot_limit'=>$slot],$staff);
    verify($status === $expected, 'capacity configuration returns '.$expected);
}
function resRow(int $id): array {
    global $pdo; $q=$pdo->prepare('SELECT * FROM reservations WHERE id=:id');$q->execute(['id'=>$id]);return $q->fetch();
}
function reschedule(int $id, string $pickup, array $session, int $expected=200): void {
    [$status]=http('reservation-action','POST',['id'=>$id,'action'=>'schedule','pickup_at'=>$pickup],$session);
    verify($status===$expected,'reschedule returns '.$expected);
}
function cancelFixtureWaitlists(array $staff): void {
    global $pdo,$books;
    foreach($books as $book){$q=$pdo->prepare('SELECT id FROM reservations WHERE book_id=:book AND status IN ("waiting","ready")');$q->execute(['book'=>$book]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)reservationAction((int)$id,'cancel',$staff);
    }
}
function capacityRace(array $jobs): array {
    global $pdo,$base;
    $pdo->beginTransaction();$pdo->query('SELECT id FROM reservation_settings WHERE id=1 FOR UPDATE');
    $multi=curl_multi_init();$handles=[];
    try {
        foreach($jobs as [$book,$pickup,$session]){
            $curl=curl_init($base.'backend/reservation-process.php');
            curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['book_id'=>$book,'pickup_at'=>$pickup]),CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_TIMEOUT=>12,CURLOPT_COOKIEFILE=>$session['cookie'],CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.$session['token']]]);
            curl_multi_add_handle($multi,$curl);$handles[]=$curl;
        }
        $deadline=microtime(true)+4;$waiting=0;
        do {
            curl_multi_exec($multi,$running);
            $waiting=(int)$pdo->query('SELECT COUNT(*) FROM performance_schema.data_locks WHERE OBJECT_SCHEMA="bcihs_library" AND OBJECT_NAME="reservation_settings" AND LOCK_STATUS="WAITING"')->fetchColumn();
            if($waiting<2)curl_multi_select($multi,.05);
        }while($waiting<2 && $running && microtime(true)<$deadline);
        verify($waiting>=2,'independent submissions concurrently wait for the shared capacity row');
        $pdo->commit();
        do{curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,.1);}while($running);
        return array_map(static fn($h)=>[curl_getinfo($h,CURLINFO_RESPONSE_CODE),json_decode(curl_multi_getcontent($h),true)],$handles);
    }finally{if($pdo->inTransaction())$pdo->rollBack();foreach($handles as $h){curl_multi_remove_handle($multi,$h);curl_close($h);}curl_multi_close($multi);}
}
$baseline=localRowSnapshot($pdo);
$originalSettings=$pdo->query('SELECT daily_limit,slot_limit FROM reservation_settings WHERE id=1')->fetch();
try {
    // Temporarily testing global quotas is safe only without existing active future schedules.
    if((int)$pdo->query('SELECT COUNT(*) FROM reservations WHERE status IN ("waiting","ready") AND pickup_at>=CURDATE()')->fetchColumn()>0) {
        throw new RuntimeException('Existing future schedules found. Do not change their policy; run acceptance tests in a separate local test database.');
    }
    foreach(['reservation-process','reservation-action','reservation-settings','reservation-availability','my-reservations-data'] as $endpoint){
        [$status]=http($endpoint,str_ends_with($endpoint,'process')||str_ends_with($endpoint,'action')?'POST':'GET',[]);
        verify($status===401,'guest blocked: '.$endpoint);
    }
    $pdo->prepare('INSERT INTO admins(username,password_hash)VALUES(:name,:hash)')->execute(['name'=>$prefix,'hash'=>password_hash($password,PASSWORD_DEFAULT)]);
    $admins[]=(int)$pdo->lastInsertId();$staff=signIn($prefix);$staff2=signIn($prefix);
    $a=newUser();$b=newUser();$c=newUser();$librarian=newUser('librarian');
    settings($staff,null,null);
    $pdo->prepare('UPDATE users SET account_status="pending" WHERE id=:id')->execute(['id'=>$c['id']]);
    foreach(['reservation-availability','my-reservations-data','reservation-settings'] as $endpoint){
        [$status]=http($endpoint,'GET',null,$c['session']);verify($status===401,'unapproved account blocked: '.$endpoint);
    }
    $pdo->prepare('UPDATE users SET account_status="accepted" WHERE id=:id')->execute(['id'=>$c['id']]);
    [$status]=http('reservation-settings','GET',null,$a['session']);verify($status===403,'student cannot read staff settings endpoint');
    settings($a['session'],1,1,403);settings($librarian['session'],null,null);
    [$status]=http('reservation-settings','POST',['daily_limit'=>1,'slot_limit'=>1],$staff,false);verify($status===403,'settings require CSRF');
    foreach([0,-1,true,1.5,65536,[],"1 OR 1=1"] as $bad)settings($staff,$bad,null,422);
    [$status]=http('reservation-process','GET',null,$a['session']);verify($status===405,'GET cannot reserve');
    [$status]=http('reservation-availability?month=2026-13','GET',null,$a['session']);
    verify($status===422,'invalid calendar month rejected');
    [$status]=http('reservation-availability?month%5B%5D=2026-10','GET',null,$a['session']);verify($status===422,'array month rejected');
    $future=new DateTimeImmutable('+2 days');while((int)$future->format('N')>5)$future=$future->modify('+1 day');
    $day=$future->format('Y-m-d');$pickup=$day.'T09:00';
    $next=$future->modify('+1 day');while((int)$next->format('N')>5)$next=$next->modify('+1 day');$day2=$next->format('Y-m-d');
    $empty=newBook(0);$otherEmpty=newBook(0);
    foreach([null,[],"2026-02-30T09:00",(new DateTimeImmutable('yesterday'))->format('Y-m-d').'T09:00',
        (new DateTimeImmutable('next Sunday'))->format('Y-m-d').'T09:00',$day.'T06:30',$day.'T16:30',$day.'T09:15',$pickup.':00',$pickup."\0"] as $bad){
        [$status]=http('reservation-process','POST',['book_id'=>$empty,'pickup_at'=>$bad],$a['session']);verify($status===422,'invalid pickup rejected');
    }
    [$status]=http('reservation-process','POST',['book_id'=>$empty,'pickup_at'=>$pickup],$a['session'],false);verify($status===403,'reservation requires CSRF');
    $boundary=reserve(newBook(0),$c,$day.'T16:00');reservationAction($boundary,'cancel',$staff);
    $id=reserve($empty,$a,$pickup);stock($empty,0,0,$staff);
    reserve($empty,$a,$day2.'T09:00',409);
    reservationAction($id,'ready',$staff,409);reservationAction($id,'fulfill',$staff,409);
    reservationAction($id,'ready',$a['session'],403);reservationAction($id,'cancel',$b['session'],403);
    reschedule($id,$day2.'T09:00',$b['session'],403);
    reschedule($id,$day2.'T09:00',$a['session']);reschedule($id,$pickup,$staff);
    verify(resRow($id)['status']==='waiting' && resRow($id)['ready_at']===null,'scheduling never promises unavailable stock');
    reserve(newBook(),$b,$pickup,409);

    $book=newBook();$loan=requestLoan($book,$a);action($loan,'approve',$staff);action($loan,'checkout',$staff);
    $one=reserve($book,$b,$pickup);$two=reserve($book,$c,$pickup);stock($book,1,0,$staff);
    action($loan,'return',$staff);stock($book,0,0,$staff);
    $out=race($book,[['reservation-action',['id'=>$one,'action'=>'ready'],$staff],['reservation-action',['id'=>$two,'action'=>'ready'],$staff2]]);
    $codes=array_column($out,0);sort($codes);verify($codes===[200,409],'two ready actions allocate only the last physical copy');stock($book,0,1,$staff);
    $winner=$out[0][0]===200?$one:$two;$loser=$winner===$one?$two:$one;
    $winnerStudent=$winner===$one?$b:$c;
    [, $ownHistory]=http('my-reservations-data','GET',null,$winnerStudent['session']);
    verify(count(array_filter($ownHistory['reservations'],static fn($r)=>(int)$r['id']===$winner && $r['status']==='ready'))===1,'student history shows Ready without an active loan');
    reschedule($winner,$day2.'T09:00',$staff,409);
    reservationAction($loser,'fulfill',$staff,409);
    $out=race($book,[['reservation-action',['id'=>$winner,'action'=>'fulfill'],$staff],['reservation-action',['id'=>$winner,'action'=>'fulfill'],$staff2]]);
    $codes=array_column($out,0);sort($codes);verify($codes===[200,409],'duplicate concurrent fulfillment increments inventory once');stock($book,1,0,$staff);
    $row=resRow($winner);
    [, $ownHistory]=http('my-reservations-data','GET',null,$winnerStudent['session']);
    verify(count(array_filter($ownHistory['reservations'],static fn($r)=>(int)$r['id']===$winner && $r['status']==='fulfilled' && $r['fulfilled_at']!==null))===1,'student reservation history records actual fulfillment');
    [, $loanHistory]=http('borrowing-history-data','GET',null,$winnerStudent['session']);
    verify(count(array_filter($loanHistory['requests'],static fn($r)=>(int)$r['id']===$winner && $r['status']==='checked_out'))===1,'reservation fulfillment appears once as an outstanding borrowing record');
    reservationAction($winner,'cancel',$winnerStudent['session'],409);
    verify(strtotime($row['due_date'])-strtotime($row['fulfilled_at'])===14*86400 && abs(time()-strtotime($row['fulfilled_at']))<10,'loan starts at actual checkout with a 14-day due date');
    $out=race($book,[['reservation-action',['id'=>$winner,'action'=>'return'],$staff],['reservation-action',['id'=>$winner,'action'=>'return'],$staff2]]);
    $codes=array_column($out,0);sort($codes);verify($codes===[200,409],'duplicate concurrent return decrements inventory once');stock($book,0,0,$staff);
    [, $ownHistory]=http('my-reservations-data','GET',null,$winnerStudent['session']);
    verify(count(array_filter($ownHistory['reservations'],static fn($r)=>(int)$r['id']===$winner && $r['status']==='returned' && $r['returned_at']!==null))===1,'student reservation history retains its return date');
    $returned=resRow($winner)['returned_at'];reservationAction($winner,'return',$staff,409);verify(resRow($winner)['returned_at']===$returned,'repeated return retains original timestamp');
    reservationAction($loser,'ready',$staff);stock($book,0,1,$staff);reservationAction($loser,'cancel',($loser===$one?$b:$c)['session']);stock($book,0,0,$staff);
    reservationAction($loser,'cancel',$staff,409);reservationAction($loser,'fulfill',$staff,409);

    $holdBook=newBook();$request=requestLoan($holdBook,$a);$contender=requestLoan($holdBook,$b);action($request,'approve',$staff);
    $heldReservation=reserve($holdBook,$c,$pickup);reservationAction($heldReservation,'ready',$staff,409);reservationAction($heldReservation,'fulfill',$staff,409);
    action($request,'reject',$staff);
    $out=race($holdBook,[['reservation-action',['id'=>$heldReservation,'action'=>'ready'],$staff],['borrow-request-action',['id'=>$contender,'action'=>'approve'],$staff2]]);
    $codes=array_column($out,0);sort($codes);verify($codes===[200,409],'reservation and borrow-request approval cannot allocate the same last copy');stock($holdBook,0,1,$staff);
    if($out[0][0]===200)reservationAction($heldReservation,'cancel',$staff);else action($contender,'reject',$staff);
    stock($holdBook,0,0,$staff);

    cancelFixtureWaitlists($staff);
    $first=reserve($empty,$a,$pickup);$second=reserve($otherEmpty,$b,$pickup);
    settings($staff,null,1,409);verify($pdo->query('SELECT slot_limit FROM reservation_settings WHERE id=1')->fetchColumn()===null,'invalid quota reduction changes no setting');
    reservationAction($second,'cancel',$b['session']);settings($staff,null,1);
    reserve($otherEmpty,$b,$pickup,409);$second=reserve($otherEmpty,$b,$day.'T09:30');
    [$status,$availability]=http('reservation-availability?month='.substr($day,0,7),'GET',null,$a['session']);
    verify($status===200 && $availability['timezone']==='Asia/Manila' && $availability['slots'][$day.' 09:00:00']===1,'calendar reports full slot counts in Philippine time');
    verify(!isset($availability['users'],$availability['reservations'],$availability['user_id']),'availability exposes aggregate counts without patron identities');
    [$status,$ownAvailability]=http('reservation-availability?month='.substr($day,0,7).'&exclude_id='.$first,'GET',null,$a['session']);
    verify($status===200 && !isset($ownAvailability['slots'][$day.' 09:00:00']),'editing own schedule excludes its current allocation');
    [$status]=http('reservation-availability?month='.substr($day,0,7).'&exclude_id='.$first,'GET',null,$b['session']);verify($status===403,'cannot exclude another patron allocation');
    reschedule($first,$day.'T09:30',$a['session'],409);verify(resRow($first)['pickup_at']===$day.' 09:00:00','failed reschedule retains original capacity allocation');
    reschedule($first,$day.'T10:00',$a['session']);
    reservationAction($second,'cancel',$b['session']);$second=reserve($otherEmpty,$b,$day.'T09:30');
    cancelFixtureWaitlists($staff);settings($staff,1,null);
    $first=reserve($empty,$a,$pickup);reserve($otherEmpty,$b,$day.'T09:30',409);$second=reserve($otherEmpty,$b,$day2.'T09:00');
    cancelFixtureWaitlists($staff);settings($staff,null,1);
    $out=capacityRace([[$empty,$day.'T14:00',$a['session']],[$otherEmpty,$day.'T14:00',$b['session']]]);
    $codes=array_column($out,0);sort($codes);verify($codes===[200,409],'different-book concurrent submissions cannot overbook the last slot');
    cancelFixtureWaitlists($staff);settings($staff,1,null);
    $out=capacityRace([[$empty,$day.'T14:00',$a['session']],[$otherEmpty,$day.'T15:00',$b['session']]]);
    $codes=array_column($out,0);sort($codes);verify($codes===[200,409],'different-slot concurrent submissions cannot overbook the last daily place');
    cancelFixtureWaitlists($staff);settings($staff,null,null);

    $q=$pdo->prepare('SELECT email FROM users WHERE id=:id');$q->execute(['id'=>$a['id']]);
    $samePatron=signIn($q->fetchColumn());
    $out=capacityRace([[$empty,$pickup,$a['session']],[$empty,$pickup,$samePatron]]);
    $codes=array_column($out,0);sort($codes);verify($codes===[200,409],'independent sessions for one patron cannot create duplicate active reservations');
    $failure=$out[0][0]===409?$out[0][1]:$out[1][1];
    verify(str_contains($failure['error']??'','already have an active'),'duplicate race is rejected by identity/title protection rather than a quota');
    cancelFixtureWaitlists($staff);
    [, $history]=http('my-reservations-data','GET',null,$b['session']);
    verify(count(array_filter($history['reservations'],static fn($r)=>$r['status']==='cancelled'))>0,'student retains cancelled reservation history');
    foreach($history['reservations'] as $historyRow)verify((int)resRow((int)$historyRow['id'])['user_id']===$b['id'],'reservation history contains only the signed-in patron');
    [, $tx]=http('transactions-data','GET',null,$staff);
    $rows=array_filter($tx['requests'],static fn($r)=>$r['source']==='reservation' && (int)$r['id']===$winner);
    verify(count($rows)===1 && array_values($rows)[0]['status']==='returned','Transactions retains the returned reservation exactly once');
    [, $report]=http('borrowing-report-data','GET',null,$staff);
    verify(count(array_filter($report['borrowings'],static fn($r)=>(int)$r['book_id']===$book))===2,'report includes one original loan and one reservation checkout without double-counting');
    [, $calendar]=http('reservations-data','GET',null,$staff);
    $selected=array_values(array_filter($calendar['reservations'],static fn($r)=>(int)$r['id']===$winner))[0];
    verify($selected['pickup_at']===$pickup.':00' || $selected['pickup_at']===str_replace('T',' ',$pickup).':00','admin data preserves preferred pickup schedule');
    verify(isset($selected['held_copies'],$selected['requestable_copies']),'admin calendar exposes stock separately from schedule');
    $q=$pdo->prepare('SELECT COUNT(*) FROM sms_logs WHERE user_id IN ('.implode(',',array_fill(0,count($users),'?')).') AND status="sent"');$q->execute($users);verify((int)$q->fetchColumn()===0,'no fixture SMS was sent');
}catch(Throwable $exception){fwrite(STDERR,$exception instanceof PDOException?"Local fixture database operation failed.\n":$exception->getMessage().PHP_EOL);$failed=true;}
finally{
    if($pdo->inTransaction())$pdo->rollBack();
    foreach($books as $id){foreach(['borrow_requests','reservations'] as $table)$pdo->prepare('DELETE FROM '.$table.' WHERE book_id=:id')->execute(['id'=>$id]);$pdo->prepare('DELETE FROM books WHERE id=:id')->execute(['id'=>$id]);}
    foreach($users as $id){$pdo->prepare('DELETE FROM sms_logs WHERE user_id=:id')->execute(['id'=>$id]);$pdo->prepare('DELETE FROM users WHERE id=:id')->execute(['id'=>$id]);}
    foreach($admins as $id)$pdo->prepare('DELETE FROM admins WHERE id=:id')->execute(['id'=>$id]);
    foreach($cookies as $file)if(is_file($file))unlink($file);
    $pdo->prepare('UPDATE reservation_settings SET daily_limit=:daily,slot_limit=:slot WHERE id=1')->execute(['daily'=>$originalSettings['daily_limit'],'slot'=>$originalSettings['slot_limit']]);
    verify(localRowSnapshot($pdo)===$baseline,'all original rows and capacity settings match after fixture cleanup');
}
echo 'Completed '.$checks." Phase 4 checks.\n";
exit(isset($failed)?1:0);
