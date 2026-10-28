<?php
// Shared validation/templates/provider interpretation; live-test storage is isolated.
require_once __DIR__ . '/sms-live-test.php';
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
        return smsResult($httpCode === 0 || $httpCode >= 500 ? 'unknown' : 'failed', match ($httpCode) {
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

function smsDispatch(array $config, $phone, string $message, callable $transport, array $context = []): array
{
    $phone = smsPhone($phone);
    if ($phone === null) return smsResult('skipped', 'missing_or_invalid_phone');
    if (trim($message) === '' || strlen($message) > 2000) return smsResult('skipped', 'invalid_message');
    $provider = $config['provider'] ?? 'semaphore';
    if ($provider === 'simulation') return smsResult('simulated', 'simulation_no_network_no_credits');
    if (!in_array($provider, ['semaphore', 'unisms'], true)) return smsResult('skipped', 'unsupported_provider');
    if ($provider === 'unisms') {
        $restriction = smsUniRestriction($config, $phone, $message);
        if ($restriction !== null) return smsResult('skipped', $restriction);
    }
    if (($config['mode'] ?? 'simulate') === 'simulate') return smsResult('simulated', 'simulation_no_network_no_credits');
    if (($config['mode'] ?? '') !== 'live' || ($config['live_enabled'] ?? false) !== true) return smsResult('skipped', 'live_sending_not_enabled');
    if ($provider !== 'unisms' && ($config['live_test'] ?? null) !== null) return smsResult('skipped', 'live_test_wrong_provider');
    if (empty($config['key'])) return smsResult('skipped', 'missing_provider_credentials');
    if ($provider === 'unisms') return smsUniDispatch($config, $phone, $message, $transport, $context);
    try {
        $payload = ['apikey' => $config['key'], 'number' => $phone, 'message' => $message];
        if (!empty($config['sender'])) $payload['sendername'] = $config['sender'];
        $response = $transport($payload);
        if (!empty($response['error'])) return smsResult('unknown', 'transport_interrupted_check_provider_before_retry');
        $http = (int)($response['http'] ?? 0);
        return smsProviderResult($http, $response['body'] ?? null, $phone) + ['http_status' => $http, 'request_attempted' => true];
    } catch (Throwable $exception) { return smsResult('unknown', 'transport_interrupted_check_provider_before_retry'); }
}

function smsUniRestriction(array $config, string $phone, string $message): ?string
{
    // A prefix cannot establish the current network of a ported number.
    $network = ($config['unisms_verified_networks'] ?? [])[$phone] ?? '';
    if (!in_array($network, ['globe', 'dito'], true)) return 'unisms_network_not_verified';
    if (mb_strlen($message) > 160) return 'unisms_message_too_long';
    if (preg_match('~(?:https?:|www\.|[\p{L}\p{N}-]+\.[\p{L}]{2,}|[\p{L}\p{N}-]+\s*:\s*//)~iu', $message)) return 'unisms_links_not_allowed';
    return null;
}

function smsUniDispatch(array $config, string $phone, string $message, callable $transport, array $context = []): array
{
    try {
        $blocked = smsReserveLiveTest($config, $phone, $message, $context);
        if ($blocked !== null) return smsResult('skipped', $blocked);
        if (!smsRestoreLiveTest($config)) return smsResult('skipped', 'live_test_restore_failed');
        $response = $transport(['provider' => 'unisms', 'secret' => $config['key'],
            'body' => ['recipient' => '+63' . substr($phone, 1), 'content' => $message, 'sender_id' => 'Unisoft']]);
        if (!empty($response['error'])) return smsResult('unknown', 'transport_interrupted_check_provider_before_retry');
        $http = (int)($response['http'] ?? 0);
        return smsUniResult($http, $response['body'] ?? null, $phone) + ['http_status' => $http, 'request_attempted' => true];
    } catch (Throwable $exception) {return smsResult('unknown', 'transport_interrupted_check_provider_before_retry');}
}

function smsUniResult(int $http, $body, string $phone): array
{
    if ($http < 200 || $http >= 300) {
        // Undocumented errors remain generic; never persist an arbitrary response body.
        $validationReason = 'provider_validation_rejected';
        if ($http === 422 && is_string($body)) {
            $error = json_decode($body, true);
            $text = is_array($error) ? json_encode($error['errors'] ?? $error['message'] ?? '') : '';
            $validationReason = match (true) {
                (bool)preg_match('/insufficient.{0,30}credits?|not enough.{0,30}credits?/i', $text) => 'provider_credit_rejected',
                (bool)preg_match('/(?:smart|tnt|sun)/i', $text) => 'provider_network_rejected',
                default => 'provider_validation_rejected'
            };
        }
        return smsResult($http === 0 || $http >= 500 ? 'unknown' : 'failed', match ($http) {
            401, 403 => 'provider_authentication_rejected', 422 => $validationReason,
            429 => 'provider_rate_limited', 402 => 'provider_credit_rejected',
            default => $http >= 500 ? 'provider_unavailable' : 'provider_http_rejected'
        });
    }
    $data = is_string($body) ? json_decode($body, true) : null;
    $row = is_array($data) ? ($data['message'] ?? null) : null;
    if (!is_array($row) || !is_string($row['reference_id'] ?? null)
        || !preg_match('/^msg_[a-zA-Z0-9-]{1,100}$/D', $row['reference_id'])
        || smsPhone($row['recipient'] ?? null) !== $phone
        || !in_array($row['status'] ?? null, ['pending', 'sent', 'retrying', 'failed'], true)) {
        return smsResult('unknown', 'unsupported_provider_response');
    }
    return smsResult($row['status'], match ($row['status']) {
        'pending' => 'provider_queued', 'retrying' => 'provider_retrying',
        'sent' => 'provider_sent_not_handset_delivery', 'failed' => 'provider_message_rejected'
    }, $row['reference_id']);
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
    $title = mb_substr($title !== '' ? $title : 'your book', 0, 45);
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
    $states = ['simulated','queued','pending','accepted','sent','retrying','failed','unknown','skipped'];
    if (is_array($metadata) && ($metadata['sms_version'] ?? null) === 1 && in_array($metadata['state'] ?? '', $states, true)) {
        return ['event' => $metadata['event'] ?? 'legacy', 'state' => $metadata['state'],
            'reason' => $metadata['reason'] ?? 'status_unavailable', 'provider_id' => $metadata['provider_id'] ?? null,
            'provider' => in_array($metadata['provider'] ?? '', ['simulation','semaphore','unisms'], true) ? $metadata['provider'] : 'semaphore',
            'request_attempted' => is_bool($metadata['request_attempted'] ?? null) ? $metadata['request_attempted'] : null,
            'http_status' => is_int($metadata['http_status'] ?? null) && $metadata['http_status'] >= 100 && $metadata['http_status'] <= 599 ? $metadata['http_status'] : null];
    }
    return ['event' => 'legacy', 'state' => match ($row['status'] ?? '') {
        'sent' => 'legacy_sent', 'failed' => 'failed', 'skipped' => 'skipped', default => 'unknown'
    }, 'reason' => 'historical_unverified_status', 'provider_id' => null, 'provider' => 'legacy', 'request_attempted' => null, 'http_status' => null];
}

function smsPublicLog(array $row): array
{
    $meta = smsLogMetadata($row);
    $digits = preg_replace('/\D/', '', $row['phone']);
    $reference = $meta['provider_id'];
    $validReference = is_string($reference) && (
        ($meta['provider'] === 'unisms' && preg_match('/^msg_[a-zA-Z0-9-]{1,100}$/D', $reference))
        || ($meta['provider'] === 'semaphore' && preg_match('/^[1-9][0-9]{0,30}$/D', $reference)));
    return ['id' => $row['id'], 'full_name' => $row['full_name'],
        'phone' => strlen($digits) >= 4 ? '*******' . substr($digits, -4) : 'Missing / invalid',
        'message' => $meta['event'] === 'password_reset' || stripos($row['message'], 'reset code') !== false
            ? 'Password reset code [redacted].' : $row['message'],
        'created_at' => $row['created_at'], 'event' => $meta['event'], 'status' => $meta['state'], 'reason' => $meta['reason'],
        'provider' => $meta['provider'], 'request_attempted' => $meta['request_attempted'], 'http_status' => $meta['http_status'],
        'reference' => $validReference ? $reference : null];
}
