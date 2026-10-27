<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/reservation-schedule.php';
header('Content-Type: application/json');

function fail(int $code, string $message) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    fail(401, 'not_logged_in');
}
$isAdmin = isset($_SESSION['admin_id']) || (
    isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'librarian'
);
$isUser = isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') !== 'librarian';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'method_not_allowed');
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$id     = isset($input['id']) ? (int) $input['id'] : 0;
$action = $input['action'] ?? '';

if ($id <= 0 || !in_array($action, ['ready', 'fulfill', 'cancel', 'return', 'schedule'], true)) {
    fail(422, 'Missing or invalid request.');
}
$pickupAt = $action === 'schedule'
    ? validatePickupAt($pdo, $input['pickup_at'] ?? null)
    : null;
if ($action === 'schedule' && $pickupAt === null) {
    fail(422, 'Choose a future weekday and a pickup time between 7:00 AM and 4:00 PM in 30-minute intervals.');
}

$stmt = $pdo->prepare(
    'SELECT r.id, r.status, r.book_id, r.pickup_at, b.total_copies, b.borrowed_copies,
            b.title AS book_title, u.id AS user_id, u.full_name, u.phone
     FROM reservations r
     JOIN books b ON b.id = r.book_id
     JOIN users u ON u.id = r.user_id
     WHERE r.id = :id LIMIT 1'
);
$stmt->execute(['id' => $id]);
$reservation = $stmt->fetch();

if (!$reservation) {
    fail(404, 'That reservation no longer exists.');
}

// Users can only cancel their own reservations.
if ($isUser && !$isAdmin && $action === 'cancel') {
    if ($reservation['user_id'] != $_SESSION['user_id']) {
        fail(403, 'You can only cancel your own reservations.');
    }
}
if ($isUser && !$isAdmin && $action === 'schedule') {
    if ($reservation['user_id'] != $_SESSION['user_id']) {
        fail(403, 'You can only change the preferred pickup time for your own reservation.');
    }
}

// Admins and librarians can do everything; users can only cancel.
if ($isUser && !$isAdmin && in_array($action, ['ready', 'fulfill', 'return'], true)) {
    fail(403, 'Only administrators can perform that action.');
}

$pdo->beginTransaction();

try {
    if ($action === 'ready') {
        $lock = $pdo->prepare('SELECT status, pickup_at FROM reservations WHERE id = :id FOR UPDATE');
        $lock->execute(['id' => $id]);
        $current = $lock->fetch();
        if (!$current || $current['status'] !== 'waiting') {
            throw new RuntimeException('Only waiting reservations can be marked ready.');
        }
        if (!$current['pickup_at']) {
            throw new RuntimeException('The user must choose a preferred pickup time before this reservation can be marked ready.');
        }
        $pickupInput = str_replace(' ', 'T', substr($current['pickup_at'], 0, 16));
        $validPickupAt = validatePickupAt($pdo, $pickupInput);
        if ($validPickupAt === null) {
            throw new RuntimeException('The preferred pickup time has passed or is unavailable. Ask the user to choose a new time before marking this reservation ready.');
        }
        $reservation['pickup_at'] = $current['pickup_at'];
        $pdo->prepare('UPDATE reservations SET status = "ready", ready_at = NOW() WHERE id = :id AND status = "waiting"')
            ->execute(['id' => $id]);

    } elseif ($action === 'fulfill') {
        if (!in_array($reservation['status'], ['waiting', 'ready'], true)) {
            throw new RuntimeException('Only waiting or ready reservations can be fulfilled.');
        }
        $available = (int) $reservation['total_copies'] - (int) $reservation['borrowed_copies'];
        if ($available <= 0) {
            throw new RuntimeException('No copies of this book are currently available to fulfill.');
        }
        $pdo->prepare('UPDATE books SET borrowed_copies = borrowed_copies + 1 WHERE id = :book_id')
            ->execute(['book_id' => $reservation['book_id']]);
        $pdo->prepare('UPDATE reservations SET status = "fulfilled", fulfilled_at = NOW() WHERE id = :id')
            ->execute(['id' => $id]);

    } elseif ($action === 'cancel') {
        if (!in_array($reservation['status'], ['waiting', 'ready'], true)) {
            throw new RuntimeException('Only waiting or ready reservations can be cancelled.');
        }
        $pdo->prepare('UPDATE reservations SET status = "cancelled" WHERE id = :id')
            ->execute(['id' => $id]);

    } elseif ($action === 'schedule') {
        $lock = $pdo->prepare('SELECT status, pickup_at FROM reservations WHERE id = :id FOR UPDATE');
        $lock->execute(['id' => $id]);
        $current = $lock->fetch();
        if (!$current || $current['status'] !== 'waiting') {
            throw new RuntimeException('Pickup time is final once the reservation is ready.');
        }
        if ($current['pickup_at'] !== $pickupAt) {
            $pdo->prepare(
                'UPDATE reservations SET pickup_at = :pickup_at WHERE id = :id AND status = "waiting"'
            )->execute(['pickup_at' => $pickupAt, 'id' => $id]);
        }

    } else { // return
        if ($reservation['status'] !== 'fulfilled') {
            throw new RuntimeException('Only fulfilled reservations can be marked returned.');
        }
        $returned = $pdo->prepare(
            'UPDATE reservations SET status = "returned", returned_at = NOW()
             WHERE id = :id AND status = "fulfilled"'
        );
        $returned->execute(['id' => $id]);
        if ($returned->rowCount() !== 1) {
            throw new RuntimeException('This reservation has already been returned.');
        }
        $pdo->prepare('UPDATE books SET borrowed_copies = GREATEST(borrowed_copies - 1, 0) WHERE id = :book_id')
            ->execute(['book_id' => $reservation['book_id']]);
    }

    $pdo->commit();

    // Notify the patron their reserved copy is ready for pickup. Sent after
    // the commit so the SMS gateway can never block or roll back the
    // actual status change.
    if ($action === 'ready') {
        $pickupTime = $reservation['pickup_at']
            ? ' Your pickup is scheduled for ' . date('M j, Y g:i A', strtotime($reservation['pickup_at'])) . '.'
            : '';
        send_sms(
            $pdo, (int) $reservation['user_id'], $reservation['phone'],
            "BCIHS Library: Good news! Your reserved book \"{$reservation['book_title']}\" is now READY FOR PICKUP.{$pickupTime} Please claim it at the library."
        );
    }

    echo json_encode(['success' => true]);

} catch (RuntimeException $e) {
    $pdo->rollBack();
    fail(422, $e->getMessage());
}
