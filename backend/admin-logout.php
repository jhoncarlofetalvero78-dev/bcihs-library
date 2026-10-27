<?php
session_start();

// Clear all session variables
$_SESSION = [];

// Delete session cookie with multiple paths to ensure it's cleared
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    // Clear with the session's configured path
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    // Also clear with root path (common issue when session path differs)
    setcookie(session_name(), '', time() - 42000, '/', $params['domain'], $params['secure'], $params['httponly']);
    // Also clear with empty path (fallback)
    setcookie(session_name(), '', time() - 42000, '', $params['domain'], $params['secure'], $params['httponly']);
}

// Destroy the session on the server
session_destroy();

// Prevent caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// Redirect with cache-busting parameter to force fresh page load
header('Location: ../index.html?v=' . time());
exit;
