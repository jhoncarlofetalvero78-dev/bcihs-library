<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';
header('Content-Type: application/json');

// Deliberately no login check — announcements are shown to guests on the
// public homepage, not just to signed-in patrons or admins.
try {
    $stmt = $pdo->query(
        'SELECT a.id, a.title, a.body, a.category, a.image_path, a.created_at
         FROM announcements a
         ORDER BY a.created_at DESC'
    );
    $announcements = $stmt->fetchAll();
} catch (PDOException $exception) {
    error_log('Could not load announcements: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not load announcements. Check that the announcement photo migration has been applied.']);
    exit;
}

echo json_encode(['announcements' => $announcements]);
