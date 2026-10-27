<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/borrowing.php';

try {
    requireBookAccess($pdo);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') bookError(405, 'method_not_allowed');
    $stmt = $pdo->query(
        'SELECT b.id, b.title, b.author, b.edition, b.place_of_publication, b.publisher, b.year_of_publication,
                b.isbn, b.category, b.call_number, b.cutter_number, b.accession_number, b.image_path, b.ddc, b.genre,
                b.total_copies, b.borrowed_copies, b.created_at, (' . BOOK_HOLDS_SQL . ') AS held_copies
         FROM books b ORDER BY b.created_at DESC, b.id DESC'
    );
    echo json_encode(['books' => addBookAvailability($stmt->fetchAll()), 'csrfToken' => bookCsrfToken(), 'serverDate' => date('Y-m-d')]);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
