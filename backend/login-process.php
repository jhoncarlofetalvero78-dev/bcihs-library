<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';

function backToSignin(string $error): void
{
    $role = $_POST['role'] ?? '';
    $allowedRoles = ['student', 'teacher', 'librarian', 'visitor'];
    $params = ['error' => $error];
    if (in_array($role, $allowedRoles, true)) {
        $params['role'] = $role;
    }
    $bookId = $_POST['book'] ?? '';
    if (is_string($bookId) && ctype_digit($bookId) && (int) $bookId > 0) {
        $params['book'] = (int) $bookId;
    }
    header('Location: ../login.html?' . http_build_query($params));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    backToSignin('');
}

$identifier = trim($_POST['identifier'] ?? '');
$password = $_POST['password'] ?? '';
$remember = isset($_POST['remember']);

if ($identifier === '' || $password === '') {
    backToSignin('Please fill in both fields.');
}

$userStmt = $pdo->prepare(
    'SELECT id, full_name, student_id, id_number, email, password_hash, role, account_status
     FROM users
     WHERE student_id = :student_id OR id_number = :id_number OR email = :email
     LIMIT 1'
);
$userStmt->execute([
    'student_id' => $identifier,
    'id_number' => $identifier,
    'email' => $identifier,
]);
$user = $userStmt->fetch();

$adminStmt = $pdo->prepare(
    'SELECT id, username, password_hash FROM admins WHERE username = :username LIMIT 1'
);
$adminStmt->execute(['username' => $identifier]);
$admin = $adminStmt->fetch();

if ($admin && password_verify($password, $admin['password_hash'])) {
    $accountType = 'admin';
    $account = $admin;
} elseif ($user && password_verify($password, $user['password_hash'])) {
    $accountType = 'user';
    $account = $user;
} else {
    backToSignin('Incorrect username or password.');
}
if ($accountType === 'user' && $account['account_status'] !== 'accepted') {
    $message = $account['account_status'] === 'rejected'
        ? 'Your account was rejected. Please contact the library for assistance.'
        : 'Your account is awaiting approval. You can sign in after it has been accepted.';
    backToSignin($message);
}

session_regenerate_id(true);
foreach (['admin_id', 'admin_username', 'user_id', 'full_name', 'role', 'student_id', 'id_number', 'email'] as $key) {
    unset($_SESSION[$key]);
}

if ($accountType === 'admin') {
    $_SESSION['admin_id'] = $account['id'];
    $_SESSION['admin_username'] = $account['username'];
    $destination = '../admin-dashboard.html';
} else {
    $_SESSION['user_id'] = $account['id'];
    $_SESSION['full_name'] = $account['full_name'];
    $_SESSION['role'] = $account['role'];
    if ($account['role'] === 'student') {
        $_SESSION['student_id'] = $account['student_id'];
    } elseif ($account['role'] === 'teacher' || $account['role'] === 'librarian') {
        $_SESSION['id_number'] = $account['id_number'];
    } else {
        $_SESSION['email'] = $account['email'];
    }
    $bookId = $_POST['book'] ?? '';
    $destination = is_string($bookId) && ctype_digit($bookId) && (int) $bookId > 0
        ? '../browse-books.html?book=' . (int) $bookId
        : '../index.html';
}

if ($remember) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        session_id(),
        time() + 60 * 60 * 24 * 30,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

header('Location: ' . $destination);
exit;
