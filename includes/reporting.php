<?php
require_once __DIR__ . '/borrowing.php';
require_once __DIR__ . '/attendance.php';

function reportInventory(PDO $pdo): array
{
    return addBookAvailability($pdo->query('SELECT b.id,b.title,b.author,b.isbn,b.accession_number,b.ddc,b.category,b.call_number,
        b.total_copies,b.borrowed_copies,(' . BOOK_HOLDS_SQL . ') AS held_copies FROM books b ORDER BY b.title,b.id')->fetchAll());
}

function reportTransactions(PDO $pdo): array
{
    $rows = $pdo->query('SELECT br.id,"borrow" AS source,br.book_id,b.title AS book_title,
        COALESCE(br.borrower_name,u.full_name,"Unknown borrower") AS borrower_name,br.status,
        br.requested_at AS created_at,br.approved_at,br.checked_out_at,
        COALESCE(br.checked_out_at,CASE WHEN br.status IN ("approved","returned") THEN br.approved_at END) AS borrowed_at,
        br.due_date,br.returned_at,NULL AS pickup_at
        FROM borrow_requests br LEFT JOIN users u ON u.id=br.user_id JOIN books b ON b.id=br.book_id
        UNION ALL
        SELECT r.id,"reservation",r.book_id,b.title,u.full_name,r.status,r.reserved_at,r.ready_at,r.fulfilled_at,
        r.fulfilled_at,r.due_date,r.returned_at,r.pickup_at
        FROM reservations r JOIN users u ON u.id=r.user_id JOIN books b ON b.id=r.book_id')->fetchAll();
    foreach ($rows as &$row) {
        $row['record_key'] = $row['source'] . ':' . $row['id'];
        $row['legacy_checkout'] = $row['source'] === 'borrow' && $row['checked_out_at'] === null && $row['borrowed_at'] !== null;
        $row['loan_status'] = $row['status'] === 'returned' ? 'returned' : 'checked_out';
        $row['is_loan'] = $row['borrowed_at'] !== null && in_array($row['status'], ['approved','checked_out','fulfilled','returned'],true);
        $row['overdue'] = $row['is_loan'] && $row['status'] !== 'returned' && $row['due_date'] !== null && substr($row['due_date'],0,10) < date('Y-m-d');
    }
    unset($row);
    return $rows;
}

function reportCurrentSummary(PDO $pdo, array $books, array $transactions): array
{
    $totals = ['titles'=>count($books),'copies'=>0,'borrowed_copies'=>0,'held_copies'=>0,'requestable_copies'=>0,'available_copies'=>0,
        'pending'=>0,'awaiting_pickup'=>0,'outstanding_loans'=>0,'overdue'=>0,'waiting'=>0,'ready'=>0];
    foreach ($books as $book) {
        foreach (['borrowed_copies','held_copies','requestable_copies','available_copies'] as $field) $totals[$field]+=(int)$book[$field];
        $totals['copies']+=(int)$book['total_copies'];
    }
    $outstandingByBook=[];
    foreach ($transactions as $row) {
        if ($row['source']==='borrow' && in_array($row['status'],['pending','awaiting_pickup'],true)) $totals[$row['status']]++;
        if ($row['source']==='reservation' && in_array($row['status'],['waiting','ready'],true)) $totals[$row['status']]++;
        if ($row['is_loan'] && $row['status']!=='returned') {
            $totals['outstanding_loans']++;
            $outstandingByBook[$row['book_id']]=($outstandingByBook[$row['book_id']]??0)+1;
        }
        if ($row['overdue']) $totals['overdue']++;
    }
    $totals['users']=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $totals['accepted_students']=(int)$pdo->query('SELECT COUNT(*) FROM users WHERE role="student" AND account_status="accepted"')->fetchColumn();
    $totals['attendance_today']=attendanceCounts($pdo,date('Y-m-d'));
    $totals['occupancy']= (int)attendanceSettings($pdo)['track_departures'] ? attendanceOccupancy($pdo) : null;
    $totals['undated_loan_records']=count(array_filter($transactions,static fn($row)=>in_array($row['status'],['approved','checked_out','fulfilled','returned'],true) && $row['borrowed_at']===null));
    $totals['inventory_matches_loans']=true;
    foreach ($books as $book) if ((int)$book['borrowed_copies']!==($outstandingByBook[$book['id']]??0)) $totals['inventory_matches_loans']=false;
    return $totals;
}

function reportDate($value): DateTimeImmutable
{
    if (!is_string($value)) throw new DomainException('Enter valid report dates.',422);
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if (!$date || $date->format('Y-m-d')!==$value || $value<'1000-01-01' || $value>'9999-12-30') throw new DomainException('Enter valid report dates.',422);
    return $date;
}

function reportRange(array $input): array
{
    $preset=$input['period']??'month';
    $today=new DateTimeImmutable('today');
    switch ($preset) {
        case 'today': $from=$to=$today;break;
        case 'week': $from=$today->modify('monday this week');$to=$from->modify('+6 days');break;
        case 'month': $from=$today->modify('first day of this month');$to=$today->modify('last day of this month');break;
        case 'custom': $from=reportDate($input['from']??null);$to=reportDate($input['to']??null);break;
        default: throw new DomainException('Choose Today, This week, This month or Custom.',422);
    }
    if ($from>$to) throw new DomainException('The start date must be on or before the end date.',422);
    return ['from'=>$from->format('Y-m-d'),'to'=>$to->format('Y-m-d')];
}

function reportInRange(?string $time, array $range): bool
{
    return $time!==null && substr($time,0,10)>=$range['from'] && substr($time,0,10)<=$range['to'];
}

function reportFilter(array $records, string $search, string $status, array $searchFields): array
{
    return array_values(array_filter($records,static function($row)use($search,$status,$searchFields){
        if ($status!=='all' && $row['status']!==$status) return false;
        if ($search==='') return true;
        foreach ($searchFields as $field) if (mb_stripos((string)($row[$field]??''),$search)!==false) return true;
        return false;
    }));
}
