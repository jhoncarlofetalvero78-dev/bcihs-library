<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';
header('Content-Type: application/json');

function fail(int $code, string $message) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

function removeBookPhoto(?string $path): void {
    if (!$path || !preg_match('#^uploads/books/[a-f0-9]{32}\.(jpg|png|webp)$#', $path)) {
        return;
    }

    $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (is_file($file) && !unlink($file)) {
        error_log('Could not remove book photo: ' . $file);
    }
}

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

$isLibraryStaff = isset($_SESSION['admin_id']) || (
    isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'librarian'
);
if (!$isLibraryStaff) {
    fail(401, 'not_logged_in');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'method_not_allowed');
}

ensureBookMetadataColumns($pdo);

$input = $_POST;
if (!$input) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}

$id = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;
$title = trim($input['title'] ?? '');
$author = trim($input['author'] ?? '');
$edition = trim($input['edition'] ?? '');
$place_of_publication = trim($input['place_of_publication'] ?? $input['place'] ?? '');
$publisher = trim($input['publisher'] ?? '');
$year_of_publication = trim($input['year_of_publication'] ?? $input['publication_year'] ?? '');
$isbn = trim($input['isbn'] ?? '');
$category = trim($input['category'] ?? $input['genre'] ?? '');
$classification_number = trim($input['classification_number'] ?? $input['ddc'] ?? $input['call_number'] ?? '');
$cutter_number = trim($input['cutter_number'] ?? '');
$call_number = implode(' ', array_filter(
    [$classification_number, $cutter_number, $year_of_publication],
    static fn(string $part): bool => $part !== ''
));
$accession_number = trim($input['accession_number'] ?? '');
$genre = $category;
$ddc = $classification_number;
$total_copies = isset($input['total_copies']) ? (int) $input['total_copies'] : 1;

if ($title === '' || $author === '') {
    fail(422, 'Title and author are required.');
}
if ($total_copies < 0) {
    fail(422, 'Copy counts cannot be negative.');
}

$oldPhoto = null;
if ($id) {
    $lookup = $pdo->prepare('SELECT image_path FROM books WHERE id = :id');
    $lookup->execute(['id' => $id]);
    $oldPhoto = $lookup->fetchColumn();
    if ($oldPhoto === false) {
        fail(404, 'That book no longer exists.');
    }
}

$newPhoto = null;
if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
    $upload = $_FILES['photo'];
    if ($upload['error'] !== UPLOAD_ERR_OK) {
        fail(422, 'The book picture could not be uploaded. Please try again.');
    }
    if ($upload['size'] > 5 * 1024 * 1024) {
        fail(422, 'Book picture must be 5 MB or smaller.');
    }
    if (!is_uploaded_file($upload['tmp_name'])) {
        fail(422, 'Invalid picture upload.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($extensions[$mime])) {
        fail(422, 'Book picture must be a JPG, PNG, or WebP image.');
    }

    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'books';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        fail(500, 'Picture storage is unavailable. Please contact the administrator.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($upload['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
        fail(500, 'The picture could not be saved. Please try again.');
    }
    $newPhoto = 'uploads/books/' . $filename;
}

try {
    if ($id) {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT borrowed_copies FROM books WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $book = $stmt->fetch();

        if (!$book) {
            $pdo->rollBack();
            fail(404, 'That book no longer exists.');
        }
        if ((int) $book['borrowed_copies'] > $total_copies) {
            $pdo->rollBack();
            fail(422, 'Total copies cannot be less than the number currently borrowed.');
        }

        $stmt = $pdo->prepare(
            'UPDATE books SET
                title = :title,
                author = :author,
                edition = :edition,
                place_of_publication = :place_of_publication,
                publisher = :publisher,
                year_of_publication = :year_of_publication,
                isbn = :isbn,
                category = :category,
                call_number = :call_number,
                cutter_number = :cutter_number,
                accession_number = :accession_number,
                image_path = :image_path,
                ddc = :ddc,
                genre = :genre,
                total_copies = :total_copies
             WHERE id = :id'
        );
        $stmt->execute([
            'title' => $title,
            'author' => $author,
            'edition' => $edition ?: null,
            'place_of_publication' => $place_of_publication ?: null,
            'publisher' => $publisher ?: null,
            'year_of_publication' => $year_of_publication ?: null,
            'isbn' => $isbn ?: null,
            'category' => $category ?: null,
            'call_number' => $call_number ?: null,
            'cutter_number' => $cutter_number ?: null,
            'accession_number' => $accession_number ?: null,
            'image_path' => $newPhoto ?? $oldPhoto,
            'ddc' => $ddc ?: null,
            'genre' => $genre ?: null,
            'total_copies' => $total_copies,
            'id' => $id,
        ]);
        $pdo->commit();
        $savedId = $id;
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO books (
                title, author, edition, place_of_publication, publisher, year_of_publication,
                isbn, category, call_number, cutter_number, accession_number, image_path, ddc, genre, total_copies
            ) VALUES (
                :title, :author, :edition, :place_of_publication, :publisher, :year_of_publication,
                :isbn, :category, :call_number, :cutter_number, :accession_number, :image_path, :ddc, :genre, :total_copies
            )'
        );
        $stmt->execute([
            'title' => $title,
            'author' => $author,
            'edition' => $edition ?: null,
            'place_of_publication' => $place_of_publication ?: null,
            'publisher' => $publisher ?: null,
            'year_of_publication' => $year_of_publication ?: null,
            'isbn' => $isbn ?: null,
            'category' => $category ?: null,
            'call_number' => $call_number ?: null,
            'cutter_number' => $cutter_number ?: null,
            'accession_number' => $accession_number ?: null,
            'image_path' => $newPhoto,
            'ddc' => $ddc ?: null,
            'genre' => $genre ?: null,
            'total_copies' => $total_copies,
        ]);
        $savedId = $pdo->lastInsertId();
    }
} catch (PDOException $exception) {
    if ($newPhoto) {
        removeBookPhoto($newPhoto);
    }
    error_log('Book save failed: ' . $exception->getMessage());
    fail(500, 'Could not save the book. Please try again.');
}

if ($newPhoto && $oldPhoto) {
    removeBookPhoto($oldPhoto);
}

echo json_encode(['success' => true, 'id' => $savedId]);
