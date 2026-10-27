<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/attendance.php';
try {
    attendanceAccess($pdo, 'GET');
    $today = $pdo->query('SELECT CURDATE()')->fetchColumn();
    $date = attendanceText($_GET['date'] ?? $today, 10);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date || $date < '1000-01-01' || $date > '9999-12-30') throw new DomainException('Enter a valid attendance date.', 422);
    $search = attendanceText($_GET['search'] ?? '', 100, false);
    $view = $_GET['view'] ?? 'all';
    if (!in_array($view, ['all','inside','completed','time_in_only'], true)) throw new DomainException('Choose a valid attendance view.', 422);
    $params = [];
    if ($view === 'inside') $where = 'tracks_departure = 1 AND time_out IS NULL';
    else {
        $where = 'time_in >= :start AND time_in < :end';
        $params = ['start'=>$date . ' 00:00:00','end'=>$parsed->modify('+1 day')->format('Y-m-d') . ' 00:00:00'];
        if ($view === 'completed') $where .= ' AND time_out IS NOT NULL';
        if ($view === 'time_in_only') $where .= ' AND tracks_departure = 0';
    }
    if ($search !== '') {
        $like = '%' . str_replace(['!','%','_'], ['!!','!%','!_'], $search) . '%';
        $where .= " AND (student_name LIKE :name ESCAPE '!' OR student_identifier LIKE :identifier ESCAPE '!')";
        $params['name']=$like; $params['identifier']=$like;
    }
    // Read one consistent snapshot so summary, policy and records agree.
    $pdo->beginTransaction();
    $settings = attendanceSettings($pdo);
    $stmt = $pdo->prepare('SELECT id,user_id,student_name,year_level,section,student_identifier,year_section_snapshot,
        tracks_departure,time_in,time_out,CASE WHEN tracks_departure=1 THEN GREATEST(0,TIMESTAMPDIFF(SECOND,time_in,COALESCE(time_out,NOW()))) ELSE NULL END AS duration_seconds
        FROM attendance WHERE ' . $where . ' ORDER BY time_in DESC,id DESC');
    $stmt->execute($params);
    $records = $stmt->fetchAll();
    $summary = attendanceCounts($pdo,$date);
    $todaySummary = attendanceCounts($pdo,$today);
    $inside = attendanceOccupancy($pdo);
    $pdo->commit();
    echo json_encode(['records'=>$records,'date'=>$date,'serverToday'=>$today,'timezone'=>'Asia/Manila','summary'=>$summary,
        'today'=>$todaySummary,'inside'=>$inside,'settings'=>$settings,'csrfToken'=>bookCsrfToken(),'formToken'=>attendanceFormToken()]);
} catch (Throwable $exception) { attendanceFailure($pdo, $exception); }
