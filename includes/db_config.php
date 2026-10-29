<?php
// Environment variables take priority over the private local configuration.
$localDatabaseConfig = [];
$localDatabaseFile = __DIR__ . '/db-local.php';
if (is_file($localDatabaseFile)) {
    $localDatabaseConfig = require $localDatabaseFile;
    if (!is_array($localDatabaseConfig)) {
        throw new InvalidArgumentException('The local database configuration must return an array.');
    }
}

function databaseSetting(string $name, string $fallback): string
{
    global $localDatabaseConfig;

    $value = getenv($name);
    if ($value !== false) {
        return $value;
    }

    return (string) ($localDatabaseConfig[$name] ?? $fallback);
}

define('DB_HOST', databaseSetting('BCIHS_DB_HOST', 'localhost'));
define('DB_PORT', databaseSetting('BCIHS_DB_PORT', ''));
define('DB_NAME', databaseSetting('BCIHS_DB_NAME', 'bcihs_library'));
define('DB_USER', databaseSetting('BCIHS_DB_USER', 'root'));
define('DB_PASS', databaseSetting('BCIHS_DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

if (DB_PORT !== '' && (!ctype_digit(DB_PORT) || (int) DB_PORT < 1 || (int) DB_PORT > 65535)) {
    throw new InvalidArgumentException('The database port must be between 1 and 65535.');
}

// These settings are used inside a PDO DSN, where semicolons separate options.
if (DB_HOST === '' || DB_NAME === '' || strpbrk(DB_HOST . DB_NAME, ";\r\n\0") !== false) {
    throw new InvalidArgumentException('Invalid database host or database name.');
}
