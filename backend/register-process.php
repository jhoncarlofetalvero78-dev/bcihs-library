<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';

$allowedRoles = ['student', 'teacher', 'librarian', 'visitor'];

function backToRegister(string $role, string $error) {
    $qs = http_build_query(array_filter(['role' => $role, 'error' => $error]));
    header('Location: ../select-role.html' . ($qs ? '?' . $qs : ''));
    exit;
}

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

function executeRegistrationQuery(PDO $pdo, string $sql, array $parameters, string $role): PDOStatement
{
    try {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    } catch (PDOException $e) {
        error_log('Registration database operation failed: ' . $e->getMessage());
        backToRegister($role, 'Registration could not be completed. Please try again later.');
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../select-role.html');
    exit;
}

$role = isset($_POST['role']) ? preg_replace('/[^a-z]/', '', strtolower($_POST['role'])) : '';
if (!in_array($role, $allowedRoles, true)) {
    $role = 'student';
}

$fullName = trim($_POST['full_name'] ?? '');
$password = $_POST['password'] ?? '';
$confirm  = $_POST['confirm_password'] ?? '';

if ($role === 'student') {

    $studentId   = trim($_POST['student_id'] ?? '');
    $gradeLevel  = trim($_POST['grade_level'] ?? '');
    $section     = trim($_POST['section'] ?? '');
    $yearSection = $gradeLevel !== '' && $section !== ''
        ? $gradeLevel . ' — ' . $section
        : trim($_POST['year_section'] ?? '');
    $phone       = normalizePhilippineMobileNumber($_POST['phone'] ?? '');

    if ($studentId === '' || $fullName === '' || $yearSection === '' || $phone === null || $password === '' || $confirm === '') {
        if ($phone === null && trim($_POST['phone'] ?? '') !== '') {
            backToRegister($role, 'Enter a valid Philippine mobile number: 09XXXXXXXXX or +639XXXXXXXXX.');
        }
        backToRegister($role, 'Please fill in every field.');
    }
    $yearSectionLength = preg_match_all('/./us', $yearSection);
    if ($yearSectionLength === false || $yearSectionLength > 50) {
        backToRegister($role, 'Grade level and section must be 50 characters or fewer combined.');
    }
    if (strlen($password) < 8) {
        backToRegister($role, 'Password must be at least 8 characters.');
    }
    if ($password !== $confirm) {
        backToRegister($role, 'Passwords do not match.');
    }

    // Make sure the Student ID isn't already registered.
    $check = executeRegistrationQuery(
        $pdo,
        'SELECT id FROM users WHERE student_id = :student_id LIMIT 1',
        ['student_id' => $studentId],
        $role
    );
    if ($check->fetch()) {
        backToRegister($role, 'An account with that Student ID already exists.');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    executeRegistrationQuery(
        $pdo,
        'INSERT INTO users (full_name, student_id, year_section, phone, password_hash, role)
         VALUES (:full_name, :student_id, :year_section, :phone, :password_hash, :role)',
        [
            'full_name'     => $fullName,
            'student_id'    => $studentId,
            'year_section'  => $yearSection,
            'phone'         => $phone,
            'password_hash' => $passwordHash,
            'role'          => $role,
        ],
        $role
    );

    header('Location: ../login.html?registered=1');
    exit;
}

// ---- Teacher, Librarian, and Staff: ID Number-based registration ----

if ($role === 'teacher' || $role === 'librarian') {

    $idNumber = trim($_POST['id_number'] ?? '');
    $phone    = normalizePhilippineMobileNumber($_POST['phone'] ?? '');
    $department = trim($_POST['department'] ?? '');

    if ($idNumber === '' || $fullName === '' || $phone === null || $password === '' || $confirm === '') {
        if ($phone === null && trim($_POST['phone'] ?? '') !== '') {
            backToRegister($role, 'Enter a valid Philippine mobile number: 09XXXXXXXXX or +639XXXXXXXXX.');
        }
        backToRegister($role, 'Please fill in every field.');
    }
    if (strlen($password) < 8) {
        backToRegister($role, 'Password must be at least 8 characters.');
    }
    if ($password !== $confirm) {
        backToRegister($role, 'Passwords do not match.');
    }

    // Make sure the ID Number isn't already registered.
    $check = executeRegistrationQuery(
        $pdo,
        'SELECT id FROM users WHERE id_number = :id_number LIMIT 1',
        ['id_number' => $idNumber],
        $role
    );
    if ($check->fetch()) {
        backToRegister($role, 'An account with that ID Number already exists.');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    executeRegistrationQuery(
        $pdo,
        'INSERT INTO users (full_name, id_number, phone, department, password_hash, role)
         VALUES (:full_name, :id_number, :phone, :department, :password_hash, :role)',
        [
            'full_name'     => $fullName,
            'id_number'     => $idNumber,
            'phone'         => $phone,
            'department'    => $department ?: null,
            'password_hash' => $passwordHash,
            'role'          => $role,
        ],
        $role
    );

    // Accounts require approval before signing in.
    header('Location: ../login.html?registered=1');
    exit;
}

// ---- Visitor: email-based registration ----

$email = trim($_POST['email'] ?? '');
$phone = normalizePhilippineMobileNumber($_POST['phone'] ?? '');

if ($fullName === '' || $email === '' || $phone === null || $password === '' || $confirm === '') {
    if ($phone === null && trim($_POST['phone'] ?? '') !== '') {
        backToRegister($role, 'Enter a valid Philippine mobile number: 09XXXXXXXXX or +639XXXXXXXXX.');
    }
    backToRegister($role, 'Please fill in every field.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    backToRegister($role, 'Please enter a valid email address.');
}
if (strlen($password) < 8) {
    backToRegister($role, 'Password must be at least 8 characters.');
}
if ($password !== $confirm) {
    backToRegister($role, 'Passwords do not match.');
}

// Make sure the email isn't already registered.
$check = executeRegistrationQuery(
    $pdo,
    'SELECT id FROM users WHERE email = :email LIMIT 1',
    ['email' => $email],
    $role
);
if ($check->fetch()) {
    backToRegister($role, 'An account with that email already exists.');
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

executeRegistrationQuery(
    $pdo,
    'INSERT INTO users (full_name, email, phone, password_hash, role)
     VALUES (:full_name, :email, :phone, :password_hash, :role)',
    [
        'full_name'      => $fullName,
        'email'          => $email,
        'phone'          => $phone,
        'password_hash'  => $passwordHash,
        'role'           => $role,
    ],
    $role
);

header('Location: ../login.html?registered=1');
exit;