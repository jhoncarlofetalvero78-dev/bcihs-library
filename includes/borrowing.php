<?php
require_once __DIR__ . '/book-management.php';
require_once __DIR__ . '/sms.php';

// Use Philippine library time without changing MySQL's global configuration.
date_default_timezone_set('Asia/Manila');
try {
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $exception) {
    error_log('Could not initialize library time.');
    bookError(500, 'The library service could not start. Please try again.');
}
const BORROWING_DAYS = 14;

function borrowingId($value): int
{
    if ((!is_string($value) && !is_int($value))
        || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]) === false) {
        throw new DomainException('Choose a valid record or book.', 422);
    }
    return (int) $value;
}

function borrowingPost(PDO $pdo, bool $staffOnly = false): array
{
    requireBookAccess($pdo, $staffOnly);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') bookError(405, 'method_not_allowed');
    requireBookCsrf();
    return bookRequestInput();
}

function borrowingUser(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT id, full_name, phone, account_status, role FROM users WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $id]);
    $user = $stmt->fetch();
    if (!$user || $user['account_status'] !== 'accepted' || !in_array($user['role'], ['student','teacher','librarian','staff','employee'], true)) {
        throw new DomainException('The borrower must have an approved school borrower account. Visitors need library staff assistance.', 403);
    }
    return $user;
}

function lockBorrowingBook(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT id, title, total_copies, borrowed_copies FROM books WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $id]);
    $book = $stmt->fetch();
    if (!$book) throw new DomainException('That book no longer exists.', 404);
    return $book;
}

function borrowingStock(PDO $pdo, array $book): array
{
    $holds = $pdo->prepare(
        'SELECT (SELECT COUNT(*) FROM borrow_requests WHERE book_id = :borrow_book AND status = "awaiting_pickup")
              + (SELECT COUNT(*) FROM reservations WHERE book_id = :reserved_book AND status = "ready")'
    );
    $holds->execute(['borrow_book' => $book['id'], 'reserved_book' => $book['id']]);
    $held = (int) $holds->fetchColumn();
    $physical = (int) $book['total_copies'] - (int) $book['borrowed_copies'];
    return ['available_copies' => $physical, 'held_copies' => $held, 'requestable_copies' => max(0, $physical - $held)];
}

function hasActiveBorrowing(PDO $pdo, int $userId, int $bookId, int $ignoreBorrow = 0, int $ignoreReservation = 0): bool
{
    $stmt = $pdo->prepare(
        'SELECT EXISTS(SELECT 1 FROM borrow_requests WHERE user_id = :borrow_user AND book_id = :borrow_book
                       AND id <> :ignore_borrow AND status IN ("pending","awaiting_pickup","checked_out","approved"))
             OR EXISTS(SELECT 1 FROM reservations WHERE user_id = :reserved_user AND book_id = :reserved_book
                       AND id <> :ignore_reservation AND status IN ("waiting","ready","fulfilled"))'
    );
    $stmt->execute(['borrow_user' => $userId, 'borrow_book' => $bookId, 'ignore_borrow' => $ignoreBorrow,
        'reserved_user' => $userId, 'reserved_book' => $bookId, 'ignore_reservation' => $ignoreReservation]);
    return (bool) $stmt->fetchColumn();
}

function changeBorrowedCopies(PDO $pdo, int $bookId, int $change): void
{
    $sql = $change === 1
        ? 'UPDATE books SET borrowed_copies = borrowed_copies + 1 WHERE id = :id AND borrowed_copies < total_copies'
        : 'UPDATE books SET borrowed_copies = borrowed_copies - 1 WHERE id = :id AND borrowed_copies > 0';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $bookId]);
    if ($stmt->rowCount() !== 1) {
        throw new DomainException($change === 1 ? 'No physical copy is available for checkout.'
            : 'The recorded stock needs librarian review. This return was not applied.', 409);
    }
}

function borrowingFailure(PDO $pdo, Throwable $exception): void
{
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($exception instanceof DomainException) bookError($exception->getCode() ?: 422, $exception->getMessage());
    error_log('Library borrowing operation failed.');
    bookError(500, 'Could not complete this library action. Reload and try again.');
}

function notifyBorrower(PDO $pdo, ?int $userId, string $message): void
{
    if (!$userId) return;
    try {
        require_once __DIR__ . '/sms.php';
        $stmt = $pdo->prepare('SELECT phone FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $phone = $stmt->fetchColumn();
        if ($phone !== false) send_sms($pdo, $userId, $phone, $message);
    } catch (Throwable $exception) {
        // A notification failure must not undo or report failure for a committed library action.
        error_log('Library notification could not be recorded.');
    }
}

// This expression is used only with the fixed books alias "b" in read-only queries.
const BOOK_HOLDS_SQL = '(SELECT COUNT(*) FROM borrow_requests h WHERE h.book_id = b.id AND h.status = "awaiting_pickup")
                     + (SELECT COUNT(*) FROM reservations h WHERE h.book_id = b.id AND h.status = "ready")';

function addBookAvailability(array $books): array
{
    foreach ($books as &$book) {
        $book['available_copies'] = (int) $book['total_copies'] - (int) $book['borrowed_copies'];
        $book['held_copies'] = (int) $book['held_copies'];
        $book['requestable_copies'] = max(0, $book['available_copies'] - $book['held_copies']);
    }
    return $books;
}

function manualCheckoutToken(): string
{
    if (empty($_SESSION['manual_checkout_token'])) {
        $_SESSION['manual_checkout_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['manual_checkout_token'];
}
