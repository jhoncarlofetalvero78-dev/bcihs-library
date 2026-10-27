<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db_connect.php';

try {
    $stmt = $pdo->query(
        'SELECT title, author, category, genre, image_path, ddc, cutter_number, year_of_publication, call_number
         FROM books
         WHERE total_copies > borrowed_copies
         ORDER BY created_at DESC, id DESC
         LIMIT 4'
    );
    echo json_encode(['books' => $stmt->fetchAll()]);
} catch (PDOException $exception) {
    error_log('Could not load public books.');
    http_response_code(500);
    echo json_encode(['error' => 'Could not load available books. Please try again.']);
}
