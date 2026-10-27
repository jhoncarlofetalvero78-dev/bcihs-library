<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/borrowing.php';
require_once __DIR__ . '/../includes/reservation-schedule.php';

try {
    $role = requireBookAccess($pdo);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') bookError(405, 'method_not_allowed');
    requireBookCsrf();
    $input = bookRequestInput();
    $id = borrowingId($input['id'] ?? null);
    $action = $input['action'] ?? '';
    $isStaff = in_array($role, ['admin', 'librarian'], true);
    if (!is_string($action) || !in_array($action, ['ready', 'fulfill', 'cancel', 'return', 'schedule'], true)) {
        throw new DomainException('Choose a valid reservation action.', 422);
    }
    if (!$isStaff && !in_array($action, ['cancel', 'schedule'], true)) bookError(403, 'Only library staff can confirm pickup or return.');
    $pickupAt = $action === 'schedule' ? validatePickupAt($pdo, $input['pickup_at'] ?? null) : null;
    if ($action === 'schedule' && $pickupAt === null) throw new DomainException('Choose a valid future weekday pickup time.', 422);
    $lookup = $pdo->prepare('SELECT book_id FROM reservations WHERE id = :id');
    $lookup->execute(['id' => $id]);
    $bookId = $lookup->fetchColumn();
    if ($bookId === false) throw new DomainException('That reservation no longer exists.', 404);

    $pdo->beginTransaction();
    $settings = $action === 'schedule' ? reservationSettings($pdo, true) : null;
    $book = lockBorrowingBook($pdo, (int) $bookId);
    $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $id]);
    $reservation = $stmt->fetch();
    if (!$reservation || (int) $reservation['book_id'] !== (int) $bookId) throw new DomainException('That reservation changed. Reload the page.', 409);
    if (!$isStaff && (int) $reservation['user_id'] !== (int) ($_SESSION['user_id'] ?? 0)) {
        throw new DomainException('You can only change your own reservation.', 403);
    }
    $status = $reservation['status'];
    if (in_array($action, ['ready', 'fulfill'], true)) {
        borrowingUser($pdo, (int) $reservation['user_id']);
        if (hasActiveBorrowing($pdo, (int) $reservation['user_id'], (int) $bookId, 0, $id)) {
            throw new DomainException('This borrower has another active request or loan for this title.', 409);
        }
    }
    if ($action === 'ready') {
        if ($status !== 'waiting') throw new DomainException('Only waiting reservations can be marked ready.', 409);
        $pickup = $reservation['pickup_at'] ? str_replace(' ', 'T', substr($reservation['pickup_at'], 0, 16)) : null;
        if (validatePickupAt($pdo, $pickup) === null) throw new DomainException('The patron must choose a valid future pickup time first.', 409);
        if (borrowingStock($pdo, $book)['requestable_copies'] < 1) throw new DomainException('No unallocated copy is available to hold for this reservation.', 409);
        $sql = 'UPDATE reservations SET status = "ready", ready_at = NOW() WHERE id = :id';
    } elseif ($action === 'fulfill') {
        if (!in_array($status, ['waiting', 'ready'], true)) throw new DomainException('Only waiting or ready reservations can be checked out.', 409);
        $stock = borrowingStock($pdo, $book);
        $available = $status === 'ready'
            ? $stock['available_copies'] > 0 && $stock['held_copies'] <= $stock['available_copies']
            : $stock['requestable_copies'] > 0;
        if (!$available) throw new DomainException('No copy is available for this reservation; other pickup holds are protected.', 409);
        changeBorrowedCopies($pdo, (int) $bookId, 1);
        $sql = 'UPDATE reservations SET status = "fulfilled", fulfilled_at = NOW(), due_date = DATE_ADD(NOW(), INTERVAL ' . BORROWING_DAYS . ' DAY) WHERE id = :id';
    } elseif ($action === 'return') {
        if ($status !== 'fulfilled') throw new DomainException('Only checked-out reservations can be returned.', 409);
        changeBorrowedCopies($pdo, (int) $bookId, -1);
        $sql = 'UPDATE reservations SET status = "returned", returned_at = NOW() WHERE id = :id';
    } elseif ($action === 'cancel') {
        if (!in_array($status, ['waiting', 'ready'], true)) throw new DomainException('Only waiting or ready reservations can be cancelled.', 409);
        $sql = 'UPDATE reservations SET status = "cancelled" WHERE id = :id';
    } else {
        if ($status !== 'waiting') throw new DomainException('Pickup time is final once the reservation is ready.', 409);
        $pickupAt = validatePickupAt($pdo, $input['pickup_at'] ?? null);
        if ($pickupAt === null) throw new DomainException('That pickup time has passed. Choose a future time.', 422);
        requirePickupCapacity($pdo, $settings, $pickupAt, $id);
        $pdo->prepare('UPDATE reservations SET pickup_at = :pickup WHERE id = :id')->execute(['pickup' => $pickupAt, 'id' => $id]);
        $sql = null;
    }
    if ($sql) $pdo->prepare($sql)->execute(['id' => $id]);
    $pdo->commit();
    if ($action === 'ready') {
        notifyLibraryEvent($pdo, (int)$reservation['user_id'], 'reservation_ready', $id,
            ['title' => $book['title'], 'pickup_at' => $reservation['pickup_at']], 'reservation');
    } elseif ($action === 'fulfill') {
        notifyCheckoutEvent($pdo, (int)$reservation['user_id'], $id, $book['title'], 'reservation');
    }
    echo json_encode(['success' => true]);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
