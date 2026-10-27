<?php
function bookError(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

function requireBookAccess(PDO $pdo, bool $staffOnly = false): void
{
    if (isset($_SESSION['admin_id'])) {
        $stmt = $pdo->prepare('SELECT id FROM admins WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $_SESSION['admin_id']]);
        if ($stmt->fetch()) {
            return;
        }
    }
    if (!isset($_SESSION['user_id'])) {
        bookError(401, 'not_logged_in');
    }
    $stmt = $pdo->prepare('SELECT role, account_status FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user || $user['account_status'] !== 'accepted') {
        bookError(401, 'account_not_accepted');
    }
    if ($staffOnly && $user['role'] !== 'librarian') {
        bookError(403, 'Only library staff can change books.');
    }
}

function requireBookCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($token === '' || !isset($_SESSION['book_csrf_token'])
        || !hash_equals($_SESSION['book_csrf_token'], $token)) {
        bookError(403, 'Your form session has expired. Reload Manage Books and try again.');
    }
}

function bookRequestInput(): array
{
    if ($_POST) {
        return $_POST;
    }
    $raw = file_get_contents('php://input');
    if (trim($raw) === '') {
        return [];
    }
    $input = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($input) || substr(ltrim($raw), 0, 1) !== '{') {
        bookError(400, 'Send a valid book form or JSON object.');
    }
    return $input;
}

function removeBookPhoto(?string $path): void
{
    if (!$path || !preg_match('#^uploads/books/[a-f0-9]{32}\.(jpg|png|webp)$#', $path)) {
        return;
    }
    $file = dirname(__DIR__) . '/' . $path;
    if (is_file($file) && !unlink($file)) {
        error_log('Could not remove a book photo.');
    }
}
