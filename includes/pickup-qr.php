<?php
require_once __DIR__ . '/borrowing.php';

function pickupToken($value): string
{
    if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) {
        throw new DomainException('Invalid pickup QR. Ask the student to reopen their approved request.', 422);
    }
    return $value;
}

function pickupBaseUrl(): string
{
    $local = [];
    if (is_file(__DIR__ . '/pickup-local.php')) $local = require __DIR__ . '/pickup-local.php';
    $value = getenv('BCIHS_PUBLIC_BASE_URL');
    if ($value === false) $value = is_array($local) ? ($local['BCIHS_PUBLIC_BASE_URL'] ?? '') : '';
    if (!is_string($value) || $value === '') {
        throw new DomainException('Pickup QR is not configured yet. Ask staff to set the library public base URL; manual checkout is still available.', 503);
    }
    $value = rtrim($value, '/');
    $parts = parse_url($value);
    if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || preg_match('/[\s<>"\x00-\x1f\\\\]/', $value) || strlen($value) > 500) {
        throw new DomainException('The library public base URL is invalid. Ask staff to review its configuration.', 503);
    }
    return $value;
}
