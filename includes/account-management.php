<?php
require_once __DIR__ . '/borrowing.php';

function accountText($value, int $maximum, bool $required = true): string
{
    if (!is_string($value)) throw new DomainException('Enter valid text for each field.',422);
    $value=trim($value);
    if (($required && $value==='') || mb_strlen($value)>$maximum || preg_match('/[\x00-\x1f\x7f]/u',$value)) {
        throw new DomainException('Check required fields and their maximum lengths.',422);
    }
    return $value;
}

function accountPhone($value): ?string
{
    if (!is_string($value)) return null;
    $phone=preg_replace('/[\s().-]/','',trim($value));
    if (preg_match('/^09\d{9}$/',$phone)) return $phone;
    if (preg_match('/^\+639\d{9}$/',$phone)) return '0'.substr($phone,3);
    return null;
}

function accountHistorySql(): string
{
    // Fixed alias u; preserve identities and audit references in every linked table.
    return 'EXISTS(SELECT 1 FROM borrow_requests h WHERE h.user_id=u.id)
        OR EXISTS(SELECT 1 FROM reservations h WHERE h.user_id=u.id)
        OR EXISTS(SELECT 1 FROM attendance h WHERE h.user_id=u.id)
        OR EXISTS(SELECT 1 FROM sms_logs h WHERE h.user_id=u.id)
        OR EXISTS(SELECT 1 FROM announcements h WHERE h.posted_by=u.id)';
}
