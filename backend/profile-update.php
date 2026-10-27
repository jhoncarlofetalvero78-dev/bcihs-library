<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/approved-user-session.php';
header('Content-Type: application/json');

if (!requireApprovedUserSession($pdo)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Your account is not accepted.']);
    exit;
}

$accountId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
if (!$accountId || !is_numeric($accountId)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in first.']);
    exit;
}

$userId = (int) $accountId;
$userStmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
$userStmt->execute(['id' => $userId]);
$user = $userStmt->fetch();

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in first.']);
    exit;
}

$role = $user['role'];

function normalizePhilippineMobileNumber(string $phone): ?string
{
    $phone = preg_replace('/[\s().-]/', '', trim($phone));

    if (preg_match('/^09\d{9}$/', $phone)) {
        return $phone;
    }
    if (preg_match('/^\+639\d{9}$/', $phone)) {
        return '0' . substr($phone, 3);
    }

    return null;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = $_POST;
}
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid data.']);
    exit;
}

$fullName = trim((string) ($data['full_name'] ?? ''));
if ($fullName === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Full name is required.']);
    exit;
}

// ID fields (student_id, id_number) are immutable and cannot be changed after creation
$updates = ['full_name' => $fullName];

if ($role === 'student') {
    $yearSection = trim((string) ($data['year_section'] ?? ''));
    $phone = normalizePhilippineMobileNumber((string) ($data['phone'] ?? ''));

    if ($yearSection === '' || $phone === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $phone === null
            ? 'Enter a valid Philippine mobile number: 09XXXXXXXXX or +639XXXXXXXXX.'
            : 'Grade level and phone number are required.']);
        exit;
    }

    $updates['year_section'] = $yearSection;
    $updates['phone'] = $phone;
} elseif ($role === 'teacher' || $role === 'librarian' || $role === 'admin') {
    $phone = normalizePhilippineMobileNumber((string) ($data['phone'] ?? ''));

    if ($phone === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Enter a valid Philippine mobile number: 09XXXXXXXXX or +639XXXXXXXXX.']);
        exit;
    }

    $updates['phone'] = $phone;

    if (array_key_exists('department', $data)) {
        $department = trim((string) ($data['department'] ?? ''));
        $updates['department'] = $department;
    }
} elseif ($role === 'visitor') {
    $email = trim((string) ($data['email'] ?? ''));
    $phone = normalizePhilippineMobileNumber((string) ($data['phone'] ?? ''));

    if ($email === '' || $phone === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $phone === null
            ? 'Enter a valid Philippine mobile number: 09XXXXXXXXX or +639XXXXXXXXX.'
            : 'Email and phone number are required.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
        exit;
    }

    $updates['email'] = $email;
    $updates['phone'] = $phone;
}

$setClauses = [];
$params = ['id' => $userId];
foreach ($updates as $field => $value) {
    $setClauses[] = "$field = :$field";
    $params[$field] = $value;
}

$sql = 'UPDATE users SET ' . implode(', ', $setClauses) . ' WHERE id = :id';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$_SESSION['full_name'] = $fullName;

echo json_encode([
    'success' => true,
    'message' => 'Profile updated successfully.'
]);
