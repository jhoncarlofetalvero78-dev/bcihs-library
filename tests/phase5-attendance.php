<?php
// Attendance now uses Time In only; run its current cases in the owned disposable suite.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$argv = [__FILE__, '--run', '--sms-acceptance'];
require __DIR__ . '/../scripts/phase8-isolated-qa.php';
