<?php
function validatePickupAt(PDO $pdo, $value): ?string
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) {
        return null;
    }

    $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
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
