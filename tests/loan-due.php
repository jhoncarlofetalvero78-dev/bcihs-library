<?php
// All settings/previews live in an owned temporary copy; no database or provider.
const BORROWING_DAYS = 14;
$root = sys_get_temp_dir() . '/bcihs-due-unit-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
copy(__DIR__ . '/../includes/loan-due.php', $root . '/loan-due.php');
require $root . '/loan-due.php';
$checks = 0;
function dueCheck(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
}
function dueRejected(callable $operation, int $code): void {
    try { $operation(); dueCheck(false, 'invalid selection accepted'); }
    catch (DomainException $error) { dueCheck($error->getCode() === $code, 'validation status'); }
}
try {
    $now = new DateTimeImmutable('2026-10-29 09:30:15', new DateTimeZone('Asia/Manila'));
    $policy = loanPolicy();
    dueCheck($policy['days'] === 14 && !is_file($root.'/borrowing-policy.json'), 'default remains unchanged and reads create no policy');
    dueCheck(calculateLoanDue([], $policy, $now) === '2026-11-12 09:30:15', 'default fourteen calendar days');
    dueCheck(calculateLoanDue(['due_mode'=>'days','days'=>3], $policy, $now) === '2026-11-01 09:30:15', 'custom three-day period crosses month/weekend without invented closure rules');
    dueCheck(calculateLoanDue(['due_mode'=>'date','due_date'=>'2026-10-29'], $policy, $now) === '2026-10-29 23:59:59', 'same-day custom date has an explicit end-of-day deadline');
    dueCheck(calculateLoanDue(['due_mode'=>'days','days'=>1], $policy, new DateTimeImmutable('2028-02-28 10:00:00',new DateTimeZone('Asia/Manila'))) === '2028-02-29 10:00:00', 'leap-year date calculation');
    foreach ([0,-1,366,'3.5','03',null,[],true] as $bad) dueRejected(static fn()=>loanDays($bad), 422);
    foreach (['2026-02-30','2026-2-3','not-date','2020-01-01','2038-01-19'] as $bad) dueRejected(static fn()=>calculateLoanDue(['due_mode'=>'date','due_date'=>$bad],$policy,$now),422);
    dueRejected(static fn()=>calculateLoanDue(['due_mode'=>'date','due_date'=>'2027-10-30'],$policy,$now),422);
    dueRejected(static fn()=>calculateLoanDue(['due_mode'=>'unknown'],$policy,$now),422);
    dueRejected(static fn()=>calculateLoanDue([],$policy,new DateTimeImmutable('2038-01-17',new DateTimeZone('Asia/Manila'))),422);

    $_SESSION['loan_due_previews']['synthetic']=['source'=>'borrow','id'=>10,'due_at'=>'2026-11-01 09:30:15','mode'=>'days','policy_version'=>0,'expires'=>time()+120];
    $input=['due_preview_token'=>'synthetic'];
    dueCheck(checkoutLoanDue($input,'borrow',10,$now->format('Y-m-d H:i:s'))==='2026-11-01 09:30:15','confirmation reuses exact preview seconds');
    dueRejected(static fn()=>checkoutLoanDue($input,'reservation',10,$now->format('Y-m-d H:i:s')),409);
    dueRejected(static fn()=>checkoutLoanDue($input,'borrow',11,$now->format('Y-m-d H:i:s')),409);
    $_SESSION['loan_due_previews']['synthetic']['expires']=time()-1;
    dueRejected(static fn()=>checkoutLoanDue($input,'borrow',10,$now->format('Y-m-d H:i:s')),409);
    $_SESSION['loan_due_previews']['synthetic']['expires']=time()+120;
    $saved=saveLoanPolicy(['days'=>5,'version'=>0,'reason'=>'Approved synthetic policy','confirm_change'=>true],1);
    dueCheck($saved['version']===1 && $saved['audit'][0]['previous_days']===14 && $saved['audit'][0]['new_days']===5,'default changes are atomic with their before/after audit');
    dueCheck((fileperms($root.'/borrowing-policy.json')&0777)===0600,'policy and audit are private');
    dueRejected(static fn()=>saveLoanPolicy(['days'=>3,'version'=>0,'reason'=>'Stale','confirm_change'=>true],1),409);
    dueCheck(checkoutLoanDue($input,'borrow',10,$now->format('Y-m-d H:i:s'))==='2026-11-01 09:30:15','approved custom preview survives unrelated default edits');
    $_SESSION['loan_due_previews']['synthetic']['mode']='default';
    dueRejected(static fn()=>checkoutLoanDue($input,'borrow',10,$now->format('Y-m-d H:i:s')),409);
    dueRejected(static fn()=>checkoutLoanDue(['days'=>3],'borrow',10,$now->format('Y-m-d H:i:s')),422);
    dueCheck(checkoutLoanDue([],'borrow',10,$now->format('Y-m-d H:i:s'))==='2026-11-03 09:30:15','legacy default callers honor only the current future-checkout policy');
    $workers=[];
    foreach ([3,7] as $days) {
        $code='define("BORROWING_DAYS",14); require '.var_export($root.'/loan-due.php',true).'; try { saveLoanPolicy(["days"=>'.$days.',"version"=>1,"reason"=>"Concurrent fixture","confirm_change"=>true],1); echo "200"; } catch (DomainException $e) { echo $e->getCode(); }';
        $process=proc_open([PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]);$workers[]=[$process,$pipes];
    }
    $codes=[];
    foreach ($workers as [$process,$pipes]) {
        $codes[]=(int)stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);dueCheck(proc_close($process)===0 && $errors==='','concurrent policy writer completes safely');
    }
    sort($codes);dueCheck($codes===[200,409] && loanPolicy()['version']===2 && count(loanPolicy()['audit'])===2,'simultaneous administrators cannot overwrite a newer policy/audit version');
    echo "Completed {$checks} database-free due-date checks. No active settings, database or transport changed.\n";
} finally {
    foreach (glob($root.'/*') as $file) unlink($file);
    foreach (glob($root.'/.borrowing-policy*') as $file) unlink($file);
    rmdir($root);
}
