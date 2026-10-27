<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/approved-user-session.php';
require_once __DIR__ . '/../includes/reservation-schedule.php';
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
$pickupAt = validatePickupAt($pdo, $input['pickup_at'] ?? null);

if ($bookId <= 0) {
    fail(422, 'Missing or invalid book.');
}
if ($pickupAt === null) {
    fail(422, 'Choose a future weekday and a pickup time between 7:00 AM and 4:00 PM in 30-minute intervals.');
}

$book = $pdo->prepare('SELECT id, total_copies, borrowed_copies FROM books WHERE id = :id LIMIT 1');
$book->execute(['id' => $bookId]);
$book = $book->fetch();

if (!$book) {
    fail(404, 'That book no longer exists.');
}

// Reservations are only for books with nothing available right now —
// if copies are free, the patron should request to borrow instead.
if ((int) $book['total_copies'] - (int) $book['borrowed_copies'] > 0) {
    fail(422, 'This book currently has copies available — request to borrow it instead.');
}

// Don't allow a duplicate active reservation for the same book.
$existing = $pdo->prepare(
    "SELECT id FROM reservations
     WHERE user_id = :user_id AND book_id = :book_id AND status IN ('waiting','ready')
     LIMIT 1"
);
$existing->execute(['user_id' => $_SESSION['user_id'], 'book_id' => $bookId]);
if ($existing->fetch()) {
    fail(422, 'You already have a reservation for this book.');
}

$insert = $pdo->prepare(
    'INSERT INTO reservations (user_id, book_id, status, pickup_at)
     VALUES (:user_id, :book_id, "waiting", :pickup_at)'
);
$insert->execute([
    'user_id' => $_SESSION['user_id'],
    'book_id' => $bookId,
    'pickup_at' => $pickupAt,
]);

echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
