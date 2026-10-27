<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/borrowing.php';
require_once __DIR__ . '/../includes/reservation-schedule.php';

try {
    requireBookAccess($pdo, true);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['settings' => reservationSettings($pdo), 'csrfToken' => bookCsrfToken()]);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') bookError(405, 'method_not_allowed');
    requireBookCsrf();
    $input = bookRequestInput();
    $settings = [];
    foreach (['daily_limit', 'slot_limit'] as $field) {
        if (!array_key_exists($field, $input)) throw new DomainException('Send both pickup limits. Leave blank for unlimited.', 422);
        $value = $input[$field];
        if ($value === '' || $value === null) $settings[$field] = null;
        elseif ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9]\d{0,4}$/', (string) $value) || (int) $value > 65535) {
            throw new DomainException('Pickup limits must be whole numbers from 1 to 65535, or blank for unlimited.', 422);
        } else $settings[$field] = (int) $value;
    }
    $pdo->beginTransaction();
    reservationSettings($pdo, true);
    foreach (['daily_limit' => 'DATE(pickup_at)', 'slot_limit' => 'pickup_at'] as $field => $group) {
        if ($settings[$field] === null) continue;
        $peak = $pdo->query('SELECT COALESCE(MAX(n), 0) FROM (SELECT COUNT(*) AS n FROM reservations
            WHERE status IN ("waiting", "ready") AND pickup_at >= CURDATE() GROUP BY ' . $group . ') counts')->fetchColumn();
        if ($settings[$field] < (int) $peak) throw new DomainException('That limit is below existing scheduled reservations. Reschedule or cancel them first.', 409);
    }
    $pdo->prepare('UPDATE reservation_settings SET daily_limit = :daily, slot_limit = :slot WHERE id = 1')
        ->execute(['daily' => $settings['daily_limit'], 'slot' => $settings['slot_limit']]);
    $pdo->commit();
    echo json_encode(['success' => true, 'settings' => $settings]);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
