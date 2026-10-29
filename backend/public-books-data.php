<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/borrowing.php';

try {
    $stmt = $pdo->query(
        'SELECT b.title, b.author, b.category, b.genre, b.image_path, b.ddc, b.cutter_number, b.year_of_publication,
                b.call_number, b.total_copies, b.borrowed_copies, (' . BOOK_HOLDS_SQL . ') AS held_copies
         FROM books b WHERE b.total_copies - b.borrowed_copies > (' . BOOK_HOLDS_SQL . ')
         ORDER BY b.created_at DESC, b.id DESC LIMIT 4'
    );
    echo json_encode(['books' => addBookAvailability($stmt->fetchAll())]);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
