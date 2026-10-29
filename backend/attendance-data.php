<?php
require_once __DIR__ . '/../includes/session.php';
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
    if ($view !== 'all') throw new DomainException('Attendance is Time In only. Reload the page to use date and name filters.', 422);
    $where = 'time_in >= :start AND time_in < :end';
    $params = ['start'=>$date . ' 00:00:00','end'=>$parsed->modify('+1 day')->format('Y-m-d') . ' 00:00:00'];
    if ($search !== '') {
        $like = '%' . str_replace(['!','%','_'], ['!!','!%','!_'], $search) . '%';
        $where .= " AND (student_name LIKE :name ESCAPE '!' OR student_identifier LIKE :identifier ESCAPE '!')";
        $params['name']=$like; $params['identifier']=$like;
    }
    // Read one consistent snapshot so summary, policy and records agree.
    $pdo->beginTransaction();
    $settings = attendanceSettings($pdo);
    $stmt = $pdo->prepare('SELECT id,user_id,student_name,year_level,section,student_identifier,year_section_snapshot,
        time_in
        FROM attendance WHERE ' . $where . ' ORDER BY time_in DESC,id DESC');
    $stmt->execute($params);
    $records = $stmt->fetchAll();
    $summary = attendanceCounts($pdo,$date);
    $todaySummary = attendanceCounts($pdo,$today);
    $pdo->commit();
    echo json_encode(['records'=>$records,'date'=>$date,'serverToday'=>$today,'timezone'=>'Asia/Manila','summary'=>$summary,
        'today'=>$todaySummary,'settings'=>$settings,'csrfToken'=>bookCsrfToken(),'formToken'=>attendanceFormToken()]);
} catch (Throwable $exception) { attendanceFailure($pdo, $exception); }
