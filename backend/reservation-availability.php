<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/borrowing.php';
require_once __DIR__ . '/../includes/reservation-schedule.php';

try {
    $role = requireBookAccess($pdo);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') bookError(405, 'method_not_allowed');
    $month = $_GET['month'] ?? date('Y-m');
    if (!is_string($month) || !preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/', $month)) throw new DomainException('Choose a valid calendar month.', 422);
    $start = new DateTimeImmutable($month . '-01');
    $ignoreId = isset($_GET['exclude_id']) ? borrowingId($_GET['exclude_id']) : 0;
    if ($ignoreId) {
        $query = $pdo->prepare('SELECT user_id, status FROM reservations WHERE id = :id');
        $query->execute(['id' => $ignoreId]);
        $own = $query->fetch();
        if (!$own || (!in_array($role, ['admin', 'librarian'], true) && (int) $own['user_id'] !== (int) ($_SESSION['user_id'] ?? 0))) {
            throw new DomainException('You can only reschedule your own reservation.', 403);
        }
        if ($own['status'] !== 'waiting') throw new DomainException('Only waiting reservations can be rescheduled.', 409);
    }
    $query = $pdo->prepare('SELECT pickup_at, COUNT(*) AS n FROM reservations
        WHERE status IN ("waiting", "ready") AND pickup_at >= :start AND pickup_at <= :end AND id <> :ignore
        GROUP BY pickup_at ORDER BY pickup_at');
    $query->execute(['start' => $start->format('Y-m-d'), 'end' => $start->format('Y-m-t 23:59:59'), 'ignore' => $ignoreId]);
    $days = $slots = [];
    foreach ($query->fetchAll() as $row) {
        $key = substr($row['pickup_at'], 0, 10);
        $days[$key] = ($days[$key] ?? 0) + (int) $row['n'];
        $slots[$row['pickup_at']] = (int) $row['n'];
    }
    echo json_encode(['settings' => reservationSettings($pdo), 'days' => (object) $days, 'slots' => (object) $slots,
        'now' => date('c'), 'timezone' => 'Asia/Manila']);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
