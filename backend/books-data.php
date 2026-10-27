<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/book-management.php';

try {
    // The catalog is also read by approved patrons, reports, and the dashboard.
    requireBookAccess($pdo);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        bookError(405, 'method_not_allowed');
    }
    $stmt = $pdo->query(
        'SELECT id, title, author, edition, place_of_publication, publisher, year_of_publication,
                isbn, category, call_number, cutter_number, accession_number, image_path, ddc, genre,
                total_copies, borrowed_copies, created_at
         FROM books ORDER BY created_at DESC, id DESC'
    );
    if (empty($_SESSION['book_csrf_token'])) {
        $_SESSION['book_csrf_token'] = bin2hex(random_bytes(32));
    }
    echo json_encode(['books' => $stmt->fetchAll(), 'csrfToken' => $_SESSION['book_csrf_token']]);
} catch (PDOException $exception) {
    error_log('Could not load the book catalog.');
    bookError(500, 'Could not load books. Please contact the administrator if this continues.');
}
