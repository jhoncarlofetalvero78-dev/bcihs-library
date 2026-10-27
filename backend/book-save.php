<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/book-management.php';

function validIsbn(string $isbn): bool
{
    if (preg_match('/^[0-9]{9}[0-9X]$/', $isbn)) {
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += ($isbn[$i] === 'X' ? 10 : (int) $isbn[$i]) * (10 - $i);
        }
        return $sum % 11 === 0;
    }
    if (preg_match('/^97[89][0-9]{10}$/', $isbn)) {
        $sum = 0;
        for ($i = 0; $i < 13; $i++) {
            $sum += (int) $isbn[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        return $sum % 10 === 0;
    }
    return false;
}

$newPhoto = null;
$oldPhoto = null;
try {
    requireBookAccess($pdo, true);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        bookError(405, 'method_not_allowed');
    }
    requireBookCsrf();
    $input = bookRequestInput();
    $id = null;
    if (array_key_exists('id', $input) && $input['id'] !== '') {
        $id = filter_var($input['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
        if ((!is_string($input['id']) && !is_int($input['id'])) || $id === false) {
            throw new RuntimeException('Invalid book id.', 422);
        }
    }

    $pdo->beginTransaction();
    $book = null;
    if ($id !== null) {
        $lookup = $pdo->prepare('SELECT * FROM books WHERE id = :id FOR UPDATE');
        $lookup->execute(['id' => $id]);
        $book = $lookup->fetch();
        if (!$book) {
            throw new RuntimeException('That book no longer exists.', 404);
        }
        $oldPhoto = $book['image_path'];
    }

    if (array_key_exists('classification_number', $input)) {
        $input['ddc'] = $input['classification_number'];
    }
    $fields = [
        'title' => [200, 'Title'], 'author' => [150, 'Author'], 'edition' => [50, 'Edition'],
        'place_of_publication' => [150, 'Place of publication'], 'publisher' => [150, 'Publisher'],
        'year_of_publication' => [10, 'Publication year'], 'isbn' => [17, 'ISBN'],
        'category' => [80, 'Category'], 'ddc' => [30, 'DDC Classification Number'],
        'call_number' => [120, 'Call Number'], 'accession_number' => [50, 'Accession Number'],
        'genre' => [100, 'Genre'],
    ];
    $values = [];
    foreach ($fields as $field => [$limit, $label]) {
        // Omitted edit fields retain their original value, including independent genre data.
        $value = $input[$field] ?? ($book[$field] ?? '');
        if (!is_string($value) || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new RuntimeException($label . ' must be plain text without control characters.', 422);
        }
        $value = trim($value);
        $length = preg_match_all('/./us', $value);
        if ($length === false || $length > $limit) {
            throw new RuntimeException($label . ' must be valid text of at most ' . $limit . ' characters.', 422);
        }
        $values[$field] = $value === '' ? null : $value;
    }
    if ($values['title'] === null || $values['author'] === null) {
        throw new RuntimeException('Title and author are required.', 422);
    }
    if (!$book && !array_key_exists('genre', $input)) {
        $values['genre'] = $values['category'];
    }
    if ($values['ddc'] !== null && $values['ddc'] !== ($book['ddc'] ?? null)
        && !preg_match('/^[0-9]{3}(\.[0-9]+)?$/', $values['ddc'])) {
        throw new RuntimeException('Enter the DDC only, such as 500 or 001.3. Put prefixes and Cutter information in Call Number.', 422);
    }
    if ($values['year_of_publication'] !== null && $values['year_of_publication'] !== ($book['year_of_publication'] ?? null)
        && !preg_match('/^[0-9]{4}$/', $values['year_of_publication'])) {
        throw new RuntimeException('Publication year must contain four digits, or be left blank.', 422);
    }

    $normalizedIsbn = strtoupper(str_replace(['-', ' '], '', $values['isbn'] ?? ''));
    if ($values['isbn'] !== null && $values['isbn'] !== ($book['isbn'] ?? null) && !validIsbn($normalizedIsbn)) {
        throw new RuntimeException('Enter a valid ISBN-10 or ISBN-13, including its check digit, or leave ISBN blank.', 422);
    }
    foreach (['isbn', 'accession_number'] as $field) {
        if ($values[$field] === null || ($book && $values[$field] === $book[$field])) {
            continue;
        }
        $expression = $field === 'isbn' ? "REPLACE(REPLACE(UPPER(isbn), '-', ''), ' ', '')" : 'TRIM(accession_number)';
        $duplicate = $pdo->prepare('SELECT id FROM books WHERE ' . $expression . ' = :value AND id <> :id LIMIT 1 FOR UPDATE');
        $duplicate->execute(['value' => $field === 'isbn' ? $normalizedIsbn : $values[$field], 'id' => $id ?? 0]);
        if ($duplicate->fetch()) {
            throw new RuntimeException($field === 'isbn'
                ? 'This ISBN already exists. Edit that title to update its copy count, or verify the edition.'
                : 'This Accession Number already belongs to another catalog record.', 409);
        }
    }

    $total = $input['total_copies'] ?? ($book['total_copies'] ?? 1);
    if ((!is_int($total) && !is_string($total)) || !preg_match('/^[0-9]+$/', (string) $total)
        || (float) $total > 4294967295) {
        throw new RuntimeException('Total copies must be a whole number from 0 to 4294967295.', 422);
    }
    $total = (int) $total;
    $borrowed = (int) ($book['borrowed_copies'] ?? 0);
    if ($total < $borrowed) {
        throw new RuntimeException('Total copies cannot be less than the number currently borrowed (' . $borrowed . ').', 422);
    }
    if (array_key_exists('borrowed_copies', $input)) {
        throw new RuntimeException('Borrowed copies are managed by borrowing and return transactions.', 422);
    }

    if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload = $_FILES['photo'];
        if (!is_int($upload['error']) || $upload['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The book picture could not be uploaded. Please try again.', 422);
        }
        if ($upload['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('Book picture must be 5 MB or smaller.', 422);
        }
        if (!is_uploaded_file($upload['tmp_name'])) {
            throw new RuntimeException('Invalid picture upload.', 422);
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime]) || @getimagesize($upload['tmp_name']) === false) {
            throw new RuntimeException('Book picture must be a valid JPG, PNG, or WebP image.', 422);
        }
        $directory = dirname(__DIR__) . '/uploads/books';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Picture storage is unavailable. Please contact the administrator.', 500);
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($upload['tmp_name'], $directory . '/' . $filename)) {
            throw new RuntimeException('The picture could not be saved. Please try again.', 500);
        }
        $newPhoto = 'uploads/books/' . $filename;
    }

    // The hidden legacy Cutter column is retained unchanged, never rebuilt from a call number.
    $values['cutter_number'] = $book['cutter_number'] ?? null;
    $values['image_path'] = $newPhoto ?? $oldPhoto;
    $values['total_copies'] = $total;
    if ($book) {
        $assignments = [];
        foreach (array_keys($values) as $field) {
            $assignments[] = $field . ' = :' . $field;
        }
        $values['id'] = $id;
        $stmt = $pdo->prepare('UPDATE books SET ' . implode(', ', $assignments) . ' WHERE id = :id');
    } else {
        $columns = array_keys($values);
        $stmt = $pdo->prepare('INSERT INTO books (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
    }
    $stmt->execute($values);
    $savedId = $id ?? (int) $pdo->lastInsertId();
    $pdo->commit();
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    removeBookPhoto($newPhoto);
    error_log('Book save failed.');
    $retry = in_array($exception->getCode(), ['40001', '23000'], true);
    bookError($retry ? 409 : 500, $retry
        ? 'The catalog changed during this save. Reload books, check the identifiers, and try again.'
        : 'Could not save the book. Please try again.');
} catch (RuntimeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    removeBookPhoto($newPhoto);
    bookError($exception->getCode() ?: 422, $exception->getMessage());
}

if ($newPhoto && $oldPhoto) {
    removeBookPhoto($oldPhoto);
}
echo json_encode(['success' => true, 'id' => $savedId]);
