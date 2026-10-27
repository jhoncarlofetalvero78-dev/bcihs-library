<?php
require_once __DIR__ . '/../includes/db_connect.php';
header('Content-Type: application/json');

function ensureCutterNumberColumn(PDO $pdo): void {
    $columns = $pdo->query('SHOW COLUMNS FROM books')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('cutter_number', $columns, true)) {
        $pdo->exec('ALTER TABLE books ADD COLUMN cutter_number VARCHAR(50) NULL AFTER call_number');
    }
}

ensureCutterNumberColumn($pdo);

$stmt = $pdo->query(
    'SELECT title, author, genre, image_path, ddc, cutter_number, year_of_publication, call_number
     FROM books
     WHERE total_copies > borrowed_copies
     ORDER BY created_at DESC
     LIMIT 4'
);

echo json_encode(['books' => $stmt->fetchAll()]);
