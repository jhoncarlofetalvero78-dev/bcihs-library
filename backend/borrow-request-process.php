<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/approved-user-session.php';
header('Content-Type: application/json');

function fail(int $code, string $message) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

if (!requireApprovedUserSession($pdo)) {
    fail(401, 'account_not_accepted');
}
if (!isset($_SESSION['user_id'])) {
    fail(401, 'not_logged_in');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'method_not_allowed');
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$bookId = isset($input['book_id']) ? (int) $input['book_id'] : 0;

if ($bookId <= 0) {
    fail(422, 'Missing or invalid book.');
}

$book = $pdo->prepare('SELECT id, total_copies, borrowed_copies FROM books WHERE id = :id LIMIT 1');
$book->execute(['id' => $bookId]);
$book = $book->fetch();

if (!$book) {
    fail(404, 'That book no longer exists.');
}
if ((int) $book['total_copies'] - (int) $book['borrowed_copies'] <= 0) {
    fail(422, 'No copies of this book are currently available.');
}

// Don't allow a duplicate pending/approved request for the same book.
$existing = $pdo->prepare(
    "SELECT id FROM borrow_requests
     WHERE user_id = :user_id AND book_id = :book_id AND status IN ('pending','approved')
     LIMIT 1"
);
$existing->execute(['user_id' => $_SESSION['user_id'], 'book_id' => $bookId]);
if ($existing->fetch()) {
    fail(422, 'You already have a request for this book.');
}

$insert = $pdo->prepare(
    'INSERT INTO borrow_requests (user_id, book_id, status) VALUES (:user_id, :book_id, "pending")'
);
$insert->execute(['user_id' => $_SESSION['user_id'], 'book_id' => $bookId]);

echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
