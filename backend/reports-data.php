<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/reporting.php';
try {
    requireBookAccess($pdo,true);
    if ($_SERVER['REQUEST_METHOD']!=='GET') bookError(405,'method_not_allowed');
    $report=$_GET['report']??'inventory';
    $allowed=[
        'inventory'=>['all'],'loans'=>['all','checked_out','returned'],'returns'=>['all','returned'],
        'requests'=>['all','pending','awaiting_pickup','approved','checked_out','returned','rejected'],
        'overdue'=>['all','checked_out'],'reservations'=>['all','waiting','ready','fulfilled','returned','cancelled'],
        'attendance'=>['all','time_in_only','inside','completed'],'users'=>['all','accepted','pending','rejected']
    ];
    if (!is_string($report) || !isset($allowed[$report])) throw new DomainException('Choose a valid report.',422);
    $status=$_GET['status']??'all';
    if (!in_array($status,$allowed[$report],true)) throw new DomainException('Choose a valid status for this report.',422);
    $range=reportRange($_GET);
    $search=attendanceText($_GET['search']??'',100,false);
    $basis=$_GET['basis']??'created_at';
    if (!in_array($basis,['created_at','pickup_at'],true)) throw new DomainException('Choose reservation creation or scheduled pickup date.',422);
    $pdo->beginTransaction();
    $books=reportInventory($pdo);$transactions=reportTransactions($pdo);
    $current=reportCurrentSummary($pdo,$books,$transactions);
    $summary=[];$records=[];$scope='';$fields=[];
    if ($report==='inventory') {
        $scope='Current inventory snapshot; date range does not apply.';
        $records=$books;$fields=['title','author','isbn','accession_number','ddc','category','call_number'];
    } elseif (in_array($report,['loans','returns','overdue','requests','reservations'],true)) {
        $dateField=match($report){'loans'=>'borrowed_at','returns'=>'returned_at','reservations'=>$basis,default=>'created_at'};
        $scope=match($report){'loans'=>'Actual checkout date (PHT). Current outcome for those checkouts.',
            'returns'=>'Actual return date (PHT), including loans checked out before the period.',
            'overdue'=>'Current overdue loans as of today (PHT); date range does not apply.',
            'requests'=>'Borrow request creation date (PHT); current request outcomes.',
            default=>$basis==='pickup_at'?'Scheduled pickup date (PHT); records without a schedule are excluded.':'Reservation creation date (PHT); current reservation outcomes.'};
        foreach ($transactions as $row) {
            if ($report==='requests' && $row['source']!=='borrow') continue;
            if ($report==='reservations' && $row['source']!=='reservation') continue;
            if ($report==='loans' && !$row['is_loan']) continue;
            if ($report==='returns' && ($row['status']!=='returned' || !$row['returned_at'])) continue;
            if ($report==='overdue' && !$row['overdue']) continue;
            if ($report!=='overdue' && !reportInRange($row[$dateField],$range)) continue;
            if (in_array($report,['loans','returns','overdue'],true)) $row['status']=$row['loan_status'];
            $records[]=$row;
        }
        usort($records,static fn($a,$b)=>strcmp($b[$dateField]??'',$a[$dateField]??'') ?: strcmp($b['record_key'],$a['record_key']));
        $fields=['record_key','borrower_name','book_title'];
    } elseif ($report==='attendance') {
        $scope='Time In date (PHT). Visits and distinct linked students are different counts. Occupancy is current across all dates.';
        $stmt=$pdo->prepare('SELECT id,user_id,student_name,student_identifier,year_level,section,year_section_snapshot,
            time_in,time_out,tracks_departure FROM attendance WHERE time_in>=:start AND time_in<:end ORDER BY time_in DESC,id DESC');
        $stmt->execute(['start'=>$range['from'].' 00:00:00','end'=>(new DateTimeImmutable($range['to']))->modify('+1 day')->format('Y-m-d').' 00:00:00']);
        $records=$stmt->fetchAll();
        foreach ($records as &$row) {
            $row['status']=!(int)$row['tracks_departure']?'time_in_only':($row['time_out']?'completed':'inside');
            $row['year_section']=$row['year_section_snapshot'] ?: trim($row['year_level'].' / '.$row['section'],' /');
            $row['visitor_type']=$row['user_id']!==null?'Registered':'Manual / unlinked';
        }unset($row);
        $seen=[];
        foreach (array_reverse(array_keys($records)) as $index) {
            $key=substr($records[$index]['time_in'],0,10).':'.$records[$index]['user_id'];
            $records[$index]['repeat_visit']=$records[$index]['user_id']!==null && isset($seen[$key]);
            if ($records[$index]['user_id']!==null) $seen[$key]=true;
        }
        $fields=['student_name','student_identifier'];
    } else {
        $scope='Account registration date (PHT); current approval status. Grouped counts only; no personal contact details.';
        $stmt=$pdo->prepare('SELECT role,account_status AS status,COUNT(*) AS accounts FROM users
            WHERE created_at>=:start AND created_at<:end GROUP BY role,account_status ORDER BY role,account_status');
        $stmt->execute(['start'=>$range['from'].' 00:00:00','end'=>(new DateTimeImmutable($range['to']))->modify('+1 day')->format('Y-m-d').' 00:00:00']);
        $records=$stmt->fetchAll();$fields=['role','status'];
    }
    $records=reportFilter($records,$search,$status,$fields);
    if (!in_array($report,['inventory','attendance'],true)) $summary['Matching rows']=count($records);
    if ($report==='inventory') {
        foreach (['Titles'=>null,'Physical copies'=>'total_copies','Borrowed copies'=>'borrowed_copies','Held for pickup'=>'held_copies','Available for new requests'=>'requestable_copies'] as $label=>$field) {
            $summary[$label]=$field===null?count($records):array_sum(array_column($records,$field));
        }
    } elseif ($report==='attendance') {
        $students=[];$walkIns=0;$daily=[];
        foreach ($records as $row) {
            $day=substr($row['time_in'],0,10);$daily[$day]=($daily[$day]??0)+1;
            if ($row['user_id']!==null) {$students[$row['user_id']]=true;}
            else $walkIns++;
        }
        $summary['Total visits']=count($records);$summary['Unique registered students']=count($students);
        $summary['Manual / unlinked visits']=$walkIns;
        $summary['Registered repeat visits on same day']=count(array_filter($records,static fn($row)=>$row['repeat_visit']));
        ksort($daily);$summary['Current occupancy (all dates)']=$current['occupancy']??'Not tracked';
        $summary['Days with visits']=count($daily);
    } elseif ($report==='users') {
        $summary['Accounts']=array_sum(array_column($records,'accounts'));
        $summary['Accepted students']=array_sum(array_map(static fn($r)=>$r['role']==='student' && $r['status']==='accepted'?(int)$r['accounts']:0,$records));
    } else {
        foreach ($allowed[$report] as $value) if ($value!=='all') $summary[$value]=count(array_filter($records,static fn($r)=>$r['status']===$value));
        $summary['Overdue now (matching loans)']=count(array_filter($records,static fn($r)=>$r['overdue']));
    }
    $pdo->commit();
    echo json_encode(['report'=>$report,'records'=>$records,'summary'=>$summary,'daily'=>$daily??[], 'range'=>$range,
        'scope'=>$scope,'current'=>$current,'generatedAt'=>date('Y-m-d H:i:s'),'serverToday'=>date('Y-m-d'),'timezone'=>'Asia/Manila']);
} catch (Throwable $exception) { borrowingFailure($pdo,$exception); }
