<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/borrowing.php';

try {
    $input = borrowingPost($pdo, true);
    $name = $input['borrower_name'] ?? '';
    if (!is_string($name) || trim($name) === '' || preg_match('/[\x00-\x1F\x7F]/', $name)
        || ($length = preg_match_all('/./us', trim($name))) === false || $length > 150) {
        throw new DomainException('Enter the student name using at most 150 characters.', 422);
    }
    if (($input['confirm_checkout'] ?? false) !== true) {
        throw new DomainException('Confirm that the physical book is being handed to the student now.', 422);
    }
    $bookId = borrowingId($input['book_id'] ?? null);
    $dueDate = $input['due_date'] ?? '';
    $dueAt = null;
    if ($dueDate !== '' && $dueDate !== null) {
        $parsed = is_string($dueDate) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $dueDate)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate) : false;
        if (!$parsed || $parsed->format('Y-m-d') !== $dueDate || $dueDate < $pdo->query('SELECT CURRENT_DATE()')->fetchColumn()) {
            throw new DomainException('Choose a real due date today or later, or leave it blank for 14 days after checkout.', 422);
        }
        // End-of-day overrides must fit the existing TIMESTAMP column.
        if ($dueDate > '2038-01-18') throw new DomainException('This due date exceeds the database date range. Choose a date before January 19, 2038.', 422);
        $dueAt = $dueDate . ' 23:59:59';
    }
    $nonce = $input['checkout_token'] ?? '';
    if (!is_string($nonce) || !preg_match('/^[a-f0-9]{64}$/', $nonce)) {
        throw new DomainException('Reload the manual checkout form before saving.', 403);
    }
    $fingerprint = hash('sha256', json_encode([trim($name), $bookId, $dueAt]));
    $previous = $_SESSION['manual_checkout_results'][$nonce] ?? null;
    if ($previous) {
        if (!hash_equals($previous['fingerprint'], $fingerprint)) throw new DomainException('This form was already used for another checkout. Reload it.', 409);
        echo json_encode(['success' => true, 'id' => $previous['id'], 'replayed' => true]);
        exit;
    }
    if (!hash_equals(manualCheckoutToken(), $nonce)) throw new DomainException('This checkout form expired. Reload it.', 403);
    $nextToken = bin2hex(random_bytes(32));
    $pdo->beginTransaction();
    $book = lockBorrowingBook($pdo, $bookId);
    if ($dueAt !== null && $dueAt <= $pdo->query('SELECT NOW()')->fetchColumn()) {
        throw new DomainException('The selected due date has passed. Choose a later date.', 422);
    }
    if (borrowingStock($pdo, $book)['requestable_copies'] < 1) {
        throw new DomainException('No unallocated copy is available for this manual checkout.', 409);
    }
    changeBorrowedCopies($pdo, $bookId, 1);
    $stmt = $pdo->prepare(
        'INSERT INTO borrow_requests (borrower_name, book_id, status, approved_at, checked_out_at, due_date)
         VALUES (:name, :book, "checked_out", NOW(), NOW(), COALESCE(:due, DATE_ADD(NOW(), INTERVAL ' . BORROWING_DAYS . ' DAY)))'
    );
    $stmt->execute(['name' => trim($name), 'book' => $bookId, 'due' => $dueAt]);
    $id = (int) $pdo->lastInsertId();
    $pdo->commit();
    $_SESSION['manual_checkout_results'][$nonce] = ['id' => $id, 'fingerprint' => $fingerprint];
    $_SESSION['manual_checkout_results'] = array_slice($_SESSION['manual_checkout_results'], -20, null, true);
    $_SESSION['manual_checkout_token'] = $nextToken;
    echo json_encode(['success' => true, 'id' => $id]);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
