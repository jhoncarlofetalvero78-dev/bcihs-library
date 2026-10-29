<?php
require_once __DIR__ . '/../scripts/local-cli.php';
require_once __DIR__ . '/../includes/borrowing.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8888/bcihs-library', '/') . '/';
$host = parse_url($base, PHP_URL_HOST);
if (!in_array($host, ['127.0.0.1','localhost','::1'], true) || !extension_loaded('curl')) {
    fwrite(STDERR, "Use a localhost URL and PHP cURL.\n"); exit(1);
}
$prefix = 'PHASE2B-TEST-' . bin2hex(random_bytes(6));
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
    $curl = curl_init($base . 'backend/' . $endpoint . '.php');
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
function loan(int $id): array {
    global $pdo; $stmt=$pdo->prepare('SELECT * FROM borrow_requests WHERE id=:id'); $stmt->execute(['id'=>$id]); return $stmt->fetch();
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
function fingerprintDatabase(): array {
    global $pdo; $result=[];
    foreach(['admins','users','books','borrow_requests','reservations','sms_logs','attendance','announcements'] as $table){
        $result[$table]=hash('sha256',json_encode($pdo->query('SELECT * FROM `'.$table.'` ORDER BY id')->fetchAll()));
    } return $result;
}
$baseline=fingerprintDatabase();
try {
    foreach(['borrow-requests-data','transactions-data','borrow-request-process','borrow-request-action','borrow-request-add','reservation-process','reservation-action','my-requests-data','borrowing-history-data'] as $endpoint){
        [$status,$data]=http($endpoint,str_ends_with($endpoint,'data')?'GET':'POST',[]);
        verify($status===401 && isset($data['error']),'guest rejected: '.$endpoint);
    }
    $pdo->prepare('INSERT INTO admins(username,password_hash)VALUES(:name,:hash)')->execute(['name'=>$prefix,'hash'=>password_hash($password,PASSWORD_DEFAULT)]);
    $admins[]=(int)$pdo->lastInsertId(); $staff=signIn($prefix);$staff2=signIn($prefix);
    [, $beforeMonthly]=http('book-monthly-stats','GET',null,$staff);$baselineBorrowed=end($beforeMonthly['borrowed']);
    $a=newUser();$b=newUser();$c=newUser();$librarian=newUser('librarian');
    $book=newBook();
    [$status]=http('borrow-request-process','POST',['book_id'=>$book],$a['session'],false);verify($status===403,'student mutation requires CSRF');
    [$status]=http('borrow-request-action','POST',['id'=>1,'action'=>'approve'],$staff,false);verify($status===403,'staff mutation requires CSRF');
    [$status]=http('borrow-request-process','GET',null,$a['session']);verify($status===405,'GET cannot create a request');
    foreach([0,-1,true,'1abc',[],4294967296] as $bad){[$status]=http('borrow-request-process','POST',['book_id'=>$bad],$a['session']);verify($status===422,'invalid book identity refused');}
    [$status]=http('borrow-request-process','POST',['book_id'=>4294967295],$a['session']);verify($status===404,'missing title refused');
    [$status,$data]=http('borrow-request-process','POST',['book_id'=>$book,'user_id'=>$b['id'],'status'=>'checked_out','due_date'=>'1900-01-01','checked_out_at'=>'1900-01-01','borrowed_copies'=>50],$a['session']);
    verify($status===200,'request accepts only server-controlled lifecycle fields');$id=(int)$data['id'];$row=loan($id);
    verify((int)$row['user_id']===$a['id'] && $row['status']==='pending' && $row['approved_at']===null && $row['checked_out_at']===null && $row['due_date']===null,'request identity is the session user; no loan dates exist before checkout');
    stock($book,0,0,$staff);
    [$status]=http('borrow-request-process','POST',['book_id'=>$book],$a['session']);verify($status===409,'duplicate active request refused');
    $other=requestLoan($book,$b);
    action($id,'approve',$a['session'],403); action($id,'checkout',$staff,409); action($id,'return',$staff,409);
    action($id,'approve',$staff);stock($book,0,1,$staff);
    verify(loan($id)['due_date']===null && loan($id)['checked_out_at']===null,'approval starts neither checkout nor due date');
    action($id,'approve',$staff,409);action($id,'return',$staff,409);action($other,'approve',$staff,409);
    [$status]=http('book-save','POST',['id'=>$book,'total_copies'=>0],$staff);verify($status===422,'catalog cannot reduce total below pickup holds');
    [$status]=http('borrow-request-process','POST',['book_id'=>$book],$c['session']);verify($status===409,'held copies cannot be requested again');
    $pickup=new DateTimeImmutable('+2 days');while((int)$pickup->format('N')>5)$pickup=$pickup->modify('+1 day');$pickup=$pickup->format('Y-m-d').'T09:00';
    [$status,$data]=http('reservation-process','POST',['book_id'=>$book,'pickup_at'=>$pickup],$c['session']);verify($status===200,'patron can queue when the physical copy is held');$reserved=(int)$data['id'];
    reservationAction($reserved,'ready',$staff,409);reservationAction($reserved,'fulfill',$staff,409);
    action($other,'reject',$staff);action($other,'checkout',$staff,409);
    $pdo->prepare('UPDATE borrow_requests SET requested_at=DATE_SUB(NOW(),INTERVAL 4 DAY),approved_at=DATE_SUB(NOW(),INTERVAL 3 DAY) WHERE id=:id')->execute(['id'=>$id]);
    $approvedAt=loan($id)['approved_at'];action($id,'checkout',$staff);stock($book,1,0,$staff);
    $dates=$pdo->prepare('SELECT TIMESTAMPDIFF(SECOND,checked_out_at,due_date) AS duration, ABS(TIMESTAMPDIFF(SECOND,checked_out_at,NOW())) AS age FROM borrow_requests WHERE id=:id');$dates->execute(['id'=>$id]);$dates=$dates->fetch();
    verify((int)$dates['duration']===14*86400 && (int)$dates['age']<10 && loan($id)['approved_at']===$approvedAt,'due date starts at actual server checkout; approval time is retained');
    action($id,'checkout',$staff,409);action($id,'reject',$staff,409);
    action($id,'return',$staff);$returned=loan($id)['returned_at'];stock($book,0,0,$staff);action($id,'return',$staff,409);verify(loan($id)['returned_at']===$returned,'duplicate return does not rewrite its original timestamp');
    reservationAction($reserved,'ready',$staff);stock($book,0,1,$staff);reservationAction($reserved,'ready',$staff,409);
    [$status]=http('reservation-action','POST',['id'=>$reserved,'action'=>'cancel'],$a['session']);verify($status===403,'patron cannot cancel another patron reservation');
    [$status,$data]=http('reservation-process','POST',['book_id'=>$book,'pickup_at'=>$pickup],$b['session']);verify($status===200,'second patron queues behind a ready hold');$waiting=(int)$data['id'];
    reservationAction($waiting,'fulfill',$staff,409);reservationAction($reserved,'fulfill',$staff);stock($book,1,0,$staff);
    $q=$pdo->prepare('SELECT TIMESTAMPDIFF(SECOND,fulfilled_at,due_date) FROM reservations WHERE id=:id');$q->execute(['id'=>$reserved]);verify((int)$q->fetchColumn()===14*86400,'reserved-book due date starts at physical fulfillment');
    reservationAction($reserved,'fulfill',$staff,409);reservationAction($reserved,'return',$staff);reservationAction($reserved,'return',$staff,409);stock($book,0,0,$staff);
    reservationAction($waiting,'ready',$staff);stock($book,0,1,$staff);reservationAction($waiting,'cancel',$b['session']);stock($book,0,0,$staff);reservationAction($waiting,'cancel',$b['session'],409);

    $rejectedBook=newBook();$rejected=requestLoan($rejectedBook,$a);action($rejected,'approve',$staff);action($rejected,'reject',$staff);stock($rejectedBook,0,0,$staff);action($rejected,'checkout',$staff,409);
    $manualBook=newBook(2);[, $list]=http('borrow-requests-data','GET',null,$staff);$nonce=$list['manualCheckoutToken'];
    $manual=['borrower_name'=>$prefix.' Manual','book_id'=>$manualBook,'confirm_checkout'=>true,'checkout_token'=>$nonce];
    [$status]=http('borrow-request-add','POST',array_merge($manual,['confirm_checkout'=>false]),$staff);verify($status===422,'manual form requires explicit physical handover');
    foreach(['1900-01-01','2026-02-30','2099-01-01',"2026-10-27\0",[]] as $bad){[$status]=http('borrow-request-add','POST',array_merge($manual,['due_date'=>$bad]),$staff);verify($status===422,'manual due override validated on the server');}
    [$status,$data]=http('borrow-request-add','POST',$manual,$staff);verify($status===200,'manual handover records an immediate checkout');$manualId=(int)$data['id'];stock($manualBook,1,0,$staff);
    verify(loan($manualId)['status']==='checked_out' && loan($manualId)['checked_out_at']!==null,'manual row is explicitly checked out, not merely approved');
    [$status,$repeat]=http('borrow-request-add','POST',$manual,$staff);verify($status===200 && ($repeat['replayed']??false) && (int)$repeat['id']===$manualId,'retrying the same manual form returns the original loan');stock($manualBook,1,0,$staff);
    [$status]=http('borrow-request-add','POST',array_merge($manual,['borrower_name'=>'Different intent']),$staff);verify($status===409,'a consumed manual token cannot record a different handover');
    [, $list]=http('borrow-requests-data','GET',null,$staff);$override=(new DateTimeImmutable('+2 days'))->format('Y-m-d');
    [$status,$data]=http('borrow-request-add','POST',array_merge($manual,['checkout_token'=>$list['manualCheckoutToken'],'borrower_name'=>$prefix.' Manual override','due_date'=>$override]),$staff);verify($status===200,'existing staff due-date override remains supported');$manual2=(int)$data['id'];verify(loan($manual2)['due_date']===$override.' 23:59:59','manual override is a validated Philippine calendar date');stock($manualBook,2,0,$staff);
    action($manualId,'return',$staff);action($manual2,'return',$staff);stock($manualBook,0,0,$staff);

    $legacyBook=newBook();$pdo->prepare('UPDATE books SET borrowed_copies=1 WHERE id=:id')->execute(['id'=>$legacyBook]);
    $pdo->prepare('INSERT INTO borrow_requests(user_id,book_id,status,requested_at,approved_at,due_date) VALUES(:user,:book,"approved","2020-01-01 09:00:00","2020-01-02 09:00:00","2020-01-16 09:00:00")')->execute(['user'=>$a['id'],'book'=>$legacyBook]);$legacy=(int)$pdo->lastInsertId();$old=loan($legacy);
    stock($legacyBook,1,0,$staff);action($legacy,'return',$staff);$new=loan($legacy);
    verify($new['approved_at']===$old['approved_at'] && $new['due_date']===$old['due_date'] && $new['checked_out_at']===null,'legacy loan returns without rewriting its old approval, checkout or due-date data');stock($legacyBook,0,0,$staff);
    [$status]=http('user-delete','POST',['id'=>$a['id']],$staff);verify($status===409,'borrower history prevents cascading user deletion');
    [$status]=http('book-delete','POST',['id'=>$book],$staff);verify($status===409,'book history still prevents cascading book deletion');

    $raceBook=newBook();$one=requestLoan($raceBook,$a);$two=requestLoan($raceBook,$b);
    $out=race($raceBook,[['borrow-request-action',['id'=>$one,'action'=>'approve'],$staff],['borrow-request-action',['id'=>$two,'action'=>'approve'],$staff2]]);
    $codes=array_column($out,0);sort($codes);verify($codes===[200,409],'concurrent approvals allocate only one pickup hold');stock($raceBook,0,1,$staff);
    $winner=$out[0][0]===200?$one:$two;
    $out=race($raceBook,[['borrow-request-action',['id'=>$winner,'action'=>'checkout'],$staff],['borrow-request-action',['id'=>$winner,'action'=>'checkout'],$staff2]]);
    $codes=array_column($out,0);sort($codes);verify($codes===[200,409],'concurrent duplicate checkout increments inventory once');stock($raceBook,1,0,$staff);
    $out=race($raceBook,[['borrow-request-action',['id'=>$winner,'action'=>'return'],$staff],['borrow-request-action',['id'=>$winner,'action'=>'return'],$staff2]]);
    $codes=array_column($out,0);sort($codes);verify($codes===[200,409],'concurrent duplicate return decrements inventory once');stock($raceBook,0,0,$staff);

    $reservationRace=newBook();$reservationIds=[];
    foreach([$a,$b] as $student){$pdo->prepare('INSERT INTO reservations(user_id,book_id,status,pickup_at)VALUES(:user,:book,"waiting",:pickup)')->execute(['user'=>$student['id'],'book'=>$reservationRace,'pickup'=>str_replace('T',' ',$pickup).':00']);$reservationIds[]=(int)$pdo->lastInsertId();}
    $out=race($reservationRace,[['reservation-action',['id'=>$reservationIds[0],'action'=>'fulfill'],$staff],['reservation-action',['id'=>$reservationIds[1],'action'=>'fulfill'],$staff2]]);
    $codes=array_column($out,0);sort($codes);verify($codes===[200,409],'concurrent waiting-reservation checkouts cannot overborrow');stock($reservationRace,1,0,$staff);
    $fulfilled=$out[0][0]===200?$reservationIds[0]:$reservationIds[1];reservationAction($fulfilled,'return',$staff);stock($reservationRace,0,0,$staff);

    $libraryBook=newBook();$libraryRequest=requestLoan($libraryBook,$c);action($libraryRequest,'approve',$librarian['session']);
    $pdo->prepare('UPDATE users SET account_status="pending" WHERE id=:id')->execute(['id'=>$librarian['id']]);action($libraryRequest,'checkout',$librarian['session'],401);
    action($libraryRequest,'checkout',$staff);stock($libraryBook,1,0,$staff);
    $pdo->prepare('UPDATE users SET account_status="rejected" WHERE id=:id')->execute(['id'=>$c['id']]);
    [$status]=http('borrow-request-process','POST',['book_id'=>$book],$c['session']);verify($status===401,'revoked patron approval blocks new requests');
    action($libraryRequest,'return',$staff);stock($libraryBook,0,0,$staff);

    [, $tx]=http('transactions-data','GET',null,$staff);$selected=array_values(array_filter($tx['requests'],static fn($r)=>(int)$r['id']===$id && $r['source']==='borrow'))[0];
    verify($selected['status']==='returned' && $selected['borrowed_at']===loan($id)['checked_out_at'],'Transactions uses checkout time rather than approval time');
    verify(count(array_filter($tx['requests'],static fn($r)=>$r['source']==='reservation' && $r['status']==='returned'))>=2,'Transactions includes returned reservation loans');
    [, $history]=http('borrowing-history-data','GET',null,$a['session']);verify(count($history['requests'])>0,'student can read their real lifecycle history');
    [, $own]=http('my-requests-data','GET',null,$a['session']);verify(count($own['requests'])>0 && count(array_filter($own['requests'],static fn($r)=>(int)$r['user_id']!==$a['id']))===0,'my requests never expose another student identity');
    [, $report]=http('borrowing-report-data','GET',null,$staff);verify(count(array_filter($report['borrowings'],static fn($r)=>(int)$r['book_id']===$rejectedBook))===0,'loan report excludes never-checked-out rejected requests');
    [, $monthly]=http('book-monthly-stats','GET',null,$staff);verify(end($monthly['borrowed'])===$baselineBorrowed,'dashboard current-month chart excludes pickup approvals and returned books');
    [$status,$public]=http('public-books-data');verify($status===200 && is_array($public['books']),'public catalog API remains available');
    $q=$pdo->prepare('SELECT COUNT(*) FROM sms_logs WHERE user_id IN ('.implode(',',array_fill(0,count($users),'?')).') AND status="sent"');$q->execute($users);verify((int)$q->fetchColumn()===0,'test fixtures sent no SMS');
}catch(Throwable $exception){fwrite(STDERR,$exception instanceof PDOException?"Fixture database operation failed.\n":$exception->getMessage().PHP_EOL);$failed=true;}
finally{
    if($pdo->inTransaction())$pdo->rollBack();
    foreach($books as $id){foreach(['borrow_requests','reservations'] as $table){$pdo->prepare('DELETE FROM '.$table.' WHERE book_id=:id')->execute(['id'=>$id]);}$pdo->prepare('DELETE FROM books WHERE id=:id')->execute(['id'=>$id]);}
    foreach($users as $id){$pdo->prepare('DELETE FROM sms_logs WHERE user_id=:id')->execute(['id'=>$id]);$pdo->prepare('DELETE FROM users WHERE id=:id')->execute(['id'=>$id]);}
    foreach($admins as $id)$pdo->prepare('DELETE FROM admins WHERE id=:id')->execute(['id'=>$id]);
    foreach($cookies as $file)if(is_file($file))unlink($file);
    verify(fingerprintDatabase()===$baseline,'all original records remain unchanged after test fixture cleanup');
}
echo 'Completed '.$checks." Phase 2B checks.\n";
exit(isset($failed)?1:0);
