<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/borrowing.php';

try {
    $input = borrowingPost($pdo, true);
    $name = $input['borrower_name'] ?? '';
    if (!is_string($name) || trim($name) === '' || preg_match('/[\x00-\x1F\x7F]/', $name)
        || ($length = preg_match_all('/./us', trim($name))) === false || $length > 150) {
        throw new DomainException('Enter the borrower name using at most 150 characters.', 422);
    }
    if (($input['confirm_checkout'] ?? false) !== true) {
        throw new DomainException('Confirm that the physical book is being handed to the borrower now.', 422);
    }
    $bookId = borrowingId($input['book_id'] ?? null);
    $selection = $input['due_preview_token'] ?? ($input['due_date'] ?? null);
    if ($selection !== null && !is_string($selection)) throw new DomainException('Choose a valid due-date selection.', 422);
    $nonce = $input['checkout_token'] ?? '';
    if (!is_string($nonce) || !preg_match('/^[a-f0-9]{64}$/', $nonce)) {
        throw new DomainException('Reload the manual checkout form before saving.', 403);
    }
    $fingerprint = hash('sha256', json_encode([trim($name), $bookId, $selection]));
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
    $checkoutAt = $pdo->query('SELECT NOW()')->fetchColumn();
    $dueAt = checkoutLoanDue($input, 'manual', $bookId, $checkoutAt);
    if (borrowingStock($pdo, $book)['requestable_copies'] < 1) {
        throw new DomainException('No unallocated copy is available for this manual checkout.', 409);
    }
    changeBorrowedCopies($pdo, $bookId, 1);
    $stmt = $pdo->prepare(
        'INSERT INTO borrow_requests (borrower_name, book_id, status, approved_at, checked_out_at, due_date)
         VALUES (:name, :book, "checked_out", :approved_at, :checkout_at, :due)'
    );
    $stmt->execute(['name' => trim($name), 'book' => $bookId, 'due' => $dueAt, 'approved_at'=>$checkoutAt, 'checkout_at'=>$checkoutAt]);
    $id = (int) $pdo->lastInsertId();
    $pdo->commit();
    $_SESSION['manual_checkout_results'][$nonce] = ['id' => $id, 'fingerprint' => $fingerprint];
    $_SESSION['manual_checkout_results'] = array_slice($_SESSION['manual_checkout_results'], -20, null, true);
    $_SESSION['manual_checkout_token'] = $nextToken;
    echo json_encode(['success' => true, 'id' => $id, 'due_date'=>$dueAt]);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
