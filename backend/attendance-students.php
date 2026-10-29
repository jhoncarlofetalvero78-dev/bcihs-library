<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/attendance.php';
try {
    attendanceAccess($pdo, 'GET');
    $search = attendanceText($_GET['search'] ?? '', 100);
    if (mb_strlen($search) < 2) throw new DomainException('Enter at least two characters.', 422);
    $like = '%' . str_replace(['!','%','_'], ['!!','!%','!_'], $search) . '%';
    $stmt = $pdo->prepare("SELECT id, full_name, COALESCE(student_id,id_number) AS student_id, CASE WHEN role='teacher' THEN department ELSE year_section END AS year_section, role FROM users
        WHERE role IN ('student','teacher') AND account_status = 'accepted'
        AND (full_name LIKE :name ESCAPE '!' OR student_id LIKE :student ESCAPE '!' OR id_number LIKE :employee ESCAPE '!') ORDER BY full_name, id LIMIT 20");
    $stmt->execute(['name'=>$like,'student'=>$like,'employee'=>$like]);
    echo json_encode(['students'=>$stmt->fetchAll()]);
} catch (Throwable $exception) { attendanceFailure($pdo, $exception); }
