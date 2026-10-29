<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/borrowing.php';
require_once __DIR__ . '/../includes/reservation-schedule.php';

try {
    $input = borrowingPost($pdo);
    if (!isset($_SESSION['user_id'])) bookError(403, 'Sign in with a library patron account to reserve a book.');
    $userId = (int) $_SESSION['user_id'];
    $bookId = borrowingId($input['book_id'] ?? null);
    $pickupAt = validatePickupAt($pdo, $input['pickup_at'] ?? null);
    if ($pickupAt === null) throw new DomainException('Choose a future weekday pickup from 7:00 AM to 4:00 PM in 30-minute intervals.', 422);
    $pdo->beginTransaction();
    $settings = reservationSettings($pdo, true);
    $book = lockBorrowingBook($pdo, $bookId);
    $pickupAt = validatePickupAt($pdo, $input['pickup_at'] ?? null);
    if ($pickupAt === null) throw new DomainException('That pickup time has passed. Choose a future time.', 422);
    borrowingUser($pdo, $userId);
    if (hasActiveBorrowing($pdo, $userId, $bookId)) {
        throw new DomainException('You already have an active request, loan, or reservation for this title.', 409);
    }
    if (borrowingStock($pdo, $book)['requestable_copies'] > 0) {
        throw new DomainException('A copy is available to request. Use Request to Borrow instead.', 409);
    }
    requirePickupCapacity($pdo, $settings, $pickupAt);
    $stmt = $pdo->prepare('INSERT INTO reservations (user_id, book_id, status, pickup_at) VALUES (:user, :book, "waiting", :pickup)');
    $stmt->execute(['user' => $userId, 'book' => $bookId, 'pickup' => $pickupAt]);
    $id = (int) $pdo->lastInsertId();
    $pdo->commit();
    echo json_encode(['success' => true, 'id' => $id]);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
