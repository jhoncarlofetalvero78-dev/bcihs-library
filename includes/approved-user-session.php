<?php
require_once __DIR__ . '/security.php';
function requireApprovedUserSession(PDO $pdo): bool
{
    if (isset($_SESSION['admin_id'])) {
        $stmt = $pdo->prepare('SELECT password_hash FROM admins WHERE id=:id');
        $stmt->execute(['id'=>$_SESSION['admin_id']]);
        $hash = $stmt->fetchColumn();
        if ($hash && securitySessionMatches($hash)) return true;
        unset($_SESSION['admin_id'], $_SESSION['admin_username']);
        return false;
    }

    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT account_status, password_hash FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if ($user && $user['account_status'] === 'accepted' && securitySessionMatches($user['password_hash'])) {
        return true;
    }

    unset(
        $_SESSION['user_id'],
        $_SESSION['full_name'],
        $_SESSION['student_id'],
        $_SESSION['id_number'],
        $_SESSION['email'],
        $_SESSION['role']
    );
    session_regenerate_id(true);
    return false;
}
