<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';
header('Content-Type: application/json');

function removeBookPhoto(?string $path): void {
    if (!$path || !preg_match('#^uploads/books/[a-f0-9]{32}\.(jpg|png|webp)$#', $path)) {
        return;
    }

    $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (is_file($file) && !unlink($file)) {
        error_log('Could not remove book photo: ' . $file);
    }
}

$isLibraryStaff = isset($_SESSION['admin_id']) || (
    isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'librarian'
);
if (!$isLibraryStaff) {
    http_response_code(401);
    echo json_encode(['error' => 'not_logged_in']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$id = isset($input['id']) ? (int) $input['id'] : 0;

if ($id <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'Missing or invalid book id.']);
    exit;
}

$lookup = $pdo->prepare('SELECT image_path FROM books WHERE id = :id');
$lookup->execute(['id' => $id]);
$oldPhoto = $lookup->fetchColumn();

$stmt = $pdo->prepare('DELETE FROM books WHERE id = :id');
$stmt->execute(['id' => $id]);

if ($oldPhoto) {
    removeBookPhoto($oldPhoto);
}

echo json_encode(['success' => true]);
