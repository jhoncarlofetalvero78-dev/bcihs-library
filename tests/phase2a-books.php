<?php
// Local integration checks. Only records created by this run are cleaned up.
require_once __DIR__ . '/../scripts/local-cli.php';

$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:8888/bcihs-library', '/') . '/';
$url = parse_url($baseUrl);
if (!in_array($url['host'] ?? '', ['localhost', '127.0.0.1', '::1'], true)
    || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || !extension_loaded('curl')) {
    fwrite(STDERR, "Use a localhost URL and PHP with cURL enabled.\n");
    exit(1);
}
$prefix = 'PHASE2A-TEST-' . bin2hex(random_bytes(6));
$password = bin2hex(random_bytes(24));
$adminId = null;
$userIds = [];
$bookIds = [];
$cookieFiles = [];
$temporaryFiles = [];
$checks = 0;

function check(bool $passed, string $label): void
{
    global $checks;
    if (!$passed) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $checks++;
    echo 'PASS: ' . $label . PHP_EOL;
}

function request(string $endpoint, string $method = 'GET', $data = null, ?string $cookies = null, ?string $token = null): array
{
    global $baseUrl;
    $curl = curl_init($baseUrl . $endpoint);
    $headers = [];
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CUSTOMREQUEST => $method]);
    if ($cookies) {
        curl_setopt($curl, CURLOPT_COOKIEFILE, $cookies);
        curl_setopt($curl, CURLOPT_COOKIEJAR, $cookies);
    }
    if ($data !== null) {
        if (is_string($data)) {
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    }
    if ($token !== null) {
        $headers[] = 'X-CSRF-Token: ' . $token;
    }
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    $body = curl_exec($curl);
    if ($body === false) {
        throw new RuntimeException('Local HTTP request failed. Check that Apache is running.');
    }
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE) ?? '';
    if ($cookies) curl_setopt($curl, CURLOPT_COOKIELIST, 'FLUSH');
    curl_close($curl);
    return [$status, json_decode($body, true), $type];
}

function login(string $identifier): string
{
    global $password, $cookieFiles;
    $cookies = tempnam(sys_get_temp_dir(), 'bcihs-test-cookie-');
    $cookieFiles[] = $cookies;
    [$status] = request('backend/login-process.php', 'POST', ['identifier' => $identifier, 'password' => $password], $cookies);
    check($status === 302, 'test account login redirects');
    return $cookies;
}

function createBook(array $fields, string $cookies, string $token): int
{
    global $bookIds;
    [$status, $data] = request('backend/book-save.php', 'POST', json_encode($fields), $cookies, $token);
    check($status === 200 && !empty($data['success']) && !empty($data['id']), 'create a temporary catalog record');
    $id = (int) $data['id'];
    $bookIds[] = $id;
    return $id;
}

function storedBook(int $id): array
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM books WHERE id = :id');
    $stmt->execute(['id' => $id]);
    return $stmt->fetch() ?: [];
}


function requestDuringStockEdit(string $endpoint, array $data, int $bookId, string $cookies): ?array
{
    global $pdo, $baseUrl, $dsn, $options;
    $observer = new PDO($dsn, DB_USER, DB_PASS, $options);
    try {
        $observer->query('SELECT 1 FROM performance_schema.data_locks LIMIT 1');
    } catch (PDOException $exception) {
        echo "SKIP: deterministic concurrency probe needs MySQL 8 data_locks access.\n";
        return null;
    }
    $pdo->beginTransaction();
    $lock = $pdo->prepare('SELECT id FROM books WHERE id = :id FOR UPDATE');
    $lock->execute(['id' => $bookId]);
    $curl = curl_init($baseUrl . $endpoint);
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_COOKIEFILE => $cookies,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $multi = curl_multi_init();
    curl_multi_add_handle($multi, $curl);
    try {
        // Hold the stock row until the handler has read the old count and waits to update it.
        $deadline = microtime(true) + 4;
        $waiting = false;
        do {
            curl_multi_exec($multi, $running);
            $waiting = (int) $observer->query("SELECT COUNT(*) FROM performance_schema.data_locks WHERE LOCK_STATUS = 'WAITING' AND OBJECT_SCHEMA = 'bcihs_library' AND OBJECT_NAME = 'books'")->fetchColumn() > 0;
            if (!$waiting) curl_multi_select($multi, 0.05);
        } while (!$waiting && $running && microtime(true) < $deadline);
        check($waiting, 'concurrent checkout reached the locked stock update');
        $pdo->prepare('UPDATE books SET total_copies = 0 WHERE id = :id')->execute(['id' => $bookId]);
        $pdo->commit();
        do {
            curl_multi_exec($multi, $running);
            if ($running) curl_multi_select($multi, 0.1);
        } while ($running);
        return [curl_getinfo($curl, CURLINFO_RESPONSE_CODE), json_decode(curl_multi_getcontent($curl), true)];
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        curl_multi_remove_handle($multi, $curl);
        curl_multi_close($multi);
        curl_close($curl);
    }
}

try {
    foreach (['books-data.php', 'book-save.php', 'book-delete.php'] as $endpoint) {
        [$status, $data, $type] = request('backend/' . $endpoint, $endpoint === 'books-data.php' ? 'GET' : 'POST', '{}');
        check($status === 401 && isset($data['error']) && str_contains($type, 'application/json'), 'guest rejected: ' . $endpoint);
    }
    $stmt = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (:name, :hash)');
    $stmt->execute(['name' => $prefix, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
    $adminId = (int) $pdo->lastInsertId();
    $adminCookies = login($prefix);
    [$status, $data] = request('backend/books-data.php', 'GET', null, $adminCookies);
    check($status === 200 && is_array($data['books']) && strlen($data['csrfToken']) === 64, 'catalog JSON and CSRF token');
    $token = $data['csrfToken'];
    [$status] = request('backend/book-save.php', 'POST', '{}', $adminCookies);
    check($status === 403, 'save without CSRF token refused');
    [$status] = request('backend/book-delete.php', 'POST', '{"id":1}', $adminCookies, 'wrong-token');
    check($status === 403, 'delete with incorrect CSRF token refused');
    foreach (['book-save.php', 'book-delete.php'] as $endpoint) {
        [$status] = request('backend/' . $endpoint, 'GET', null, $adminCookies);
        check($status === 405, 'GET mutation refused: ' . $endpoint);
    }
    [$status] = request('backend/book-save.php', 'POST', '[1,2]', $adminCookies, $token);
    check($status === 400, 'JSON arrays refused');
    $isbn = '978' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    $sum = 0;
    for ($i = 0; $i < 12; $i++) $sum += (int) $isbn[$i] * ($i % 2 === 0 ? 1 : 3);
    $isbn .= (string) ((10 - $sum % 10) % 10);
    $formattedIsbn = substr($isbn, 0, 3) . '-' . substr($isbn, 3, 1) . '-' . substr($isbn, 4, 2) . '-' . substr($isbn, 6, 6) . '-' . substr($isbn, 12);
    $valid = ['title' => $prefix . ' Catalog', 'author' => 'Local Test Author', 'edition' => '2nd',
        'place_of_publication' => 'Batangas', 'publisher' => 'Local Test Publisher', 'year_of_publication' => '2024',
        'isbn' => $formattedIsbn, 'category' => $prefix, 'classification_number' => '001.3',
        'call_number' => 'Fil. 001.3 Z948 2024', 'accession_number' => $prefix, 'total_copies' => 5];
    foreach ([['title' => ''], ['author' => ''], ['total_copies' => -1], ['total_copies' => '1.5'],
        ['total_copies' => 'text'], ['total_copies' => 4294967296], ['classification_number' => 'Fil. 001.3'],
        ['classification_number' => '9999'], ['year_of_publication' => '20xx'], ['isbn' => substr($isbn, 0, 12) . ((int) $isbn[12] + 1) % 10],
        ['title' => ['invalid']], ['title' => str_repeat('a', 201)], ['id' => true], ['id' => '1abc'],
        ['borrowed_copies' => 2]] as $index => $invalid) {
        [$status, $error] = request('backend/book-save.php', 'POST', json_encode(array_merge($valid, $invalid)), $adminCookies, $token);
        check($status === 422 && isset($error['error']), 'server validation case ' . ($index + 1));
    }
    $id = createBook($valid, $adminCookies, $token);
    $saved = storedBook($id);
    check($saved['ddc'] === '001.3' && $saved['call_number'] === 'Fil. 001.3 Z948 2024'
        && $saved['cutter_number'] === null && (int) $saved['borrowed_copies'] === 0, 'DDC, full call number, and new inventory persisted');
    foreach ([['isbn' => $isbn, 'accession_number' => $prefix . '-different'],
        ['isbn' => '', 'accession_number' => strtolower($prefix)]] as $duplicate) {
        [$status] = request('backend/book-save.php', 'POST', json_encode(array_merge($valid, $duplicate)), $adminCookies, $token);
        check($status === 409, 'duplicate normalized identifier refused');
    }
    [$status] = request('backend/book-save.php', 'POST', json_encode(['id' => $id, 'title' => $prefix . ' Edited',
        'call_number' => 'LOCAL 001.3 Z948 revised', 'total_copies' => 6]), $adminCookies, $token);
    $edited = storedBook($id);
    check($status === 200 && $edited['call_number'] === 'LOCAL 001.3 Z948 revised'
        && $edited['publisher'] === $saved['publisher'] && $edited['ddc'] === $saved['ddc']
        && $edited['edition'] === $saved['edition'], 'partial edit preserves omitted metadata and exact Call Number');

    $stmt = $pdo->prepare('UPDATE books SET cutter_number = :cutter, genre = :genre, ddc = :ddc, year_of_publication = :year WHERE id = :id');
    $stmt->execute(['cutter' => 'LEGACY-C948', 'genre' => 'Historical independent genre', 'ddc' => 'Fil. 001.3', 'year' => 'c. 1990', 'id' => $id]);
    [$status] = request('backend/book-save.php', 'POST', json_encode(['id' => $id, 'title' => $prefix . ' Legacy',
        'category' => 'Reference', 'classification_number' => 'Fil. 001.3', 'year_of_publication' => 'c. 1990']), $adminCookies, $token);
    $legacy = storedBook($id);
    check($status === 200 && $legacy['cutter_number'] === 'LEGACY-C948' && $legacy['genre'] === 'Historical independent genre'
        && $legacy['ddc'] === 'Fil. 001.3' && $legacy['call_number'] === $edited['call_number'], 'unchanged legacy DDC/year, Cutter, genre and Call Number preserved');
    $pdo->prepare('UPDATE books SET borrowed_copies = 2 WHERE id = :id')->execute(['id' => $id]);
    [$status] = request('backend/book-save.php', 'POST', json_encode(['id' => $id, 'total_copies' => 1]), $adminCookies, $token);
    check($status === 422 && (int) storedBook($id)['total_copies'] === 6, 'cannot reduce total below borrowed copies');
    [$status] = request('backend/book-delete.php', 'POST', json_encode(['id' => $id]), $adminCookies, $token);
    check($status === 409 && storedBook($id) !== [], 'borrowed inventory prevents deletion');
    $pdo->prepare('UPDATE books SET borrowed_copies = 0 WHERE id = :id')->execute(['id' => $id]);
    $pdo->prepare('INSERT INTO borrow_requests (borrower_name, book_id, status) VALUES (:name, :id, "returned")')
        ->execute(['name' => $prefix, 'id' => $id]);
    [$status] = request('backend/book-delete.php', 'POST', json_encode(['id' => $id]), $adminCookies, $token);
    check($status === 409 && storedBook($id) !== [], 'returned borrowing history prevents cascade deletion');

    $accounts = [];
    foreach (['student', 'librarian'] as $role) {
        $email = strtolower($prefix . '-' . $role) . '@example.invalid';
        $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, account_status) VALUES (:name, :email, :hash, :role, "accepted")')
            ->execute(['name' => $prefix, 'email' => $email, 'hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => $role]);
        $userId = (int) $pdo->lastInsertId();
        $userIds[] = $userId;
        $cookies = login($email);
        [$status, $data] = request('backend/books-data.php', 'GET', null, $cookies);
        check($status === 200 && is_array($data['books']), 'approved ' . $role . ' retains catalog access');
        $accounts[$role] = ['id' => $userId, 'cookies' => $cookies, 'token' => $data['csrfToken']];
    }
    $student = $accounts['student'];
    [$status] = request('backend/book-save.php', 'POST', json_encode(['title' => $prefix, 'author' => 'Test']), $student['cookies'], $student['token']);
    check($status === 403, 'patron cannot save books');
    [$status] = request('backend/book-delete.php', 'POST', json_encode(['id' => $id]), $student['cookies'], $student['token']);
    check($status === 403, 'patron cannot delete books');
    $librarian = $accounts['librarian'];
    $librarianBook = createBook(['title' => $prefix . ' Librarian', 'author' => 'Test', 'classification_number' => '500', 'total_copies' => 0], $librarian['cookies'], $librarian['token']);
    check((int) storedBook($librarianBook)['total_copies'] === 0, 'zero-copy catalog entry supported');
    $pdo->prepare('UPDATE users SET account_status = "pending" WHERE id = :id')->execute(['id' => $librarian['id']]);
    [$status] = request('backend/book-save.php', 'POST', json_encode(['id' => $librarianBook, 'title' => 'Unauthorized edit']), $librarian['cookies'], $librarian['token']);
    check($status === 401 && storedBook($librarianBook)['title'] === $prefix . ' Librarian', 'revoked approval invalidates cached librarian access');

    $pdo->prepare('DELETE FROM borrow_requests WHERE book_id = :id')->execute(['id' => $id]);
    $pdo->prepare('INSERT INTO reservations (user_id, book_id, status) VALUES (:user, :book, "cancelled")')
        ->execute(['user' => $student['id'], 'book' => $id]);
    [$status] = request('backend/book-delete.php', 'POST', json_encode(['id' => $id]), $adminCookies, $token);
    check($status === 409 && storedBook($id) !== [], 'cancelled reservation history prevents cascade deletion');
    $historyCount = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE book_id = :id');
    $historyCount->execute(['id' => $id]);
    check((int) $historyCount->fetchColumn() === 1, 'reservation history remains intact after blocked deletion');


    $stockBook = createBook(['title' => $prefix . ' Stock race', 'author' => 'Test', 'total_copies' => 1], $adminCookies, $token);
    $pdo->prepare('INSERT INTO borrow_requests (borrower_name, book_id, status) VALUES (:name, :book, "pending")')
        ->execute(['name' => $prefix, 'book' => $stockBook]);
    $requestId = (int) $pdo->lastInsertId();
    $race = requestDuringStockEdit('backend/borrow-request-action.php', ['id' => $requestId, 'action' => 'approve'], $stockBook, $adminCookies);
    if ($race !== null) {
        check($race[0] === 422 && (int) storedBook($stockBook)['borrowed_copies'] === 0, 'approval cannot use stock removed by a concurrent catalog edit');
    }
    $pdo->prepare('UPDATE books SET total_copies = 1 WHERE id = :id')->execute(['id' => $stockBook]);
    [$status] = request('backend/borrow-request-action.php', 'POST', json_encode(['id' => $requestId, 'action' => 'approve']), $adminCookies);
    check($status === 200 && (int) storedBook($stockBook)['borrowed_copies'] === 1, 'normal approval still increments stock once without sending SMS');
    [$status] = request('backend/borrow-request-action.php', 'POST', json_encode(['id' => $requestId, 'action' => 'approve']), $adminCookies);
    check($status === 422 && (int) storedBook($stockBook)['borrowed_copies'] === 1, 'repeat approval does not increment stock twice');

    $reservedBook = createBook(['title' => $prefix . ' Reservation stock race', 'author' => 'Test', 'total_copies' => 1], $adminCookies, $token);
    $pdo->prepare('INSERT INTO reservations (user_id, book_id, status) VALUES (:user, :book, "waiting")')
        ->execute(['user' => $student['id'], 'book' => $reservedBook]);
    $reservationId = (int) $pdo->lastInsertId();
    $race = requestDuringStockEdit('backend/reservation-action.php', ['id' => $reservationId, 'action' => 'fulfill'], $reservedBook, $adminCookies);
    if ($race !== null) {
        check($race[0] === 422 && (int) storedBook($reservedBook)['borrowed_copies'] === 0, 'fulfillment cannot use stock removed by a concurrent catalog edit');
    }
    $pdo->prepare('UPDATE books SET total_copies = 1 WHERE id = :id')->execute(['id' => $reservedBook]);
    [$status] = request('backend/reservation-action.php', 'POST', json_encode(['id' => $reservationId, 'action' => 'fulfill']), $adminCookies);
    check($status === 200 && (int) storedBook($reservedBook)['borrowed_copies'] === 1, 'normal reservation fulfillment still increments once');
    [$status] = request('backend/reservation-action.php', 'POST', json_encode(['id' => $reservationId, 'action' => 'fulfill']), $adminCookies);
    check($status === 422 && (int) storedBook($reservedBook)['borrowed_copies'] === 1, 'repeat fulfillment does not increment stock twice');

    $picture = tempnam(sys_get_temp_dir(), 'bcihs-test-picture-');
    $temporaryFiles[] = $picture;
    file_put_contents($picture, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9s8AAAAASUVORK5CYII='));
    [$status] = request('backend/book-save.php', 'POST', ['id' => (string) $id, 'photo' => new CURLFile($picture, 'image/png', 'cover.png')], $adminCookies, $token);
    $firstPhoto = storedBook($id)['image_path'];
    check($status === 200 && $firstPhoto && is_file(dirname(__DIR__) . '/' . $firstPhoto), 'valid multipart book cover saved');
    [$status] = request('backend/book-save.php', 'POST', ['id' => (string) $id, 'photo' => new CURLFile($picture, 'image/png', 'replacement.png')], $adminCookies, $token);
    $secondPhoto = storedBook($id)['image_path'];
    check($status === 200 && $secondPhoto !== $firstPhoto && !is_file(dirname(__DIR__) . '/' . $firstPhoto), 'cover replacement removes only the old test photo');
    file_put_contents($picture, '<?php echo "not an image";');
    [$status] = request('backend/book-save.php', 'POST', ['id' => (string) $id, 'photo' => new CURLFile($picture, 'image/png', 'fake.png')], $adminCookies, $token);
    check($status === 422 && storedBook($id)['image_path'] === $secondPhoto, 'forged image rejected without losing the existing cover');
    file_put_contents($picture, str_repeat('x', 5 * 1024 * 1024 + 1));
    [$status] = request('backend/book-save.php', 'POST', ['id' => (string) $id, 'photo' => new CURLFile($picture, 'image/png', 'oversize.png')], $adminCookies, $token);
    check($status === 422 && storedBook($id)['image_path'] === $secondPhoto, 'oversized upload refused without losing the existing cover');

    [$status] = request('backend/book-save.php', 'POST', json_encode(['id' => 4294967295, 'title' => $prefix, 'author' => 'Test']), $adminCookies, $token);
    check($status === 404, 'missing edit target returns 404');
    [$status] = request('backend/book-delete.php', 'POST', '{"id":true}', $adminCookies, $token);
    check($status === 422, 'boolean delete id rejected');
    $pdo->prepare('DELETE FROM reservations WHERE book_id = :id')->execute(['id' => $id]);
    [$status, $data] = request('backend/book-delete.php', 'POST', json_encode(['id' => $id]), $adminCookies, $token);
    check($status === 200 && !empty($data['success']) && storedBook($id) === []
        && !is_file(dirname(__DIR__) . '/' . $secondPhoto), 'unused test title and its cover can be deleted');
    [$status] = request('backend/book-delete.php', 'POST', json_encode(['id' => $id]), $adminCookies, $token);
    check($status === 404, 'repeated delete returns 404');
    echo 'Completed ' . $checks . " local API checks.\n";
} catch (Throwable $exception) {
    // Deliberately do not print raw database or HTTP details, passwords, or cookies.
    fwrite(STDERR, $exception instanceof PDOException ? "Test fixture database operation failed.\n" : $exception->getMessage() . PHP_EOL);
    $failed = true;
} finally {
    require_once __DIR__ . '/../includes/book-management.php';
    foreach ($bookIds as $bookId) {
        $book = storedBook($bookId);
        $pdo->prepare('DELETE FROM borrow_requests WHERE book_id = :id')->execute(['id' => $bookId]);
        $pdo->prepare('DELETE FROM reservations WHERE book_id = :id')->execute(['id' => $bookId]);
        $pdo->prepare('DELETE FROM books WHERE id = :id')->execute(['id' => $bookId]);
        removeBookPhoto($book['image_path'] ?? null);
    }
    foreach ($userIds as $userId) {
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
    }
    if ($adminId !== null) {
        $pdo->prepare('DELETE FROM admins WHERE id = :id')->execute(['id' => $adminId]);
    }
    foreach (array_merge($cookieFiles, $temporaryFiles) as $file) {
        if (is_file($file)) unlink($file);
    }
    echo "Temporary test accounts, catalog records, transactions, photos and cookie files cleaned up.\n";
}
exit(isset($failed) ? 1 : 0);
