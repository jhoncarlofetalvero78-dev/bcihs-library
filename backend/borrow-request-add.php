<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';
header('Content-Type: application/json');

function fail(int $code, string $message) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

$isLibraryStaff = isset($_SESSION['admin_id']) || (
    isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'librarian'
);
if (!$isLibraryStaff) {
    fail(401, 'not_logged_in');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'method_not_allowed');
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$borrowerName = trim((string) ($input['borrower_name'] ?? ''));
$bookId = isset($input['book_id']) ? (int) $input['book_id'] : 0;
$dueDate = isset($input['due_date']) ? trim((string) $input['due_date']) : '';

if ($borrowerName === '' || mb_strlen($borrowerName) > 150 || $bookId <= 0) {
    fail(422, 'Enter the student name and choose a valid book.');
}

$dueAt = null;
if ($dueDate !== '') {
    $parsed = strtotime($dueDate . ' 23:59:59');
    if ($parsed === false) {
        fail(422, 'The due date is not valid.');
    }
    $dueAt = date('Y-m-d H:i:s', $parsed);
} else {
    $dueAt = date('Y-m-d H:i:s', strtotime('+14 days'));
}

$pdo->beginTransaction();
try {
    $book = $pdo->prepare('SELECT id, total_copies, borrowed_copies FROM books WHERE id = :id LIMIT 1 FOR UPDATE');
    $book->execute(['id' => $bookId]);
    $book = $book->fetch();
    if (!$book) {
        throw new DomainException('That book no longer exists.', 404);
    }
    if ((int) $book['total_copies'] - (int) $book['borrowed_copies'] <= 0) {
        throw new DomainException('No copies of this book are currently available.', 422);
    }

    $insert = $pdo->prepare(
        'INSERT INTO borrow_requests (user_id, borrower_name, book_id, status, approved_at, due_date)
         VALUES (NULL, :borrower_name, :book_id, "approved", NOW(), :due_date)'
    );
    $insert->execute([
        'borrower_name' => $borrowerName,
        'book_id' => $bookId,
        'due_date' => $dueAt,
    ]);

    $pdo->prepare('UPDATE books SET borrowed_copies = borrowed_copies + 1 WHERE id = :book_id')
        ->execute(['book_id' => $bookId]);

    $pdo->commit();
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
} catch (DomainException $e) {
    $pdo->rollBack();
    fail($e->getCode(), $e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Could not add borrow record: ' . $e->getMessage());
    fail(500, 'Could not save that borrow record.');
}
