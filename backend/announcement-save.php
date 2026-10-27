<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';
header('Content-Type: application/json');

function fail(int $code, string $message) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

function removeAnnouncementPhoto(?string $path): void {
    if (!$path || !preg_match('#^uploads/announcements/[a-f0-9]{32}\.(jpg|png|webp)$#', $path)) {
        return;
    }

    $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (is_file($file) && !unlink($file)) {
        error_log('Could not remove announcement photo: ' . $file);
    }
}

$isLibraryStaff = isset($_SESSION['admin_id']) || (
    isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'librarian'
);
if (!$isLibraryStaff) {
    fail(401, 'not_logged_in');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'method_not_allowed');
}

$input = $_POST;
if (!$input) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}

$id       = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;
$title    = trim($input['title'] ?? '');
$body     = trim($input['body'] ?? '');
$category = $input['category'] ?? 'General';

$allowedCategories = ['General', 'Event', 'Notice'];
if (!in_array($category, $allowedCategories, true)) {
    $category = 'General';
}

if ($title === '' || $body === '') {
    fail(422, 'Title and Description are required.');
}

$oldPhoto = null;
if ($id) {
    $lookup = $pdo->prepare('SELECT image_path FROM announcements WHERE id = :id');
    $lookup->execute(['id' => $id]);
    $oldPhoto = $lookup->fetchColumn();
    if ($oldPhoto === false) {
        fail(404, 'Announcement not found.');
    }
}

$newPhoto = null;
if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
    $upload = $_FILES['photo'];
    if ($upload['error'] !== UPLOAD_ERR_OK) {
        fail(422, 'The photo could not be uploaded. Please try again.');
    }
    if ($upload['size'] > 5 * 1024 * 1024) {
        fail(422, 'Photo must be 5 MB or smaller.');
    }
    if (!is_uploaded_file($upload['tmp_name'])) {
        fail(422, 'Invalid photo upload.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($extensions[$mime])) {
        fail(422, 'Photo must be a JPG, PNG, or WebP image.');
    }

    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'announcements';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        fail(500, 'Photo storage is unavailable. Please contact the administrator.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($upload['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
        fail(500, 'The photo could not be saved. Please try again.');
    }
    $newPhoto = 'uploads/announcements/' . $filename;
}

try {
    if ($id) {
        $stmt = $pdo->prepare(
            'UPDATE announcements
             SET title = :title, body = :body, category = :category,
                 image_path = :image_path
             WHERE id = :id'
        );
        $stmt->execute([
            'title' => $title,
            'body' => $body,
            'category' => $category,
            'image_path' => $newPhoto ?? $oldPhoto,
            'id' => $id,
        ]);
        $savedId = $id;
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO announcements (title, body, category, image_path, posted_by)
             VALUES (:title, :body, :category, :image_path, :posted_by)'
        );
        $stmt->execute([
            'title' => $title,
            'body' => $body,
            'category' => $category,
            'image_path' => $newPhoto,
            'posted_by' => $_SESSION['user_id'] ?? null,
        ]);
        $savedId = $pdo->lastInsertId();
    }
} catch (PDOException $exception) {
    if ($newPhoto) {
        removeAnnouncementPhoto($newPhoto);
    }
    error_log('Announcement save failed: ' . $exception->getMessage());
    fail(500, 'Could not save the announcement. Please try again.');
}

if ($newPhoto && $oldPhoto) {
    removeAnnouncementPhoto($oldPhoto);
}

echo json_encode(['success' => true, 'id' => $savedId]);
