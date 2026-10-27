<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/sms.php';
header('Content-Type: application/json');

function fail(int $code, string $message) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

$isLibraryStaff = isset($_SESSION['admin_id']) || (
    isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'librarian'
);
if (!$isLibraryStaff) {
    fail(401, 'not_logged_in');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'method_not_allowed');
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$id     = isset($input['id']) ? (int) $input['id'] : 0;
$action = $input['action'] ?? '';

if ($id <= 0 || !in_array($action, ['approve', 'reject', 'return'], true)) {
    fail(422, 'Missing or invalid request.');
}

$stmt = $pdo->prepare(
    'SELECT br.id, br.status, br.book_id, b.total_copies, b.borrowed_copies,
            b.title AS book_title, u.id AS user_id, u.full_name, u.phone
     FROM borrow_requests br
     JOIN books b ON b.id = br.book_id
     LEFT JOIN users u ON u.id = br.user_id
     WHERE br.id = :id LIMIT 1'
);
$stmt->execute(['id' => $id]);
$request = $stmt->fetch();

if (!$request) {
    fail(404, 'That request no longer exists.');
}

$pdo->beginTransaction();

try {
    if ($action === 'approve') {
        if ($request['status'] !== 'pending') {
            throw new RuntimeException('Only pending requests can be approved.');
        }
        $available = (int) $request['total_copies'] - (int) $request['borrowed_copies'];
        if ($available <= 0) {
            throw new RuntimeException('No copies of this book are currently available.');
        }
        $pdo->prepare('UPDATE books SET borrowed_copies = borrowed_copies + 1 WHERE id = :book_id')
            ->execute(['book_id' => $request['book_id']]);
        $pdo->prepare(
            'UPDATE borrow_requests
             SET status = "approved",
                 approved_at = NOW(),
                 due_date = COALESCE(due_date, DATE_ADD(NOW(), INTERVAL 14 DAY))
             WHERE id = :id'
        )
            ->execute(['id' => $id]);

    } elseif ($action === 'reject') {
        if ($request['status'] !== 'pending') {
            throw new RuntimeException('Only pending requests can be rejected.');
        }
        $pdo->prepare('UPDATE borrow_requests SET status = "rejected", rejected_at = NOW() WHERE id = :id')
            ->execute(['id' => $id]);

    } else { // return
        if ($request['status'] !== 'approved') {
            throw new RuntimeException('Only approved (borrowed) requests can be marked returned.');
        }
        $pdo->prepare('UPDATE books SET borrowed_copies = GREATEST(borrowed_copies - 1, 0) WHERE id = :book_id')
            ->execute(['book_id' => $request['book_id']]);
        $pdo->prepare(
            'UPDATE borrow_requests
             SET status = "returned",
                 returned_at = NOW(),
                 due_date = COALESCE(due_date, DATE_ADD(approved_at, INTERVAL 14 DAY))
             WHERE id = :id'
        )
            ->execute(['id' => $id]);
    }

    $pdo->commit();

    // Notify the patron by SMS. Sent after the commit so a slow or failed
    // SMS gateway can never roll back the actual approve/reject decision.
    if ($action === 'approve' && $request['user_id']) {
        send_sms(
            $pdo, (int) $request['user_id'], $request['phone'],
            "BCIHS Library: Your request to borrow \"{$request['book_title']}\" has been APPROVED. Please pick up your book at the library."
        );
    } elseif ($action === 'reject' && $request['user_id']) {
        send_sms(
            $pdo, (int) $request['user_id'], $request['phone'],
            "BCIHS Library: Your request to borrow \"{$request['book_title']}\" was NOT APPROVED. Please visit the library for details."
        );
    }

    echo json_encode(['success' => true]);

} catch (RuntimeException $e) {
    $pdo->rollBack();
    fail(422, $e->getMessage());
}
