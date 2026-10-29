<?php
function hostingPrivatePath(string $name): string
{
    return dirname(__DIR__, 2) . '/bcihs-private/' . $name;
}
function hostingConfiguration(): array
{
    static $config;
    if (is_array($config)) return $config;
    $path = hostingPrivatePath('config.php');
    if (!is_file($path) || is_link($path)) throw new InvalidArgumentException('Hosting configuration is unavailable.');
    ob_start();
    try { $value = require $path; } finally { ob_end_clean(); }
    if (!is_array($value) || !is_array($value['database'] ?? null)) throw new InvalidArgumentException('Hosting configuration is unavailable.');
    foreach (['host','name','user','password'] as $key) {
        if (!is_string($value['database'][$key] ?? null) || $value['database'][$key] === '') throw new InvalidArgumentException('Complete the private hosting configuration.');
    }
    if (strtolower($value['database']['user']) === 'root' || $value['database']['name'] === 'bcihs_library') throw new InvalidArgumentException('A separate hosting database account is required.');
    $url = $value['public_base_url'] ?? '';
    $parts = is_string($url) ? parse_url($url) : false;
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || in_array(strtolower($parts['host']), ['localhost','127.0.0.1','::1'], true)
        || preg_match('/[\s<>"\x00-\x1f]/', $url)) throw new InvalidArgumentException('Configure the exact hosted HTTPS URL.');
    $config = $value;
    return $config;
}
function hostingRequireRotation(): void
{
    $file = hostingPrivatePath('activation.json');
    $state = is_file($file) && !is_link($file) ? json_decode((string)file_get_contents($file),true) : null;
    if (!is_array($state) || ($state['credentials_rotated'] ?? false) !== true) {
        if (PHP_SAPI !== 'cli') http_response_code(503);
        exit('Hosting setup is incomplete. Contact the deployment administrator.');
    }
}
