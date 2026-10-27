<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/approved-user-session.php';
header('Content-Type: application/json');

function ensureBookMetadataColumns(PDO $pdo): void {
    $existingColumns = $pdo->query('SHOW COLUMNS FROM books')->fetchAll(PDO::FETCH_COLUMN);
    $columnsToAdd = [
        'edition' => 'VARCHAR(50) NULL AFTER author',
        'place_of_publication' => 'VARCHAR(150) NULL AFTER edition',
        'publisher' => 'VARCHAR(150) NULL AFTER place_of_publication',
        'year_of_publication' => 'VARCHAR(10) NULL AFTER publisher',
        'category' => 'VARCHAR(80) NULL AFTER isbn',
        'call_number' => 'VARCHAR(120) NULL AFTER category',
        'cutter_number' => 'VARCHAR(50) NULL AFTER call_number',
        'accession_number' => 'VARCHAR(50) NULL AFTER call_number',
        'image_path' => 'VARCHAR(255) NULL AFTER accession_number',
    ];

    foreach ($columnsToAdd as $column => $definition) {
        if (in_array($column, $existingColumns, true)) {
            continue;
        }
        $pdo->exec("ALTER TABLE books ADD COLUMN $column $definition");
    }

    $callNumberColumn = $pdo->query("SHOW COLUMNS FROM books LIKE 'call_number'")->fetch();
    if ($callNumberColumn && preg_match('/^varchar\((\d+)\)$/i', $callNumberColumn['Type'], $matches)
        && (int) $matches[1] < 120) {
        $pdo->exec('ALTER TABLE books MODIFY call_number VARCHAR(120) NULL');
    }
}

if (!requireApprovedUserSession($pdo)) {
    http_response_code(401);
    echo json_encode(['error' => 'account_not_accepted']);
    exit;
}

if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'not_logged_in']);
    exit;
}

ensureBookMetadataColumns($pdo);

$stmt = $pdo->query(
    'SELECT id, title, author, edition, place_of_publication, publisher, year_of_publication,
            isbn, category, call_number, cutter_number, accession_number, image_path, ddc, genre,
            total_copies, borrowed_copies, created_at
     FROM books ORDER BY created_at DESC'
);
$books = $stmt->fetchAll();

echo json_encode(['books' => $books]);
