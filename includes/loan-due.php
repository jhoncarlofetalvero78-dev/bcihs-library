<?php
function loanDays($value): int
{
    if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,2}$/D', (string)$value) || (int)$value > 365) {
        throw new DomainException('Choose a whole borrowing period from 1 to 365 calendar days.', 422);
    }
    return (int)$value;
}

function loanPolicy(): array
{
    $path = __DIR__ . '/borrowing-policy.json';
    if (!file_exists($path)) return ['days'=>BORROWING_DAYS, 'version'=>0, 'audit'=>[]];
    if (is_link($path)) throw new RuntimeException('Borrowing policy unavailable.');
    $policy = json_decode((string)file_get_contents($path), true);
    if (!is_array($policy) || !is_int($policy['days'] ?? null) || !is_int($policy['version'] ?? null)
        || $policy['version'] < 1 || !is_array($policy['audit'] ?? null)) throw new RuntimeException('Borrowing policy unavailable.');
    loanDays($policy['days']);
    return $policy;
}

function saveLoanPolicy(array $input, int $adminId): array
{
    $days = loanDays($input['days'] ?? null);
    $reason = $input['reason'] ?? '';
    if (!is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 250 || preg_match('/[\x00-\x1f\x7f]/u', $reason)
        || !is_int($input['version'] ?? null) || $input['version'] < 0 || ($input['confirm_change'] ?? false) !== true) {
        throw new DomainException('Confirm the policy change and enter its approved reason (up to 250 characters).', 422);
    }
    $path = __DIR__ . '/borrowing-policy.json';
    $lockPath = __DIR__ . '/.borrowing-policy.lock';
    if (is_link($lockPath)) throw new RuntimeException('Borrowing policy unavailable.');
    $oldMask = umask(0077);
    try { $lock = fopen($lockPath, 'c+'); } finally { umask($oldMask); }
    $temporary = null;
    if (!$lock) throw new RuntimeException('Borrowing policy unavailable.');
    try {
        if (!chmod($lockPath, 0600) || !flock($lock, LOCK_EX)) throw new RuntimeException('Borrowing policy unavailable.');
        $policy = loanPolicy();
        if ($input['version'] !== $policy['version']) throw new DomainException('Another administrator changed this policy. Reload before saving.', 409);
        if ($days === $policy['days']) return $policy;
        $policy['audit'][] = ['admin_id'=>$adminId, 'previous_days'=>$policy['days'], 'new_days'=>$days,
            'reason'=>trim($reason), 'changed_at'=>(new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s')];
        $policy['days'] = $days;
        $policy['version']++;
        $json = json_encode($policy, JSON_THROW_ON_ERROR);
        $temporary = __DIR__ . '/.borrowing-policy-' . bin2hex(random_bytes(8)) . '.json';
        $oldMask = umask(0077);
        try { $handle = fopen($temporary, 'x+b'); } finally { umask($oldMask); }
        if (!$handle) throw new RuntimeException('Borrowing policy could not be saved.');
        try {
            if (!chmod($temporary, 0600) || fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('Borrowing policy could not be saved.');
            }
        } finally { fclose($handle); }
        if (!rename($temporary, $path)) throw new RuntimeException('Borrowing policy could not be saved.');
        return $policy;
    } finally {
        if ($temporary !== null && is_file($temporary)) unlink($temporary);
        flock($lock, LOCK_UN); fclose($lock);
    }
}

function calculateLoanDue(array $input, array $policy, DateTimeImmutable $now): string
{
    $mode = $input['due_mode'] ?? 'default';
    if ($mode === 'default') $due = $now->modify('+' . loanDays($policy['days']) . ' days');
    elseif ($mode === 'days') $due = $now->modify('+' . loanDays($input['days'] ?? null) . ' days');
    elseif ($mode === 'date') {
        $date = $input['due_date'] ?? null;
        $due = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Manila')) : false;
        if (!$due || $due->format('Y-m-d') !== $date) throw new DomainException('Choose a real due date.', 422);
        $due = $due->setTime(23, 59, 59);
        if ($due > $now->modify('+365 days')->setTime(23, 59, 59)) throw new DomainException('Choose a due date within the next 365 days.', 422);
    } else throw new DomainException('Choose the default period, custom days, or a custom due date.', 422);
    if ($due <= $now || $due->format('Y-m-d') > '2038-01-18') {
        throw new DomainException('The due date must be in the future and within the database date range.', 422);
    }
    return $due->format('Y-m-d H:i:s');
}

function checkoutLoanDue(array $input, string $source, int $id, string $now): string
{
    $policy = loanPolicy();
    $token = $input['due_preview_token'] ?? null;
    if ($token !== null) {
        $preview = is_string($token) ? ($_SESSION['loan_due_previews'][$token] ?? null) : null;
        if (!is_array($preview) || $preview['source'] !== $source || $preview['id'] !== $id
            || $preview['expires'] < time() || $preview['due_at'] <= $now) {
            throw new DomainException('The due-date confirmation expired or does not match this checkout. Review it again.', 409);
        }
        if ($preview['mode'] === 'default' && $preview['policy_version'] !== $policy['version']) {
            throw new DomainException('The default borrowing period changed. Review the due date again.', 409);
        }
        return $preview['due_at'];
    }
    // Keep old staff/API callers working with the current default; custom UI choices use previews.
    if ($source === 'manual' && !empty($input['due_date'])) {
        return calculateLoanDue(['due_mode'=>'date','due_date'=>$input['due_date']], $policy, new DateTimeImmutable($now, new DateTimeZone('Asia/Manila')));
    }
    if (isset($input['days']) || isset($input['due_mode']) || !empty($input['due_date'])) {
        throw new DomainException('Preview and confirm the selected due date before checkout.', 422);
    }
    return calculateLoanDue([], $policy, new DateTimeImmutable($now, new DateTimeZone('Asia/Manila')));
}
