<?php
// Read-only Apache checks: the PHP CLI development server ignores .htaccess.
if (PHP_SAPI !== 'cli') exit;
$base = 'http://localhost:8888/bcihs-library';
$checks = 0;
function staticCheck(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: '.$label);
    $checks++;
}
function staticResource(string $path): array {
    global $base;
    $curl = curl_init($base.'/'.$path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false]);
    $body = curl_exec($curl);$status = curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    if ($body === false) throw new RuntimeException('Local Apache unavailable.');
    return [$status,$body];
}
[$status,$script] = staticResource('js/vendor/qrcodegen.js');
staticCheck($status === 200,'bundled QR dependency is served by Apache');
staticCheck(str_contains($script,'var qrcodegen;') && str_contains($script,'qrcodegen.QrCode = QrCode'),'local generator exports the expected global');
foreach (['vendor/autoload.php','vendor/composer/installed.json','composer.json','composer.lock',
    'includes/sms-local.php','includes/db-local.php'] as $path) {
    [$status] = staticResource($path);
    staticCheck($status === 403,'private/dependency route remains protected: '.$path);
}
[$status,$profile] = staticResource('profile.html');
$generator = strpos($profile,'js/vendor/qrcodegen.js');$renderer = strpos($profile,'js/pickup-qr.js');
staticCheck($status === 200 && $generator !== false && $renderer !== false && $generator < $renderer, 'profile loads the local generator before pickup rendering');
echo "Passed $checks read-only Apache resource/security checks. No database or provider request.\n";
