<?php
require_once __DIR__ . '/../scripts/local-cli.php';
require_once __DIR__ . '/../includes/borrowing.php';
require_once __DIR__ . '/../scripts/local-backup.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8888/bcihs-library', '/') . '/';
$host = parse_url($base, PHP_URL_HOST);
if (!in_array($host, ['127.0.0.1','localhost','::1'], true) || !extension_loaded('curl')) {
    fwrite(STDERR, "Use a localhost URL and PHP cURL.\n"); exit(1);
}
$prefix = 'PHASE3B-TEST-' . bin2hex(random_bytes(6));
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
    $cookie = tempnam(sys_get_temp_dir(), 'bcihs-phase3b-'); $cookies[] = $cookie;
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

require_once __DIR__ . '/../includes/pickup-qr.php';
$backup=backupLocalDatabase($pdo,'/Users/macbookprom116512/.codex/backups/bcihs-library','/Applications/MAMP/Library/bin/mysql80/bin/mysqldump','phase3b-tests');
$baseline=localRowSnapshot($pdo);
function qrCheckout(array $staff,int $id,string $token,int $expected):void {
    [$code]=http('borrow-request-action','POST',['id'=>$id,'action'=>'checkout','pickup_token'=>$token],$staff);
    verify($code===$expected,'QR checkout returns '.$expected);
}
try {
    $originalBase=getenv('BCIHS_PUBLIC_BASE_URL');
    try {
        foreach(['https://library.example.edu/library'=>true,'http://192.0.2.10:8888/bcihs-library/'=>true,'javascript:alert(1)'=>false,'https://user:pass@example.edu/library'=>false,'https://library.example.edu/path?redirect=bad'=>false,'https://library.example.edu/#bad'=>false,''=>false] as $configured=>$expected) {
            putenv('BCIHS_PUBLIC_BASE_URL='.$configured);
            try {pickupBaseUrl();$valid=true;}catch(DomainException $error){$valid=false;}
            verify($valid===$expected,'public base URL validation accepts only configured HTTP(S) application URLs');
        }
    } finally {putenv($originalBase===false?'BCIHS_PUBLIC_BASE_URL':'BCIHS_PUBLIC_BASE_URL='.$originalBase);}
    $a=newUser();$b=newUser();$staffUser=newUser('librarian');$staff=$staffUser['session'];
    $pdo->prepare('INSERT INTO admins(username,password_hash)VALUES(:name,:hash)')->execute(['name'=>$prefix,'hash'=>password_hash($password,PASSWORD_DEFAULT)]);
    $admins[]=(int)$pdo->lastInsertId();$admin=signIn($prefix);
    $book=newBook(2);$id=requestLoan($book,$a);
    [$code,$listing]=http('my-pickup-requests-data','GET',null,$a['session']);verify($code===200 && !$listing['requests'],'pending request has no pickup listing');
    [$code]=http('pickup-qr-data','POST',['id'=>$id],$a['session']);verify($code===409,'pending request cannot obtain QR');
    foreach(['my-pickup-requests-data','pickup-verification-data?token='.str_repeat('a',64)] as $endpoint){[$code]=http($endpoint);verify($code===401,'anonymous listing/verification denied');}
    [$code]=http('pickup-qr-data','POST',['id'=>$id]);verify($code===401,'anonymous QR issuance denied');
    [$code]=http('pickup-qr-data','GET',null,$a['session']);verify($code===405,'QR issuance requires POST');
    [$code]=http('pickup-qr-data','POST',['id'=>$id],$a['session'],false);verify($code===403,'QR issuance requires CSRF');
    action($id,'approve',$staff);stock($book,0,1,$staff);
    $q=$pdo->prepare('SELECT * FROM borrow_requests WHERE id=:id');$q->execute(['id'=>$id]);$row=$q->fetch();$token=$row['pickup_token'];
    verify((bool)preg_match('/^[a-f0-9]{64}$/D',$token),'approval generates unpredictable 256-bit reference');
    [$code,$qr]=http('pickup-qr-data','POST',['id'=>$id],$a['session']);verify($code===200 && str_ends_with($qr['url'],'/pickup-verification.html?token='.$token),'owner receives correct verification URL');
    verify(str_starts_with($qr['url'],pickupBaseUrl().'/') && !str_contains($qr['url'],$prefix),'configured URL contains no borrower personal data');
    [$code,$again]=http('pickup-qr-data','POST',['id'=>$id],$a['session']);verify($qr['url']===$again['url'],'normal reload retains the same QR');
    [$code]=http('pickup-qr-data','POST',['id'=>$id],$b['session']);verify($code===404,'another borrower cannot obtain QR');
    [$code]=http('pickup-qr-data','POST',['id'=>$id],$admin);verify($code===403,'administrator cannot issue owner QR through student API');
    [$code,$listing]=http('my-pickup-requests-data','GET',null,$a['session']);verify(count($listing['requests'])===1 && (int)$listing['requests'][0]['id']===$id,'owner sees eligible request');
    [$code,$listing]=http('my-pickup-requests-data','GET',null,$b['session']);verify(!$listing['requests'],'another borrower listing excludes owner request');
    [$code]=http('pickup-verification-data?token='.$token,'GET',null,$a['session']);verify($code===403,'borrower knowing token cannot read staff details');
    $beforeScan=localRowSnapshot($pdo);
    foreach([$staff,$admin] as $session){[$code,$details]=http('pickup-verification-data?token='.$token,'GET',null,$session);verify($code===200 && $details['eligible'] && (int)$details['request']['id']===$id && $details['request']['full_name']===$prefix.' student','authenticated staff sees exact borrower and book');}
    verify(localRowSnapshot($pdo)===$beforeScan,'repeated scans make no database changes');
    foreach(['bad',str_repeat('a',64),''] as $bad){[$code,$details]=http('pickup-verification-data?token='.$bad,'GET',null,$staff);verify(in_array($code,[404,422],true) && !isset($details['request']),'invalid QR never discloses another borrower');}
    [$code]=http('pickup-verification-data?token='.$token,'POST',[],$staff);verify($code===405,'verification is read-only GET');
    [$code]=http('borrow-request-action','GET',null,$staff);verify($code===405,'opening checkout endpoint never checks out a book');
    [$code]=http('borrow-request-action','POST',['id'=>$id,'action'=>'checkout','pickup_token'=>$token],$staff,false);verify($code===403,'checkout requires staff CSRF');
    qrCheckout($a['session'],$id,$token,403);qrCheckout($staff,$id,str_repeat('a',64),409);
    $otherBook=newBook();$other=requestLoan($otherBook,$b);action($other,'approve',$staff);
    qrCheckout($staff,$other,$token,409);
    $q->execute(['id'=>$other]);$otherToken=$q->fetch()['pickup_token'];verify($otherToken!==$token,'each request has a different QR');
    action($other,'reject',$staff);qrCheckout($staff,$other,$otherToken,409);
    [$code,$details]=http('pickup-verification-data?token='.$otherToken,'GET',null,$staff);verify(!$details['eligible'] && $details['request']['status']==='rejected','rejected QR cannot offer checkout');
    $pdo->prepare('UPDATE users SET account_status="pending" WHERE id=:id')->execute(['id'=>$a['id']]);
    [$code]=http('pickup-qr-data','POST',['id'=>$id],$a['session']);verify($code===401,'unapproved owner cannot obtain pickup QR');
    [$code,$unapproved]=http('pickup-verification-data?token='.$token,'GET',null,$staff);verify(!$unapproved['eligible'],'staff view disables pickup for an unapproved owner');
    qrCheckout($staff,$id,$token,403);
    $pdo->prepare('UPDATE users SET account_status="accepted" WHERE id=:id')->execute(['id'=>$a['id']]);
    // Backdate approval on our disposable request: due date must start at handover.
    $pdo->prepare('UPDATE borrow_requests SET approved_at=DATE_SUB(NOW(),INTERVAL 3 DAY) WHERE id=:id')->execute(['id'=>$id]);
    $dateBefore=time();qrCheckout($staff,$id,$token,200);$dateAfter=time();
    $q->execute(['id'=>$id]);$checked=$q->fetch();
    verify($checked['status']==='checked_out' && strtotime($checked['checked_out_at'])>=$dateBefore-2 && strtotime($checked['checked_out_at'])<=$dateAfter+2,'checkout records actual handover time');
    verify(strtotime($checked['due_date'])-strtotime($checked['checked_out_at'])===14*86400,'due date is 14 days from actual checkout');
    stock($book,1,0,$staff);
    qrCheckout($admin,$id,$token,409);stock($book,1,0,$staff);
    [$code,$details]=http('pickup-verification-data?token='.$token,'GET',null,$staff);verify($code===200 && !$details['eligible'] && $details['request']['status']==='checked_out','used QR reports status but cannot repeat checkout');
    [$code]=http('pickup-qr-data','POST',['id'=>$id],$a['session']);verify($code===409,'used QR no longer offered to owner');
    [$code,$listing]=http('my-pickup-requests-data','GET',null,$a['session']);verify(!$listing['requests'],'checked-out request disappears from pickup list');
    foreach(['borrowing-history-data'=>$a['session'],'transactions-data'=>$staff,'dashboard-data'=>$staff,'reports-data?report=loans'=>$staff] as $endpoint=>$session){
        [$code,$report]=http($endpoint,'GET',null,$session);verify($code===200,'existing '.$endpoint.' remains accessible after QR checkout');
        if($endpoint==='borrowing-history-data')verify(count(array_filter($report['requests'],static fn($r)=>(int)$r['id']===$id && $r['status']==='checked_out'))===1,'student history contains exactly one checkout');
    }
    [$code,$transactions]=http('transactions-data','GET',null,$staff);
    verify(count(array_filter($transactions['requests'],static fn($r)=>(int)$r['id']===$id && $r['source']==='borrow' && $r['status']==='checked_out'))===1,'Transactions contains exactly one checked-out request');
    [$code,$loans]=http('reports-data?report=loans','GET',null,$staff);
    verify(count(array_filter($loans['records'],static fn($r)=>(int)$r['id']===$id && $r['source']==='borrow'))===1,'Reports contains exactly one QR loan');
    [$code,$dashboard]=http('dashboard-data','GET',null,$staff);
    verify($dashboard['current']===$loans['current'],'Dashboard and Reports current totals agree after QR handover');
    [$code,$catalog]=http('books-data','GET',null,$staff);
    verify(array_sum(array_column($catalog['books'],'borrowed_copies'))===$dashboard['current']['borrowed_copies'],'Dashboard borrowed count agrees with actual catalog');
    action($id,'return',$staff);stock($book,0,0,$staff);qrCheckout($staff,$id,$token,409);
    [$code,$details]=http('pickup-verification-data?token='.$token,'GET',null,$staff);verify(!$details['eligible'] && $details['request']['status']==='returned','returned QR remains unusable');
    // An awaiting request approved before migration can obtain a stable token lazily.
    $legacy=requestLoan($book,$a);$pdo->prepare('UPDATE borrow_requests SET status="awaiting_pickup",approved_at=NOW() WHERE id=:id')->execute(['id'=>$legacy]);
    [$code,$legacyQr]=http('pickup-qr-data','POST',['id'=>$legacy],$a['session']);verify($code===200 && $legacyQr['url']!==$qr['url'],'legacy awaiting request obtains its own stable token');
    action($legacy,'checkout',$staff);stock($book,1,0,$staff);action($legacy,'return',$staff);stock($book,0,0,$staff);
    $raceBook=newBook();$race=requestLoan($raceBook,$a);action($race,'approve',$staff);$q=$pdo->prepare('SELECT pickup_token FROM borrow_requests WHERE id=:id');$q->execute(['id'=>$race]);$raceToken=$q->fetchColumn();
    $multi=curl_multi_init();$handles=[];
    foreach([$staff,$admin] as $session){
        $curl=curl_init($base.'backend/borrow-request-action.php');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$session['cookie'],CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.$session['token']],CURLOPT_POSTFIELDS=>json_encode(['id'=>$race,'action'=>'checkout','pickup_token'=>$raceToken])]);
        curl_multi_add_handle($multi,$curl);$handles[]=$curl;
    }
    do {curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,1);} while($running);
    $codes=[];foreach($handles as $curl){$codes[]=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_multi_remove_handle($multi,$curl);curl_close($curl);}curl_multi_close($multi);sort($codes);
    verify($codes===[200,409],'concurrent staff scans produce only one checkout');stock($raceBook,1,0,$staff);action($race,'return',$staff);
    foreach([['identifier'=>$prefix,'password'=>$password,'pickup'=>$token],['identifier'=>'wrong','password'=>'wrong','pickup'=>$token],['identifier'=>$prefix,'password'=>$password,'pickup'=>'https://evil.invalid/']] as $n=>$payload){
        $curl=curl_init($base.'backend/login-process.php');curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_POSTFIELDS=>$payload]);$response=curl_exec($curl);curl_close($curl);
        preg_match('/^Location: (.+)$/mi',$response,$location);$location=trim($location[1]??'');
        if($n===0)verify($location==='../pickup-verification.html?token='.$token,'staff login returns to the exact QR without an arbitrary redirect');
        elseif($n===1)verify(str_starts_with($location,'../admin-login.html?pickup='.$token.'&error='),'failed login preserves the QR on the staff sign-in form');
        else verify($location==='../admin-dashboard.html','malicious external return destination is ignored');
    }
    $pdo->prepare('UPDATE users SET role="teacher" WHERE id=:id')->execute(['id'=>$staffUser['id']]);
    [$code]=http('pickup-verification-data?token='.$token,'GET',null,$staff);verify($code===403,'stale librarian role cannot verify pickup');
    $q=$pdo->prepare('SELECT COUNT(*) FROM sms_logs WHERE user_id IN ('.implode(',',array_fill(0,count($users),'?')).') AND status="sent"');$q->execute($users);verify((int)$q->fetchColumn()===0,'QR fixture workflow sends no SMS');
} catch(Throwable $e){fwrite(STDERR,$e instanceof PDOException?"Local QR fixture operation failed.\n":$e->getMessage().PHP_EOL);$failed=true;}
finally {
    if($pdo->inTransaction())$pdo->rollBack();$pdo->beginTransaction();
    foreach($books as $id){foreach(['borrow_requests','reservations'] as $table)$pdo->prepare('DELETE FROM '.$table.' WHERE book_id=:id')->execute(['id'=>$id]);$pdo->prepare('DELETE FROM books WHERE id=:id')->execute(['id'=>$id]);}
    foreach($users as $id){$pdo->prepare('DELETE FROM sms_logs WHERE user_id=:id')->execute(['id'=>$id]);$pdo->prepare('DELETE FROM users WHERE id=:id')->execute(['id'=>$id]);}
    foreach($admins as $id)$pdo->prepare('DELETE FROM admins WHERE id=:id')->execute(['id'=>$id]);
    if(localRowSnapshot($pdo)!==$baseline){$pdo->rollBack();throw new RuntimeException('Original data mismatch; cleanup rolled back.');}
    $pdo->commit();foreach($cookies as $file)if(is_file($file))unlink($file);
    verify(localRowSnapshot($pdo)===$baseline,'original rows, inventory and settings unchanged after QR fixture cleanup');
}
echo 'Completed '.$checks." Phase 3B API checks.\n";exit(isset($failed)?1:0);
