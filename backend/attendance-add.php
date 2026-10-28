<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/attendance.php';
try {
    attendanceAccess($pdo, 'POST');
    $input = bookRequestInput();
    $key = $input['submission_key'] ?? null;
    if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/', $key)
        || !in_array($key, $_SESSION['attendance_keys'] ?? [], true)) {
        throw new DomainException('Reload Attendance to start a fresh form.', 422);
    }
    $mode = $input['mode'] ?? 'registered';
    if (!in_array($mode, ['registered','manual'], true)) throw new DomainException('Choose a valid visitor type.', 422);
    $userId = $mode === 'registered' ? attendanceId($input['user_id'] ?? null) : null;
    $name = $mode === 'manual' ? attendanceText($input['student_name'] ?? null, 150) : '';
    $year = $mode === 'manual' ? attendanceText($input['year_level'] ?? null, 20) : '';
    $section = $mode === 'manual' ? attendanceText($input['section'] ?? '', 25, false) : '';
    $organization = $mode === 'manual' ? attendanceText($input['organization'] ?? '', 50, false) : '';
    if (isset($input['visitor_type']) && !in_array($input['visitor_type'], ['External visitor','BCIHS student','Teacher / faculty','Other visitor'], true)) throw new DomainException('Choose a valid walk-in type.', 422);
    if ($mode === 'manual' && isset($input['visitor_type'])) $year = $input['visitor_type'];
    $hash = hash('sha256', json_encode([$mode,$userId,$name,$year,$section,$organization], JSON_THROW_ON_ERROR));
    $pdo->beginTransaction();
    // One policy lock serializes every entry, departure and capacity change.
    $settings = attendanceSettings($pdo, true);
    $stmt = $pdo->prepare('SELECT id, student_name, time_in, submission_hash FROM attendance WHERE submission_key = :key');
    $stmt->execute(['key'=>$key]);
    $existing = $stmt->fetch();
    if ($existing) {
        if (!hash_equals($existing['submission_hash'], $hash)) throw new DomainException('This form was already used. Reload before recording another visitor.', 409);
        $pdo->commit();
        echo json_encode(['success'=>true,'replayed'=>true,'id'=>$existing['id'],'student_name'=>$existing['student_name'],
            'time_in'=>$existing['time_in'],'formToken'=>attendanceFormToken()]);
        exit;
    }
    $studentIdentifier = null; $yearSection = $organization !== '' ? $organization : null;
    $today = $pdo->query('SELECT CURDATE()')->fetchColumn();
    if ($userId !== null) {
        $stmt = $pdo->prepare('SELECT full_name, student_id, id_number, year_section, department, role, account_status FROM users WHERE id = :id FOR UPDATE');
        $stmt->execute(['id'=>$userId]);
        $user = $stmt->fetch();
        if (!$user || !in_array($user['role'], ['student','teacher'], true) || $user['account_status'] !== 'accepted') throw new DomainException('Select an approved student or teacher account.', 422);
        $name = $user['full_name']; $studentIdentifier = $user['student_id'] ?: $user['id_number']; $yearSection = $user['role'] === 'teacher' ? mb_substr('Teacher/Faculty · ' . ($user['department'] ?: 'Not specified'), 0, 50) : $user['year_section'];
        $stmt = $pdo->prepare('SELECT id FROM attendance WHERE user_id = :id AND tracks_departure = 1 AND time_out IS NULL LIMIT 1');
        $stmt->execute(['id'=>$userId]);
        if ($stmt->fetch()) throw new DomainException('This visitor already has an open visit. Confirm departure before another Time In.', 409);
        $stmt = $pdo->prepare('SELECT id FROM attendance WHERE user_id = :id AND time_in >= :start AND time_in < :end LIMIT 1');
        $stmt->execute(['id'=>$userId,'start'=>$today . ' 00:00:00','end'=>(new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d') . ' 00:00:00']);
        if ($stmt->fetch() && ($input['confirm_repeat'] ?? false) !== true) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['error'=>'This visitor visited today. Confirm that this is a separate return visit.', 'code'=>'repeat_visit_confirmation_required']);
            exit;
        }
    }
    if ($settings['daily_visit_limit'] !== null && attendanceCounts($pdo, $today)['visits'] >= (int)$settings['daily_visit_limit']) {
        throw new DomainException('Today’s visit capacity has been reached.', 409);
    }
    if ($settings['occupancy_limit'] !== null && attendanceOccupancy($pdo) >= (int)$settings['occupancy_limit']) {
        throw new DomainException('Current occupancy capacity has been reached. Record confirmed departures first.', 409);
    }
    $stmt = $pdo->prepare('INSERT INTO attendance (user_id, student_name, year_level, section, student_identifier,
        year_section_snapshot, tracks_departure, submission_key, submission_hash, time_in)
        VALUES (:user,:name,:year,:section,:identifier,:snapshot,:tracking,:key,:hash,NOW())');
    $stmt->execute(['user'=>$userId,'name'=>$name,'year'=>$year,'section'=>$section,'identifier'=>$studentIdentifier,
        'snapshot'=>$yearSection,'tracking'=>$settings['track_departures'],'key'=>$key,'hash'=>$hash]);
    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT time_in FROM attendance WHERE id = :id'); $stmt->execute(['id'=>$id]);
    $time = $stmt->fetchColumn();
    $pdo->commit();
    unset($_SESSION['attendance_form_token']);
    echo json_encode(['success'=>true,'replayed'=>false,'id'=>$id,'student_name'=>$name,'time_in'=>$time,'formToken'=>attendanceFormToken()]);
} catch (Throwable $exception) { attendanceFailure($pdo, $exception); }
