<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/reporting.php';
try {
    requireBookAccess($pdo,true);
    if ($_SERVER['REQUEST_METHOD']!=='GET') bookError(405,'method_not_allowed');
    $rows=reportTransactions($pdo);$months=$borrowed=$checkouts=$returns=[];
    $now=new DateTimeImmutable();$month=$now->modify('first day of this month')->setTime(0,0);
    for ($i=5;$i>=0;$i--) {
        $start=$month->modify('-'.$i.' months');$end=$start->modify('+1 month');
        $cutoff=min($end->format('Y-m-d H:i:s'),$now->format('Y-m-d H:i:s'));
        $out=$checked=$returned=0;
        foreach ($rows as $row) {
            if ($row['is_loan'] && $row['borrowed_at']<$end->format('Y-m-d H:i:s') && $row['borrowed_at']<=$cutoff && ($row['returned_at']===null || $row['returned_at']>=$end->format('Y-m-d H:i:s') || $row['returned_at']>$cutoff)) $out++;
            if ($row['is_loan'] && $row['borrowed_at']>=$start->format('Y-m-d H:i:s') && $row['borrowed_at']<$end->format('Y-m-d H:i:s') && $row['borrowed_at']<=$cutoff) $checked++;
            if ($row['status']==='returned' && $row['returned_at']!==null && $row['returned_at']>=$start->format('Y-m-d H:i:s') && $row['returned_at']<$end->format('Y-m-d H:i:s') && $row['returned_at']<=$cutoff) $returned++;
        }
        $months[]=$start->format('M Y');$borrowed[]=$out;$checkouts[]=$checked;$returns[]=$returned;
    }
    echo json_encode(['months'=>$months,'borrowed'=>$borrowed,'checkouts'=>$checkouts,'returns'=>$returns,
        'scope'=>'Circulation events and outstanding loans at period end (current month: now). Historical physical inventory is unavailable.', 'timezone'=>'Asia/Manila']);
} catch (Throwable $exception) { borrowingFailure($pdo,$exception); }
