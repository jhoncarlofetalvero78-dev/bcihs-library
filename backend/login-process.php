<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db_connect.php';

function loginPickupToken(): ?string
{
    $token = $_POST['pickup'] ?? null;
    return is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) ? $token : null;
}

function backToSignin(string $error): void
{
    if (($token = loginPickupToken()) || !empty($GLOBALS['staffLoginOnly'])) {
        header('Location: ../admin-login.html?' . http_build_query(array_filter(['pickup' => $token, 'error' => $error], static fn($value) => $value !== null)));
        exit;
    }
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

$identifier = is_string($_POST['identifier'] ?? null) ? trim($_POST['identifier']) : '';
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
$remember = isset($_POST['remember']);
if ($identifier === '' || $password === '' || strlen($identifier) > 190 || strlen($password) > 72) {
    backToSignin('Enter your sign-in identifier and password.');
}
try {
    if (!securityBudget('login-ip', $_SERVER['REMOTE_ADDR'] ?? 'local', 30, 900)
        || !securityBudget('login-identifier', strtolower($identifier), 10, 900)) {
        backToSignin('Too many sign-in attempts. Please wait 15 minutes and try again.');
    }
} catch (RuntimeException $exception) {
    backToSignin('Sign-in is temporarily unavailable. Please try again later.');
}
$userStmt = $pdo->prepare(
    'SELECT id, full_name, student_id, id_number, email, password_hash, role, account_status
     FROM users WHERE student_id = :student OR id_number = :employee OR email = :email LIMIT 2'
);
$userStmt->execute(['student' => $identifier, 'employee' => $identifier, 'email' => $identifier]);
$users = $userStmt->fetchAll();
$adminStmt = $pdo->prepare('SELECT id, username, password_hash FROM admins WHERE username = :username LIMIT 2');
$adminStmt->execute(['username' => $identifier]);
$admins = $adminStmt->fetchAll();
// A colliding identifier must never silently select an account or privileged role.
if (count($users) + count($admins) !== 1) backToSignin('Incorrect sign-in details.');
$accountType = $admins ? 'admin' : 'user';
$account = $admins[0] ?? $users[0];
if (!password_verify($password, $account['password_hash'])) backToSignin('Incorrect sign-in details.');
if ((!empty($staffLoginOnly) || loginPickupToken()) && $accountType !== 'admin' && $account['role'] !== 'librarian') {
    backToSignin('Only authorized library staff may sign in here. Use the Student Portal for borrower access.');
}
if ($accountType === 'user' && $account['account_status'] !== 'accepted') {
    $message = $account['account_status'] === 'rejected'
        ? 'Your account was rejected. Please contact the library for assistance.'
        : 'Your account is awaiting approval. You can sign in after it has been accepted.';
    backToSignin($message);
}

session_regenerate_id(true);
$_SESSION['password_fingerprint'] = hash('sha256', $account['password_hash']);
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

if ($accountType === 'user' && $account['role'] === 'librarian') $destination = '../admin-dashboard.html';

if (loginPickupToken() && ($accountType === 'admin' || $account['role'] === 'librarian')) {
    $destination = '../pickup-verification.html?token=' . loginPickupToken();
}

if ($remember) {
    $params = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires' => time() + 60 * 60 * 24 * 30,
        'path' => $params['path'], 'domain' => $params['domain'],
        'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

header('Location: ' . $destination);
exit;
