<?php
require_once __DIR__ . '/local-cli.php';

// Column names come from the checked-in schema, never from user input.
$requiredColumns = [
    'admins' => 'id username password_hash created_at',
    'users' => 'id full_name email student_id year_section id_number department phone avatar_path password_hash role account_status reset_code reset_code_expires last_login created_at',
    'books' => 'id title author edition place_of_publication publisher year_of_publication isbn category call_number cutter_number accession_number image_path ddc genre total_copies borrowed_copies created_at',
    'borrow_requests' => 'id user_id borrower_name book_id status requested_at approved_at due_date rejected_at returned_at',
    'reservations' => 'id user_id book_id status reserved_at pickup_at ready_at fulfilled_at returned_at',
    'attendance' => 'id user_id student_name year_level section student_identifier time_in',
    'announcements' => 'id title body category image_path posted_by created_at',
    'sms_logs' => 'id user_id phone message status response created_at',
];

fwrite(STDOUT, "Connected to local bcihs_library. Checking tables and columns without changing data.\n");
$hasProblems = false;
foreach ($requiredColumns as $table => $columns) {
    try {
        $existingColumns = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN);
        $missingColumns = array_diff(explode(' ', $columns), $existingColumns);
        if ($missingColumns) {
            fwrite(STDOUT, $table . ': missing columns: ' . implode(', ', $missingColumns) . PHP_EOL);
            $hasProblems = true;
            continue;
        }
        $pdo->query('SELECT ' . implode(', ', explode(' ', $columns)) . ' FROM `' . $table . '` LIMIT 0');
        fwrite(STDOUT, $table . ": accessible; all expected columns exist.\n");
    } catch (PDOException $exception) {
        fwrite(STDERR, $table . ": could not read the table. Check its existence and permissions.\n");
        $hasProblems = true;
    }
}

try {
    $userColumns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    $missingLegacyColumns = array_diff(['reset_token', 'reset_expires', 'otp_code', 'otp_expires'], $userColumns);
    if ($missingLegacyColumns) {
        fwrite(STDOUT, 'Warning: the older forgot-password-process.php endpoint needs extra users columns: '
            . implode(', ', $missingLegacyColumns) . ". See LOCAL_SETUP.md before considering any migration.\n");
    }
} catch (PDOException $exception) {
    $hasProblems = true;
}

fwrite(STDOUT, "No migrations or record changes were performed. This checks columns, not every constraint or workflow.\n");
exit($hasProblems ? 1 : 0);
