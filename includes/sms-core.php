<?php
// Pure validation/templates/provider interpretation; no database or network calls.
function smsPhone($value): ?string
{
    if (!is_string($value)) return null;
    $digits = preg_replace('/[\s().-]/', '', trim($value));
    if (preg_match('/^09[0-9]{9}$/D', $digits)) return $digits;
    if (preg_match('/^\+?639[0-9]{9}$/D', $digits)) return '0' . substr(ltrim($digits, '+'), 2);
    return null;
}

function smsResult(string $state, string $reason, ?string $providerId = null): array
{
    return ['state' => $state, 'reason' => $reason, 'provider_id' => $providerId];
}

function smsProviderResult(int $httpCode, $body, string $phone): array
{
    if ($httpCode < 200 || $httpCode >= 300) {
        return smsResult('failed', match ($httpCode) {
            401,403 => 'provider_authentication_rejected', 429 => 'provider_rate_limited',
            default => $httpCode >= 500 ? 'provider_unavailable' : 'provider_http_rejected'
        });
    }
    $rows = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($rows) || !array_is_list($rows) || count($rows) !== 1 || !is_array($rows[0])) return smsResult('unknown', 'unsupported_provider_response');
    $row = $rows[0]; $id = $row['message_id'] ?? null;
    if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]*$/D', (string)$id)
        || smsPhone($row['recipient'] ?? null) !== $phone || !is_string($row['status'] ?? null)) {
        return smsResult('unknown', 'unsupported_provider_response');
    }
    $state = match (strtolower($row['status'])) {
        'queued' => 'queued', 'pending' => 'pending', 'sent' => 'accepted',
        'failed','refunded' => 'failed', default => 'unknown'
    };
    return smsResult($state, match ($state) {
        'queued' => 'provider_queued', 'pending' => 'provider_in_transit',
        'accepted' => 'provider_network_accepted_not_handset_delivery',
        'failed' => 'provider_message_rejected', default => 'unsupported_provider_status'
    }, (string)$id);
}

function smsDispatch(array $config, $phone, string $message, callable $transport): array
{
    $phone = smsPhone($phone);
    if ($phone === null) return smsResult('skipped', 'missing_or_invalid_phone');
    if (trim($message) === '' || strlen($message) > 2000) return smsResult('skipped', 'invalid_message');
    if (($config['mode'] ?? 'simulate') === 'simulate') return smsResult('simulated', 'simulation_no_network_no_credits');
    if (($config['mode'] ?? '') !== 'live' || ($config['live_enabled'] ?? false) !== true) return smsResult('skipped', 'live_sending_not_enabled');
    if (empty($config['key'])) return smsResult('skipped', 'missing_provider_credentials');
    try {
        $payload = ['apikey' => $config['key'], 'number' => $phone, 'message' => $message];
        if (!empty($config['sender'])) $payload['sendername'] = $config['sender'];
        $response = $transport($payload);
        if (!empty($response['error'])) return smsResult('unknown', 'transport_interrupted_check_provider_before_retry');
        return smsProviderResult((int)($response['http'] ?? 0), $response['body'] ?? null, $phone);
    } catch (Throwable $exception) { return smsResult('unknown', 'transport_interrupted_check_provider_before_retry'); }
}

function smsEventDate($value): string
{
    if (!is_string($value)) throw new DomainException('Missing notification date.');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('Asia/Manila'));
    if (!$date || $date->format('Y-m-d H:i:s') !== $value) throw new DomainException('Invalid notification date.');
    return $date->format('M j, Y g:i A');
}

function smsEventMessage(string $event, array $details = []): string
{
    $title = preg_replace('/\s+/u', ' ', trim((string)($details['title'] ?? 'your book')));
    $title = mb_substr($title, 0, 45);
    return match ($event) {
        'account_approved' => 'BCIHS Library: Your account is approved. You may sign in to the library portal.',
        'borrow_ready' => 'BCIHS Library: "' . $title . '" is approved and held for pickup. Show your request QR to staff. Not yet checked out.',
        'borrow_rejected' => 'BCIHS Library: Your request for "' . $title . '" was not approved. Please contact library staff.',
        'checkout' => 'BCIHS Library: "' . $title . '" checked out. Due ' . smsEventDate($details['due_date'] ?? null) . ' (PHT). Please return on time.',
        'reservation_ready' => 'BCIHS Library: "' . $title . '" is ready for pickup' . (!empty($details['pickup_at']) ? ' on ' . smsEventDate($details['pickup_at']) . ' (PHT)' : '') . '. A copy is held; staff must confirm handover.',
        default => throw new DomainException('Unsupported notification event.')
    };
}

function smsLogMetadata(array $row): array
{
    $metadata = json_decode($row['response'] ?? '', true);
    $states = ['simulated','queued','pending','accepted','failed','unknown','skipped'];
    if (is_array($metadata) && ($metadata['sms_version'] ?? null) === 1 && in_array($metadata['state'] ?? '', $states, true)) {
        return ['event' => $metadata['event'] ?? 'legacy', 'state' => $metadata['state'],
            'reason' => $metadata['reason'] ?? 'status_unavailable', 'provider_id' => $metadata['provider_id'] ?? null];
    }
    return ['event' => 'legacy', 'state' => match ($row['status'] ?? '') {
        'sent' => 'legacy_sent', 'failed' => 'failed', 'skipped' => 'skipped', default => 'unknown'
    }, 'reason' => 'historical_unverified_status', 'provider_id' => null];
}

function smsPublicLog(array $row): array
{
    $meta = smsLogMetadata($row);
    $digits = preg_replace('/\D/', '', $row['phone']);
    return ['id' => $row['id'], 'full_name' => $row['full_name'],
        'phone' => strlen($digits) >= 4 ? '*******' . substr($digits, -4) : 'Missing / invalid',
        'message' => $meta['event'] === 'password_reset' || stripos($row['message'], 'reset code') !== false
            ? 'Password reset code [redacted].' : $row['message'],
        'created_at' => $row['created_at'], 'event' => $meta['event'], 'status' => $meta['state'], 'reason' => $meta['reason']];
}
