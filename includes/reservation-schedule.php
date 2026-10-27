<?php
function validatePickupAt(PDO $pdo, $value): ?string
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) {
        return null;
    }

    $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('Asia/Manila'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$dateTime || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $dateTime->format('Y-m-d\TH:i') !== $value) {
        return null;
    }

    if ((int) $dateTime->format('N') > 5 || $dateTime->format('H:i') < '07:00'
        || $dateTime->format('H:i') > '16:00'
        || !in_array($dateTime->format('i'), ['00', '30'], true)) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT STR_TO_DATE(:pickup_at, "%Y-%m-%d %H:%i:%s") > NOW()'
    );
    $stmt->execute(['pickup_at' => $dateTime->format('Y-m-d H:i:s')]);
    if (!(bool) $stmt->fetchColumn()) {
        return null;
    }

    return $dateTime->format('Y-m-d H:i:s');
}

function reservationSettings(PDO $pdo, bool $lock = false): array
{
    $row = $pdo->query('SELECT daily_limit, slot_limit FROM reservation_settings WHERE id = 1' . ($lock ? ' FOR UPDATE' : ''))->fetch();
    if (!$row) throw new DomainException('Pickup settings are unavailable. Contact library staff.', 503);
    return ['daily_limit' => $row['daily_limit'] === null ? null : (int) $row['daily_limit'],
        'slot_limit' => $row['slot_limit'] === null ? null : (int) $row['slot_limit']];
}

// Creation and rescheduling lock the single policy row before the book row.
// This serializes capacity checks even when patrons choose different titles.
function requirePickupCapacity(PDO $pdo, array $settings, string $pickup, int $ignoreId = 0): void
{
    $query = $pdo->prepare('SELECT COUNT(*) AS day_count, COALESCE(SUM(pickup_at = :pickup), 0) AS slot_count
        FROM reservations WHERE status IN ("waiting", "ready") AND id <> :ignore
        AND pickup_at >= :start AND pickup_at <= :end');
    $start = new DateTimeImmutable(substr($pickup, 0, 10));
    $query->execute(['pickup' => $pickup, 'ignore' => $ignoreId, 'start' => $start->format('Y-m-d 00:00:00'),
        'end' => $start->format('Y-m-d 23:59:59')]);
    $counts = $query->fetch();
    if ($settings['daily_limit'] !== null && (int) $counts['day_count'] >= $settings['daily_limit']) {
        throw new DomainException('That pickup date is full. Choose another date.', 409);
    }
    if ($settings['slot_limit'] !== null && (int) $counts['slot_count'] >= $settings['slot_limit']) {
        throw new DomainException('That pickup time is full. Choose another time.', 409);
    }
}
