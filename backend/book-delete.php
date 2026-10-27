<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/book-management.php';

try {
    requireBookAccess($pdo, true);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        bookError(405, 'method_not_allowed');
    }
    requireBookCsrf();
    $input = bookRequestInput();
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    if ((!is_string($input['id'] ?? null) && !is_int($input['id'] ?? null)) || $id === false) {
        bookError(422, 'Missing or invalid book id.');
    }

    $pdo->beginTransaction();
    // Lock the parent row so a concurrent loan cannot slip past the history check.
    $lookup = $pdo->prepare('SELECT image_path, borrowed_copies FROM books WHERE id = :id FOR UPDATE');
    $lookup->execute(['id' => $id]);
    $book = $lookup->fetch();
    if (!$book) {
        $pdo->rollBack();
        bookError(404, 'That book no longer exists.');
    }
    $history = $pdo->prepare(
        'SELECT EXISTS(SELECT 1 FROM borrow_requests WHERE book_id = :borrow_book)
             OR EXISTS(SELECT 1 FROM reservations WHERE book_id = :reserved_book)'
    );
    $history->execute(['borrow_book' => $id, 'reserved_book' => $id]);
    if ((int) $book['borrowed_copies'] > 0 || (bool) $history->fetchColumn()) {
        $pdo->rollBack();
        bookError(409, 'This book has borrowed copies or transaction history and cannot be deleted. Keep it to preserve library records.');
    }
    $stmt = $pdo->prepare('DELETE FROM books WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $pdo->commit();
    removeBookPhoto($book['image_path']);
    echo json_encode(['success' => true]);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Book deletion failed.');
    bookError(500, 'Could not delete the book. Please try again.');
}
