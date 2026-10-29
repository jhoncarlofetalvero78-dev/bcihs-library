<?php
// One server: private, locked files provide persistent abuse budgets without a schema change.
function securityDirectory(): string
{
    $directory = sys_get_temp_dir() . '/bcihs-security-' . substr(hash('sha256', dirname(__DIR__)), 0, 16);
    if ((!is_dir($directory) && !@mkdir($directory, 0700, true)) || is_link($directory)) {
        throw new RuntimeException('Security storage is unavailable.');
    }
    if (PHP_OS_FAMILY !== 'Windows' && (!@chmod($directory, 0700) || (fileperms($directory) & 0077) !== 0)) {
        throw new RuntimeException('Security storage must be private.');
    }
    // Windows uses ACLs instead of POSIX permission bits; verify the service account ACL at turnover.
    if (!is_writable($directory)) throw new RuntimeException('Security storage is unavailable.');
    return $directory;
}

function securityBudget(string $scope, string $identity, int $limit, int $seconds, ?string $directory = null): bool
{
    $directory ??= securityDirectory();
    $path = $directory . '/' . hash('sha256', $scope . ':' . $identity) . '.json';
    if (is_link($path)) throw new RuntimeException('Security storage is unavailable.');
    $file = @fopen($path, 'c+');
    if (!$file) throw new RuntimeException('Security storage is unavailable.');
    @chmod($path, 0600);
    try {
        if (!flock($file, LOCK_EX)) throw new RuntimeException('Security storage is unavailable.');
        $raw = stream_get_contents($file);
        $state = $raw === '' ? ['start' => time(), 'count' => 0] : json_decode($raw, true);
        if (!is_array($state) || !isset($state['start'], $state['count']) || !is_int($state['start']) || !is_int($state['count'])) {
            throw new RuntimeException('Security storage is invalid.');
        }
        if (time() >= $state['start'] + $seconds) $state = ['start' => time(), 'count' => 0];
        if ($state['count'] >= $limit) return false;
        $state['count']++;
        $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        rewind($file);
        if (!ftruncate($file, 0) || fwrite($file, $encoded) !== strlen($encoded) || !fflush($file)) {
            throw new RuntimeException('Security storage is unavailable.');
        }
        return true;
    } finally {
        flock($file, LOCK_UN);
        fclose($file);
    }
}

function securityPhone($value): ?string
{
    if (!is_string($value)) return null;
    $digits = preg_replace('/[\s().+-]/', '', trim($value));
    if (preg_match('/^639\d{9}$/D', $digits)) $digits = '0' . substr($digits, 2);
    if (preg_match('/^9\d{9}$/D', $digits)) $digits = '0' . $digits;
    return preg_match('/^09\d{9}$/D', $digits) ? $digits : null;
}

function securitySessionMatches(string $passwordHash): bool
{
    $fingerprint = hash('sha256', $passwordHash);
    if (isset($_SESSION['password_fingerprint']) && !hash_equals($_SESSION['password_fingerprint'], $fingerprint)) return false;
    $_SESSION['password_fingerprint'] = $fingerprint;
    return true;
}
