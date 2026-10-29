<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/borrowing.php';

try {
    $input = borrowingPost($pdo);
    if (!isset($_SESSION['user_id'])) bookError(403, 'Sign in with a library patron account to request a book.');
    $userId = (int) $_SESSION['user_id'];
    $bookId = borrowingId($input['book_id'] ?? null);
    $pdo->beginTransaction();
    $book = lockBorrowingBook($pdo, $bookId);
    borrowingUser($pdo, $userId);
    if (hasActiveBorrowing($pdo, $userId, $bookId)) {
        throw new DomainException('You already have an active request, loan, or reservation for this title.', 409);
    }
    if (borrowingStock($pdo, $book)['requestable_copies'] < 1) {
        throw new DomainException('No unallocated copy is available to request. Try a reservation instead.', 409);
    }
    $stmt = $pdo->prepare('INSERT INTO borrow_requests (user_id, book_id, status) VALUES (:user, :book, "pending")');
    $stmt->execute(['user' => $userId, 'book' => $bookId]);
    $id = (int) $pdo->lastInsertId();
    $pdo->commit();
    echo json_encode(['success' => true, 'id' => $id]);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
