<?php
// Read-only checks against the separately owned, freshly seeded demo only.
if (PHP_SAPI !== 'cli') exit;
ini_set('display_errors', '0');
$workspace = $argv[1] ?? '';
$passwordFile = $argv[2] ?? '';
$base = 'http://127.0.0.1:8899';
$checks = 0; $cookies = [];
function demoCheck(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
}
function demoHttp(string $path, ?array $form = null, ?string $cookie = null): array {
    global $base;
    $c = curl_init($base . '/' . $path);
    $options = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15, CURLOPT_FOLLOWLOCATION=>false];
    if ($form !== null) { $options[CURLOPT_POST]=true; $options[CURLOPT_POSTFIELDS]=http_build_query($form); }
    if ($cookie !== null) { $options[CURLOPT_COOKIEFILE]=$cookie; $options[CURLOPT_COOKIEJAR]=$cookie; }
    curl_setopt_array($c, $options);
    $body = curl_exec($c); $status = curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $type = curl_getinfo($c, CURLINFO_CONTENT_TYPE); curl_close($c);
    demoCheck(is_string($body), 'Local demo HTTP response');
    return [$status, json_decode($body, true), $body, $type];
}
try {
    $owner = json_decode(file_get_contents($workspace . '/demo-owner.json'), true, 16, JSON_THROW_ON_ERROR);
    demoCheck((bool)preg_match('/^bcihs_demo_[0-9]{8}_[a-f0-9]{8}$/D', $owner['database'] ?? ''), 'Owned demo database');
    require $workspace . '/app/includes/db_config.php';
    demoCheck(DB_NAME === $owner['database'] && DB_NAME !== 'bcihs_library', 'CLI target is demo only');
    $pdo = new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("SET time_zone='+08:00'");
    $passwords = json_decode(file_get_contents($passwordFile), true, 8, JSON_THROW_ON_ERROR);
    demoCheck((int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn()===1, 'Exactly one administrator');
    demoCheck((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===1, 'Exactly one borrower');
    foreach (['borrow_requests','reservations','attendance','sms_logs','sms_notification_audit'] as $table)
        demoCheck((int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()===0, 'Clean '.$table);
    demoCheck((int)$pdo->query('SELECT allow_notifications FROM sms_notification_settings WHERE id=1')->fetchColumn()===0, 'SMS permission OFF');
    demoCheck((int)$pdo->query('SELECT track_departures FROM attendance_settings WHERE id=1')->fetchColumn()===0, 'Time In only');
    $admin=$pdo->query('SELECT * FROM admins')->fetch(); $user=$pdo->query('SELECT * FROM users')->fetch();
    demoCheck(password_verify($passwords['admin'],$admin['password_hash']), 'Administrator password hashed');
    demoCheck(password_verify($passwords['user'],$user['password_hash']), 'Borrower password hashed');
    demoCheck($user['phone']===null && $user['student_id']==='user' && $user['account_status']==='accepted'
        && $user['full_name']==='Library Reader' && !preg_match('/\b(?:demo|sample|test)\b/i',$user['full_name'].' '.$user['email'].' '.$user['year_section']), 'Fictional approved borrower with polished display and no phone');
    foreach (['admin'=>'admin','user'=>'user'] as $role=>$identifier) {
        $cookie=tempnam($workspace . '/sessions','check-');chmod($cookie,0600);$cookies[]=$cookie;
        [$status]=demoHttp('backend/login-process.php',['identifier'=>$identifier,'password'=>$passwords[$role]],$cookie);
        demoCheck($status===302,'Real '.$role.' authentication redirect');
        [$status,$session]=demoHttp('backend/session-status.php',null,$cookie);
        demoCheck($status===200 && $session['loggedIn'],'Authenticated '.$role.' session');
        if ($role==='admin') $staffCookie=$cookie; else $studentCookie=$cookie;
    }
    [$status,$preview]=demoHttp('backend/public-books-data.php');
    demoCheck($status===200 && count($preview['books'])===4,'Homepage retains four-book preview');
    [$status,$catalog]=demoHttp('backend/books-data.php',null,$studentCookie);
    demoCheck($status===200 && count($catalog['books'])===10,'Ten books in borrower catalog');
    $manifest=json_decode(file_get_contents(dirname(__DIR__).'/demo-assets/manifest.json'),true,64,JSON_THROW_ON_ERROR);
    foreach ($manifest['books'] as $book) {
        demoCheck(count(array_filter($catalog['books'],static fn($row)=>$row['title']===$book['title']))===1,'Sample book searchable in catalog data');
        $stored=array_values(array_filter($catalog['books'],static fn($row)=>$row['title']===$book['title']))[0];
        foreach (['author','edition','place_of_publication','publisher','year_of_publication','isbn','ddc','category','genre','call_number','accession_number'] as $field)
            demoCheck(($stored[$field]??null)===$book[$field],'Catalog metadata matches verified edition field');
        demoCheck((int)$stored['total_copies']===$book['total_copies'] && (int)$stored['borrowed_copies']===0 && $stored['image_path']===$book['image_path'],'Book inventory and cover path preserved');
        $sum=0;foreach(str_split($book['isbn']) as $index=>$digit)$sum+=(int)$digit*($index%2===0?1:3);
        demoCheck(strlen($book['isbn'])===13 && $sum%10===0,'ISBN-13 checksum');
        demoCheck(!preg_match('/\b(?:demo|sample|test)\b/i',implode(' ',array_intersect_key($stored,array_flip(['title','author','category','genre','call_number','accession_number','edition'])))),'No temporary labels in visible book fields');
        [$status,, $body,$type]=demoHttp($book['image_path']);
        demoCheck($status===200 && str_starts_with($type,'image/png')
            && hash('sha256',$body)===hash_file('sha256',$workspace.'/app/'.$book['image_path']),'Book cover serves intact');
    }
    [$status,$notices]=demoHttp('backend/announcements-data.php');
    demoCheck($status===200 && count($notices['announcements'])===4,'Four published demo announcements');
    [$status,$latest]=demoHttp('backend/announcements-data.php?latest=2');
    demoCheck($status===200 && count($latest['announcements'])===2
        && $latest['announcements'][0]['title']===$manifest['announcements'][0]['title']
        && $latest['announcements'][1]['title']===$manifest['announcements'][1]['title'],'Two latest announcements per owner clarification');
    foreach ($manifest['announcements'] as $notice) {
        $stored=array_values(array_filter($notices['announcements'],static fn($row)=>$row['title']===$notice['title']));
        demoCheck(count($stored)===1 && $stored[0]['body']===$notice['body'] && $stored[0]['created_at']===$notice['created_at'],'Complete polished announcement and Manila publication date retained');
        demoCheck(!preg_match('/\b(?:demo|sample|test)\b/i',$notice['title'].' '.$notice['body']),'No temporary labels in visible announcement text');
        [$status,, $body,$type]=demoHttp($notice['image_path']);
        demoCheck($status===200 && str_starts_with($type,'image/png')
            && hash('sha256',$body)===hash_file('sha256',$workspace.'/app/'.$notice['image_path']),'Complete demo banner serves intact');
    }
    [$status,$books]=demoHttp('backend/books-data.php',null,$staffCookie);
    demoCheck($status===200 && count($books['books'])===10,'Manage Books reads ten titles');
    [$status,$mode]=demoHttp('backend/sms-settings.php',null,$staffCookie);
    demoCheck($status===200 && $mode['delivery_mode']==='Disabled' && !$mode['permission']['allowed'],'Effective delivery disabled');
    [$status]=demoHttp('backend/sms-settings.php',null,$studentCookie);
    demoCheck($status===403,'Borrower cannot read privileged SMS settings');
    [$status]=demoHttp('backend/book-save.php',['title'=>'Unauthorized demo action'],$studentCookie);
    demoCheck($status===403,'Borrower cannot change books');
    [$status,$policy]=demoHttp('backend/borrowing-policy.php',null,$staffCookie);
    demoCheck($status===200 && $policy['days']===14,'Demo retains fourteen-day default');
    [$status,$dash]=demoHttp('backend/dashboard-data.php',null,$staffCookie);
    demoCheck($status===200 && $dash['timezone']==='Asia/Manila','Honest dashboard in Philippine time');
    [$status,$report]=demoHttp('backend/statistical-report.php?month=2026-10&months=3&school_year=2026-2027',null,$staffCookie);
    demoCheck($status===200 && ($report['total'] ?? -1)===0,'Empty circulation is not fabricated');
    [$status,, $xlsx]=demoHttp('backend/statistical-report.php?month=2026-10&months=3&school_year=2026-2027&format=xlsx',null,$staffCookie);
    $file=tempnam($workspace,'report-');file_put_contents($file,$xlsx);
    $zip=new ZipArchive();$open=$zip->open($file);
    demoCheck($status===200 && $open===true && $zip->locateName('xl/charts/chart1.xml')!==false,'Excel workbook includes comparison chart');
    if ($open===true) $zip->close();unlink($file);
    foreach (['includes/db-local.php','includes/sms-local.php','vendor/autoload.php','demo-assets/manifest.json'] as $private) {
        [$status]=demoHttp($private);demoCheck($status===403,'Preview denies internal resource');
    }
    [$status,, $adminPage]=demoHttp('admin-dashboard.html',null,$staffCookie);
    demoCheck($status===200 && !str_contains($adminPage,'demo-assets/staging-indicator.js'),'Staff dashboard has no staging banner injection');
    [$status,, $homePage]=demoHttp('index.html');
    demoCheck($status===200 && !str_contains($homePage,'staging-indicator.js'),'Public homepage has no staging indicator injection');
    demoCheck((int)$pdo->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn()===0,'Read-only acceptance creates no SMS history');
    echo 'Completed '.$checks." demo content/read-only HTTP checks. No database rows or provider calls written.\n";
} catch (Throwable $error) {
    // Labels are static; do not output exception data, passwords or provider details.
    fwrite(STDERR,'Demo acceptance stopped at check '.($checks+1)."; inspect the static test assertion, not private configuration.\n");exit(1);
} finally {
    foreach ($cookies as $file) if (is_file($file)) unlink($file);
}
