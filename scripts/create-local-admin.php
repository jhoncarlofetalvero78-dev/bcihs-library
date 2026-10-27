<?php
require_once __DIR__ . '/local-cli.php';

function readHiddenPassword(string $prompt): string
{
    if (!stream_isatty(STDIN) || !is_callable('shell_exec')
        || (PHP_OS_FAMILY !== 'Windows' && !is_callable('exec'))) {
        throw new RuntimeException('Use an interactive terminal with shell_exec (and exec on macOS/Linux) enabled for hidden password entry.');
    }

    fwrite(STDOUT, $prompt);
    if (PHP_OS_FAMILY === 'Windows') {
        $command = 'powershell -NoProfile -Command "$secret = Read-Host -AsSecureString; '
            . '$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secret); '
            . 'try { [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) } '
            . 'finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }"';
        $password = shell_exec($command);
        if ($password === null || $password === false) {
            throw new RuntimeException('Hidden password entry failed. Check that PowerShell is available.');
        }
        return rtrim($password, "\r\n");
    }

    $terminalMode = trim((string) shell_exec('stty -g 2>/dev/null'));
    if ($terminalMode === '') {
        throw new RuntimeException('Hidden password entry is unavailable in this terminal.');
    }
    $output = [];
    exec('stty -echo 2>/dev/null', $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('Could not hide terminal input.');
    }
    try {
        $password = fgets(STDIN);
        if ($password === false) {
            throw new RuntimeException('Password input was cancelled.');
        }
        return rtrim($password, "\r\n");
    } finally {
        shell_exec('stty ' . escapeshellarg($terminalMode));
        fwrite(STDOUT, PHP_EOL);
    }
}

try {
    fwrite(STDOUT, "Create a NEW administrator in the local bcihs_library database. Existing accounts will not be changed.\nUsername: ");
    $username = trim((string) fgets(STDIN));
    if (!preg_match('/^[A-Za-z0-9_.-]{3,100}$/', $username)) {
        throw new RuntimeException('Use 3–100 letters, numbers, dots, underscores, or hyphens for the username.');
    }

    $check = $pdo->prepare('SELECT id FROM admins WHERE username = :username LIMIT 1');
    $check->execute(['username' => $username]);
    if ($check->fetch()) {
        throw new RuntimeException('That administrator already exists. Choose a new username; no password was changed.');
    }

    $password = readHiddenPassword('New password (12–72 bytes; hidden): ');
    $confirmation = readHiddenPassword('Confirm password (hidden): ');
    if (strlen($password) < 12 || strlen($password) > 72) {
        throw new RuntimeException('Use a unique password between 12 and 72 bytes.');
    }
    if ($password !== $confirmation) {
        throw new RuntimeException('The passwords do not match. No account was created.');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    unset($password, $confirmation);

    $insert = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (:username, :password_hash)');
    $insert->execute(['username' => $username, 'password_hash' => $passwordHash]);
    fwrite(STDOUT, "Administrator created. Sign in through admin-login.html using the credentials you just entered.\n");
} catch (PDOException $exception) {
    fwrite(STDERR, "Administrator creation failed. Check the admins table and choose an unused username. No existing account was changed.\n");
    exit(1);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
