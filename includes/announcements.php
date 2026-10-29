<?php
require_once __DIR__ . '/account-management.php';
function announcementToken(): string {
    if (empty($_SESSION['announcement_token'])) {
        $_SESSION['announcement_token']=bin2hex(random_bytes(16));
        $_SESSION['announcement_forms'][$_SESSION['announcement_token']]=null;
        $_SESSION['announcement_forms']=array_slice($_SESSION['announcement_forms'],-20,null,true);
    }
    return $_SESSION['announcement_token'];
}
function removeAnnouncementPhoto(?string $path): void {
    if (!$path || !preg_match('#^uploads/announcements/[a-f0-9]{32}\.(jpg|png|webp)$#',$path)) return;
    $file=dirname(__DIR__).'/'.$path;
    if (is_file($file) && !unlink($file)) error_log('Could not remove announcement photo.');
}
