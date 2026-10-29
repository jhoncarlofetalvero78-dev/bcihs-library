<?php
if (getenv('BCIHS_PHASE8_ISOLATED') !== '1' || !getenv('BCIHS_SMS_ACCEPTANCE_ROOT')) throw new RuntimeException('Owned isolated environment required.');
$dueRoot = getenv('BCIHS_SMS_ACCEPTANCE_ROOT');
$policy = static function (array $session) { [, $data]=http('borrowing-policy','GET',null,$session); return $data; };
foreach ([null,$studentSession,$teacherSession,$visitorSession] as $unauthorized) {
    [$code]=http('borrowing-policy','GET',null,$unauthorized);check(in_array($code,[401,403],true),'borrowing policy read requires staff');
    [$code]=http('checkout-due-preview','POST',['source'=>'borrow','id'=>1],$unauthorized);check(in_array($code,[401,403],true),'due preview requires authorized staff');
}
$initialPolicy=$policy($staff);
check($initialPolicy['days']===14 && $initialPolicy['version']===0 && !is_file($dueRoot.'/includes/borrowing-policy.json'),'unconfigured policy retains actual 14-day default without creating a settings file');
check(!$policy($libSession)['can_manage'],'librarian may view, not edit, the default');
post('borrowing-policy',['days'=>3,'version'=>0,'reason'=>'School approved fixture','confirm_change'=>true],$libSession,403);
[$code]=http('borrowing-policy','POST',['days'=>3,'version'=>0,'reason'=>'School approved fixture','confirm_change'=>true],$staff,false);check($code===403,'default policy requires CSRF');
foreach ([0,366,'3.5',[],true] as $days) post('borrowing-policy',['days'=>$days,'version'=>0,'reason'=>'Fixture','confirm_change'=>true],$staff,422);
post('borrowing-policy',['days'=>3,'version'=>0,'reason'=>'','confirm_change'=>true],$staff,422);
post('borrowing-policy',['days'=>3,'version'=>0,'reason'=>'Fixture','confirm_change'=>false],$staff,422);

$dueStudent=user('student','DUE-STUDENT');
$pdo->prepare('UPDATE users SET phone=:phone WHERE id=:id')->execute(['phone'=>'09170000109','id'=>$dueStudent]);
$dueBorrower=login('DUE-STUDENT');
$newRequest=static function() use($dueBorrower,$staff): int {
    $book=book();$id=post('borrow-request-process',['book_id'=>$book],$dueBorrower)['id'];
    post('borrow-request-action',['id'=>$id,'action'=>'approve'],$staff);return $id;
};
$defaultLoan=$newRequest();
post('borrow-request-action',['id'=>$defaultLoan,'action'=>'checkout'],$staff);
$originalDeadline=$pdo->query('SELECT due_date FROM borrow_requests WHERE id='.(int)$defaultLoan)->fetchColumn();
check((int)$pdo->query('SELECT TIMESTAMPDIFF(DAY,checked_out_at,due_date) FROM borrow_requests WHERE id='.(int)$defaultLoan)->fetchColumn()===14,'existing default gives exactly 14 days on server-side checkout');

$changed=post('borrowing-policy',['days'=>5,'version'=>$initialPolicy['version'],'reason'=>'Approved isolated five-day policy','confirm_change'=>true],$staff);
check($changed['days']===5 && $changed['version']===1 && $changed['audit'][0]['previous_days']===14 && $changed['audit'][0]['new_days']===5,'administrator policy edit persists an audited before/after change');
check($pdo->query('SELECT due_date FROM borrow_requests WHERE id='.(int)$defaultLoan)->fetchColumn()===$originalDeadline,'default changes never alter an active loan');
post('borrowing-policy',['days'=>3,'version'=>0,'reason'=>'Stale editor','confirm_change'=>true],$staff,409);
$futureLoan=$newRequest();post('borrow-request-action',['id'=>$futureLoan,'action'=>'checkout'],$staff);
check((int)$pdo->query('SELECT TIMESTAMPDIFF(DAY,checked_out_at,due_date) FROM borrow_requests WHERE id='.(int)$futureLoan)->fetchColumn()===5,'only future default checkouts use the newly approved period');

$customLoan=$newRequest();
$preview=post('checkout-due-preview',['source'=>'borrow','id'=>$customLoan,'due_mode'=>'days','days'=>3],$libSession);
$otherLoan=$newRequest();
post('borrow-request-action',['id'=>$otherLoan,'action'=>'checkout','due_preview_token'=>$preview['due_preview_token']],$libSession,409);
post('borrow-request-action',['id'=>$customLoan,'action'=>'checkout','due_preview_token'=>$preview['due_preview_token']],$staff,409);
foreach ([['due_mode'=>'days','days'=>0],['due_mode'=>'days','days'=>366],['due_mode'=>'date','due_date'=>'2026-02-30'],['due_mode'=>'date','due_date'=>'2000-01-01'],['due_mode'=>'unknown']] as $bad) {
    post('checkout-due-preview',['source'=>'borrow','id'=>$customLoan]+$bad,$libSession,422);
}

// Only this disposable copy has fake live credentials and a file-only provider.
file_put_contents($dueRoot.'/includes/sms-local.php','<?php return '.var_export([
    'provider'=>'unisms','mode'=>'live','live_enabled'=>true,'unisms_secret'=>'SYNTHETIC_ONLY',
    'unisms_delivery'=>'production','unisms_production_authorized'=>true,'live_test'=>null,
    'unisms_verified_networks'=>['09170000109'=>'dito']],true).';');
$callCount=static fn()=>is_file($dueRoot.'/mock-calls.jsonl')?count(file($dueRoot.'/mock-calls.jsonl')):0;
$beforeCalls=$callCount();
$qr=$pdo->query('SELECT pickup_token FROM borrow_requests WHERE id='.(int)$customLoan)->fetchColumn();
$result=post('borrow-request-action',['id'=>$customLoan,'action'=>'checkout','pickup_token'=>$qr,'due_preview_token'=>$preview['due_preview_token']],$libSession);
$saved=$pdo->query('SELECT due_date FROM borrow_requests WHERE id='.(int)$customLoan)->fetchColumn();
check($saved===$preview['due_at'] && $result['due_date']===$saved,'QR checkout saves exactly the staff-confirmed custom three-day deadline');
$log=$pdo->query('SELECT * FROM sms_logs WHERE user_id='.(int)$dueStudent.' ORDER BY id DESC LIMIT 1')->fetch();
check(smsLogMetadata($log)['event']==='checkout' && smsLogMetadata($log)['provider']==='unisms' && $log['message']===smsEventMessage('checkout',['title'=>'Phase8 book','due_date'=>$saved]),'checkout SMS reuses the exact stored deadline');
post('borrow-request-action',['id'=>$customLoan,'action'=>'checkout','pickup_token'=>$qr,'due_preview_token'=>$preview['due_preview_token']],$libSession,409);
check($callCount()===$beforeCalls+1,'duplicate QR checkout cannot repeat stock mutation or SMS');
[$code,$history]=http('borrowing-history-data','GET',null,$dueBorrower);
$match=array_values(array_filter($history['requests'],static fn($r)=>(int)$r['id']===(int)$customLoan));
check($code===200 && $match[0]['due_date']===$saved,'student borrowing history uses the same saved custom due date');
[$code,$transactions]=http('transactions-data','GET',null,$staff);
$match=array_values(array_filter($transactions['requests'],static fn($r)=>$r['source']==='borrow' && (int)$r['id']===(int)$customLoan));
check($match[0]['due_date']===$saved,'staff transactions use the same stored deadline');

$reservedBook=book();$pdo->exec('UPDATE books SET total_copies=0 WHERE id='.$reservedBook);
$pickup=(new DateTimeImmutable('next monday 10:00',new DateTimeZone('Asia/Manila')))->format('Y-m-d\TH:i');
$reservation=post('reservation-process',['book_id'=>$reservedBook,'pickup_at'=>$pickup],$dueBorrower)['id'];
$pdo->exec('UPDATE books SET total_copies=1 WHERE id='.$reservedBook);
post('reservation-action',['id'=>$reservation,'action'=>'ready'],$staff);
$reservationPreview=post('checkout-due-preview',['source'=>'reservation','id'=>$reservation,'due_mode'=>'days','days'=>'3'],$staff);
post('reservation-action',['id'=>$reservation,'action'=>'fulfill','due_preview_token'=>$preview['due_preview_token']],$staff,409);
$result=post('reservation-action',['id'=>$reservation,'action'=>'fulfill','due_preview_token'=>$reservationPreview['due_preview_token']],$staff);
check($result['due_date']===$reservationPreview['due_at'] && $pdo->query('SELECT due_date FROM reservations WHERE id='.(int)$reservation)->fetchColumn()===$result['due_date'],'reservation checkout shares the exact custom deadline calculation');
$reservationLog=$pdo->query('SELECT * FROM sms_logs WHERE user_id='.(int)$dueStudent.' ORDER BY id DESC LIMIT 1')->fetch();
check($reservationLog['message']===smsEventMessage('checkout',['title'=>'Phase8 book','due_date'=>$result['due_date']]),'reservation checkout SMS uses its saved deadline');
[$code,$studentReservations]=http('my-reservations-data','GET',null,$dueBorrower);
$match=array_values(array_filter($studentReservations['reservations'],static fn($r)=>(int)$r['id']===(int)$reservation));
check($match[0]['due_date']===$result['due_date'],'student reservation history uses the stored custom due date');
[$code,$loans]=http('reports-data?report=loans&period=month','GET',null,$staff);
$match=array_values(array_filter($loans['records'],static fn($r)=>$r['record_key']==='reservation:'.$reservation));
check($match[0]['due_date']===$result['due_date'],'loan reports retain the reservation custom deadline');

$manualBook=book();$date=(new DateTimeImmutable('+7 days',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$manualPreview=post('checkout-due-preview',['source'=>'manual','id'=>$manualBook,'due_mode'=>'date','due_date'=>$date],$staff);
[$code,$form]=http('borrow-requests-data','GET',null,$staff);
$manual=post('borrow-request-add',['borrower_name'=>'Due fixture walk-in','book_id'=>$manualBook,'confirm_checkout'=>true,'checkout_token'=>$form['manualCheckoutToken'],'due_preview_token'=>$manualPreview['due_preview_token']],$staff);
check($manual['due_date']===$date.' 23:59:59','custom calendar date is saved at Philippine end of day');
$manualAgain=post('borrow-request-add',['borrower_name'=>'Due fixture walk-in','book_id'=>$manualBook,'confirm_checkout'=>true,'checkout_token'=>$form['manualCheckoutToken'],'due_preview_token'=>$manualPreview['due_preview_token']],$staff);
check($manualAgain['id']===$manual['id'] && $manualAgain['replayed'],'manual due selection preserves checkout nonce idempotency');

$stale=post('checkout-due-preview',['source'=>'borrow','id'=>$otherLoan,'due_mode'=>'default'],$staff);
$current=$policy($staff);
post('borrowing-policy',['days'=>3,'version'=>$current['version'],'reason'=>'Second isolated approved default','confirm_change'=>true],$staff);
post('borrow-request-action',['id'=>$otherLoan,'action'=>'checkout','due_preview_token'=>$stale['due_preview_token']],$staff,409);
$ordinaryPreview=post('checkout-due-preview',['source'=>'borrow','id'=>$otherLoan,'due_mode'=>'days','days'=>3],$staff);
post('borrow-request-action',['id'=>$otherLoan,'action'=>'checkout','due_preview_token'=>$ordinaryPreview['due_preview_token']],$staff);
check((int)$pdo->query('SELECT DATEDIFF(due_date,checked_out_at) FROM borrow_requests WHERE id='.(int)$otherLoan)->fetchColumn()===3,'ordinary checkout also supports an explicit custom three-calendar-day period without a QR');
post('borrow-request-action',['id'=>$defaultLoan,'action'=>'change_due_date','due_date'=>$date],$staff,422);
post('borrow-request-action',['id'=>$defaultLoan,'action'=>'return','due_date'=>$date],$staff,422);
check($pdo->query('SELECT due_date FROM borrow_requests WHERE id='.(int)$defaultLoan)->fetchColumn()===$originalDeadline,'unsupported active-loan edits cannot shorten an existing deadline');
$policyPath=$dueRoot.'/includes/borrowing-policy.json';$policyBytes=file_get_contents($policyPath);
// Use a still-awaiting request so this tests policy failure, not repeat checkout.
$blockedLoan=$newRequest();file_put_contents($policyPath,'{broken');
post('borrow-request-action',['id'=>$blockedLoan,'action'=>'checkout'],$staff,500);
file_put_contents($policyPath,$policyBytes);
check($pdo->query('SELECT status FROM borrow_requests WHERE id='.(int)$blockedLoan)->fetchColumn()==='awaiting_pickup','invalid policy blocks checkout without changing eligibility or inventory');
$current=$policy($staff);post('borrowing-policy',['days'=>14,'version'=>$current['version'],'reason'=>'Restore isolated initial default','confirm_change'=>true],$staff);
file_put_contents($dueRoot.'/includes/sms-local.php',"<?php return ['provider'=>'simulation','mode'=>'simulate','live_enabled'=>false];");
