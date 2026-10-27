<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/borrowing.php';

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
    if (in_array($action, ['approve', 'checkout'], true) && $request['user_id']) {
        borrowingUser($pdo, (int) $request['user_id']);
        if (hasActiveBorrowing($pdo, (int) $request['user_id'], (int) $bookId, $id)) {
            throw new DomainException('This borrower has another active loan or reservation for the same title.', 409);
        }
    }

    if ($action === 'approve') {
        if ($status !== 'pending') throw new DomainException('Only pending requests can be approved.', 409);
        if (borrowingStock($pdo, $book)['requestable_copies'] < 1) throw new DomainException('No unallocated copy is available to hold for pickup.', 409);
        $sql = 'UPDATE borrow_requests SET status = "awaiting_pickup", approved_at = NOW() WHERE id = :id';
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
    $pdo->prepare($sql)->execute(['id' => $id]);
    $pdo->commit();
    if ($action === 'approve' || $action === 'reject') {
        $message = $action === 'approve'
            ? 'is APPROVED and held for pickup. It is not yet checked out; library staff must confirm physical handover.'
            : 'was NOT APPROVED. Please visit the library for details.';
        notifyBorrower($pdo, $request['user_id'] ? (int) $request['user_id'] : null,
            'BCIHS Library: Your request for "' . $book['title'] . '" ' . $message);
    }
    echo json_encode(['success' => true]);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
