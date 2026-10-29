<?php
require_once __DIR__ . '/../includes/sms.php';

$secret = 'SYNTHETIC_DIAGNOSTIC_SECRET';
$phone = '09171234567';
$path = '/private/test/ledger';
$summary = smsSafeDiagnosticSummary(
    ['unisms_secret' => $secret, 'live_test' => ['enabled' => false, 'recipient' => $phone, 'directory' => $path]],
    ['provider' => 'unisms', 'mode' => 'simulate', 'live_enabled' => false],
    ['available' => true, 'allowed' => true],
    true,
    true,
    true
);
$output = json_encode($summary, JSON_THROW_ON_ERROR);
if (str_contains($output, $secret) || str_contains($output, $phone) || str_contains($output, $path)) {
    throw new RuntimeException('Safe diagnostics exposed private test data.');
}
if (($summary['unisms_secret_present'] ?? false) !== true
    || ($summary['provider'] ?? '') !== 'unisms'
    || ($summary['configured_mode'] ?? '') !== 'simulate'
    || ($summary['live_enabled'] ?? true) !== false
    || ($summary['live_test_authorization_armed'] ?? true) !== false
    || ($summary['previous_account_364_claim_consumed'] ?? false) !== true) {
    throw new RuntimeException('Safe diagnostic summary omitted expected Boolean state.');
}

$production = smsSafeDiagnosticSummary(
    ['unisms_secret'=>$secret,'live_test'=>null,'consumed_live_tests'=>['account:368'=>['directory'=>$path]]],
    ['provider'=>'unisms','mode'=>'live','live_enabled'=>true,'key'=>$secret,
        'unisms_delivery'=>'production','unisms_production_authorized'=>true,'live_test'=>null,
        'unisms_verified_networks'=>[$phone=>'dito']],
    ['available'=>true,'allowed'=>true], true, true, true, true
);
$output = json_encode($production, JSON_THROW_ON_ERROR);
if ($production['effective_delivery'] !== 'Live' || $production['unisms_delivery_policy'] !== 'production'
    || !$production['previous_account_364_claim_consumed'] || !$production['account_368_claim_consumed']
    || str_contains($output, $secret) || str_contains($output, $phone) || str_contains($output, $path)) {
    throw new RuntimeException('Production diagnostic state or redaction failed.');
}

echo "Completed safe SMS diagnostics checks. Output contains allowlisted status only.\n";
