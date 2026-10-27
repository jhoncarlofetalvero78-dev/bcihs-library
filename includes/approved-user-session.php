<?php
function requireApprovedUserSession(PDO $pdo): bool
{
    if (isset($_SESSION['admin_id'])) {
        return true;
    }

    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT account_status FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if ($user && $user['account_status'] === 'accepted') {
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
