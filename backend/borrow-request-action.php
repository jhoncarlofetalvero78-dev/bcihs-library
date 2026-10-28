<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/borrowing.php';
require_once __DIR__ . '/../includes/pickup-qr.php';

try {
    $input = borrowingPost($pdo, true);
    $id = borrowingId($input['id'] ?? null);
    $action = $input['action'] ?? '';
    if (!is_string($action) || !in_array($action, ['approve', 'reject', 'checkout', 'return'], true)) {
        throw new DomainException('Choose a valid borrowing action.', 422);
    }
    $lookup = $pdo->prepare('SELECT book_id FROM borrow_requests WHERE id = :id');
    $lookup->execute(['id' => $id]);
    $bookId = $lookup->fetchColumn();
    if ($bookId === false) throw new DomainException('That request no longer exists.', 404);

    $pdo->beginTransaction();
    // All stock-changing paths lock the book before its request/reservation row.
    $book = lockBorrowingBook($pdo, (int) $bookId);
    $stmt = $pdo->prepare('SELECT * FROM borrow_requests WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $id]);
    $request = $stmt->fetch();
    if (!$request || (int) $request['book_id'] !== (int) $bookId) throw new DomainException('That request changed. Reload the page.', 409);
    $status = $request['status'];
    if (array_key_exists('pickup_token', $input)) {
        $token = pickupToken($input['pickup_token']);
        if ($action !== 'checkout' || !$request['pickup_token'] || !hash_equals($request['pickup_token'], $token)) {
            throw new DomainException('This pickup QR does not match the borrowing request.', 409);
        }
    }
    if (in_array($action, ['approve', 'checkout'], true) && $request['user_id']) {
        borrowingUser($pdo, (int) $request['user_id']);
        if (hasActiveBorrowing($pdo, (int) $request['user_id'], (int) $bookId, $id)) {
            throw new DomainException('This borrower has another active loan or reservation for the same title.', 409);
        }
    }

    if ($action === 'approve') {
        if ($status !== 'pending') throw new DomainException('Only pending requests can be approved.', 409);
        if (borrowingStock($pdo, $book)['requestable_copies'] < 1) throw new DomainException('No unallocated copy is available to hold for pickup.', 409);
        $sql = 'UPDATE borrow_requests SET status = "awaiting_pickup", approved_at = NOW(), pickup_token = COALESCE(pickup_token,:pickup_token) WHERE id = :id';
    } elseif ($action === 'reject') {
        if (!in_array($status, ['pending', 'awaiting_pickup'], true)) throw new DomainException('Only pending or awaiting-pickup requests can be rejected.', 409);
        $sql = 'UPDATE borrow_requests SET status = "rejected", rejected_at = NOW() WHERE id = :id';
    } elseif ($action === 'checkout') {
        if ($status !== 'awaiting_pickup') throw new DomainException('Approve this request before confirming checkout. It must still be awaiting pickup.', 409);
        $stock = borrowingStock($pdo, $book);
        if ($stock['available_copies'] < 1 || $stock['held_copies'] > $stock['available_copies']) {
            throw new DomainException('Pickup holds exceed physical stock. Review inventory before checkout.', 409);
        }
        changeBorrowedCopies($pdo, (int) $bookId, 1);
        $sql = 'UPDATE borrow_requests SET status = "checked_out", checked_out_at = NOW(), due_date = DATE_ADD(NOW(), INTERVAL ' . BORROWING_DAYS . ' DAY) WHERE id = :id';
    } else {
        // "approved" is retained only for historical loans checked out by the old system.
        if (!in_array($status, ['checked_out', 'approved'], true)) throw new DomainException('Only checked-out books can be returned.', 409);
        changeBorrowedCopies($pdo, (int) $bookId, -1);
        $sql = 'UPDATE borrow_requests SET status = "returned", returned_at = NOW() WHERE id = :id';
    }
    $parameters = ['id' => $id];
    if ($action === 'approve') $parameters['pickup_token'] = bin2hex(random_bytes(32));
    $pdo->prepare($sql)->execute($parameters);
    $pdo->commit();
    $recipient = $request['user_id'] ? (int)$request['user_id'] : null;
    if ($action === 'approve' || $action === 'reject') {
        notifyLibraryEvent($pdo, $recipient, $action === 'approve' ? 'borrow_ready' : 'borrow_rejected', $id, ['title' => $book['title']]);
    } elseif ($action === 'checkout') {
        notifyCheckoutEvent($pdo, $recipient, $id, $book['title']);
    }
    echo json_encode(['success' => true]);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
