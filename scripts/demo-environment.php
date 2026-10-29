<?php
// CLI-only: owns a separate local demo database. Never seeds the source database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Manila');
umask(0077);
$root = dirname(__DIR__);
require $root . '/includes/db_config.php';
$command = $argv[1] ?? '';
$args = [];
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([a-z-]+)=(.+)$/D', $argument, $match)) $args[$match[1]] = $match[2];
}

function requireDemo(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function demoPdo(?string $database = null): PDO
{
    return new PDO('mysql:host=' . DB_HOST . (DB_PORT !== '' ? ';port=' . DB_PORT : '')
        . ($database !== null ? ';dbname=' . $database : '') . ';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

function tableSnapshot(PDO $pdo): array
{
    $tables = [];
    $pdo->beginTransaction();
    try {
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            requireDemo((bool)preg_match('/^[a-z_]+$/D', $table), 'Unexpected table name.');
            $rows = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll();
            $hashes = array_map(static fn($row) => hash('sha256', serialize($row)), $rows);
            sort($hashes);
            $tables[$table] = ['count' => count($rows), 'hash' => hash('sha256', serialize($hashes))];
        }
    } finally { $pdo->rollBack(); }
    return $tables;
}

function privateJson(string $file, array $value): void
{
    requireDemo(file_put_contents($file, json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) !== false,
        'Could not save private verification evidence.');
    chmod($file, 0600);
}

function backupSource(PDO $source, string $workspace, string $database = DB_NAME, string $prefix = 'development'): array
{
    $source->beginTransaction();
    $schema = []; $rows = []; $counts = [];
    try {
        foreach ($source->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            requireDemo((bool)preg_match('/^[a-z_]+$/D', $table), 'Unexpected source table.');
            $schema[$table] = $source->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
            $rows[$table] = $source->query('SELECT * FROM `' . $table . '`')->fetchAll();
            $counts[$table] = count($rows[$table]);
        }
    } finally { $source->rollBack(); }
    $sql = "-- Private BCIHS backup. No CREATE DATABASE or USE statement.\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET time_zone='+08:00';\n";
    foreach ($schema as $ddl) $sql .= $ddl . ";\n";
    foreach ($rows as $table => $records) {
        foreach ($records as $record) {
            $columns = implode(',', array_map(static fn($column) => '`' . $column . '`', array_keys($record)));
            $values = implode(',', array_map(static fn($value) => $value === null ? 'NULL' : $source->quote((string)$value), $record));
            $sql .= 'INSERT INTO `' . $table . '` (' . $columns . ') VALUES (' . $values . ");\n";
        }
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    $file = $workspace . '/' . $prefix . '-backup.sql';
    requireDemo(file_put_contents($file, $sql) === strlen($sql), 'Backup write incomplete.');
    chmod($file, 0600);
    requireDemo(hash_file('sha256', $file) === hash('sha256', $sql), 'Backup checksum mismatch.');
    privateJson($workspace . '/' . $prefix . '-backup-manifest.json', ['database' => $database, 'tables' => $counts,
        'sha256' => hash_file('sha256', $file), 'created_at' => date(DATE_ATOM)]);
    return $schema;
}

function demoPasswords(array $args): array
{
    $file = $args['password-file'] ?? '';
    requireDemo(is_file($file) && !is_link($file) && (fileperms($file) & 0777) === 0600,
        'Provide --password-file pointing to a private 0600 JSON file with admin and user values.');
    $values = json_decode(file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
    foreach (['admin', 'user'] as $key) {
        requireDemo(is_string($values[$key] ?? null) && strlen($values[$key]) >= 12 && strlen($values[$key]) <= 72,
            'Both local demo passwords must be 12–72 bytes.');
    }
    return $values;
}

function seedDemo(PDO $demo, array $passwords, array $manifest): void
{
    $demo->beginTransaction();
    try {
        $demo->prepare('INSERT INTO admins(username,password_hash) VALUES(?,?)')
            ->execute(['admin', password_hash($passwords['admin'], PASSWORD_DEFAULT)]);
        // The real borrower login has no username column: "user" is a fictional School ID.
        $demo->prepare('INSERT INTO users(full_name,email,student_id,year_section,phone,password_hash,role,account_status) VALUES(?,?,?,?,?,?,?,?)')
            ->execute(['Library Reader', 'reader@example.invalid', 'user', 'Grade 10', null,
                password_hash($passwords['user'], PASSWORD_DEFAULT), 'student', 'accepted']);
        $book = $demo->prepare('INSERT INTO books(title,author,category,genre,call_number,accession_number,image_path,total_copies,edition,place_of_publication,publisher,year_of_publication,isbn,ddc,cutter_number,borrowed_copies) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)');
        foreach ($manifest['books'] as $row) $book->execute([$row['title'], $row['author'], $row['category'], $row['genre'],
            $row['call_number'], $row['accession_number'], $row['image_path'], $row['total_copies'], $row['edition'],
            $row['place_of_publication'], $row['publisher'], $row['year_of_publication'], $row['isbn'], $row['ddc'], $row['cutter_number']]);
        $notice = $demo->prepare('INSERT INTO announcements(title,body,category,image_path,created_at,posted_by) VALUES(?,?,?,?,?,NULL)');
        foreach ($manifest['announcements'] as $row) $notice->execute([$row['title'], $row['body'], $row['category'], $row['image_path'], $row['created_at']]);
        // Neutral demo settings, not copied school quotas or fabricated policy approvals.
        $demo->exec('INSERT INTO attendance_settings(id,daily_visit_limit,occupancy_limit,track_departures) VALUES(1,NULL,NULL,0)');
        $demo->exec('INSERT INTO reservation_settings(id,daily_limit,slot_limit) VALUES(1,NULL,NULL)');
        $demo->exec('INSERT INTO sms_notification_settings(id,allow_notifications,version) VALUES(1,0,0)');
        $demo->commit();
    } catch (Throwable $error) { if ($demo->inTransaction()) $demo->rollBack(); throw $error; }
}

function copyDemoApp(string $root, string $destination): void
{
    requireDemo(mkdir($destination, 0700), 'Cannot create private demo copy.');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->isLink()) continue;
        $relative = substr($file->getPathname(), strlen($root) + 1);
        $allowed = preg_match('#^(backend|css|js|images|demo-assets|vendor)/#', $relative)
            || preg_match('#^includes/[^/]+\.(php|htaccess)$#', $relative)
            || $relative === 'includes/.htaccess'
            || preg_match('#^[^/]+\.(html|php)$#', $relative) || $relative === '.htaccess'
            || in_array($relative, ['uploads/books/.htaccess', 'uploads/announcements/.htaccess'], true);
        if (!$allowed || preg_match('#(^|/)(db-local|sms-local|pickup-local|semaphore-api-key)\.php$#', $relative)
            || str_contains($relative, '/.')) continue;
        $target = $destination . '/' . $relative;
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
        requireDemo(copy($file->getPathname(), $target), 'Could not copy application file.');
    }
    // Apache protections are needed even though the bundled preview uses a loopback router.
    foreach (['includes', 'uploads/books', 'uploads/announcements'] as $folder) {
        if (!is_dir($destination . '/' . $folder)) mkdir($destination . '/' . $folder, 0700, true);
        copy($root . '/' . $folder . '/.htaccess', $destination . '/' . $folder . '/.htaccess');
    }
}

function refineDemo(PDO $demo, string $root, string $workspace, string $name): void
{
    requireDemo($name === 'bcihs_demo_20261028_236ee935', 'This reviewed content update is for the existing staging database only.');
    $lock = fopen($workspace . '/server.lock', 'c');
    requireDemo($lock && flock($lock, LOCK_EX | LOCK_NB), 'Stop the staging preview before content updates.');
    try {
        requireDemo(!is_file($workspace . '/server-running'), 'Preview still marked as running.');
        $before = tableSnapshot($demo);
        requireDemo($before === json_decode(file_get_contents($workspace . '/demo-seeded.json'), true),
            'Staging has additional changes; inspect them before updating content.');
        backupSource($demo, $workspace, $name, 'content-before-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)));
        $manifest = json_decode(file_get_contents($root . '/demo-assets/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        requireDemo(count($manifest['books']) === 10 && count($manifest['announcements']) === 4, 'Unexpected content manifest.');
        $fields = ['title','author','edition','place_of_publication','publisher','year_of_publication','isbn','category','call_number','cutter_number','accession_number','image_path','ddc','genre'];
        $sql = 'UPDATE books SET ' . implode(',', array_map(static fn($field) => '`' . $field . '`=:' . $field, $fields)) . ' WHERE id=:id';
        $update = $demo->prepare($sql);
        $demo->beginTransaction();
        try {
            foreach ($manifest['books'] as $row) {
                $find = $demo->prepare('SELECT id,total_copies,borrowed_copies FROM books WHERE image_path=? FOR UPDATE');
                $find->execute([$row['image_path']]); $matches = $find->fetchAll();
                requireDemo(count($matches) === 1 && (int)$matches[0]['total_copies'] === $row['total_copies']
                    && (int)$matches[0]['borrowed_copies'] === 0, 'Book identity or inventory changed.');
                $values = array_intersect_key($row, array_flip($fields)); $values['id'] = $matches[0]['id'];
                $update->execute($values);
            }
            foreach ($manifest['announcements'] as $row) {
                $find = $demo->prepare('SELECT id FROM announcements WHERE image_path=? FOR UPDATE');
                $find->execute([$row['image_path']]); $ids = $find->fetchAll(PDO::FETCH_COLUMN);
                requireDemo(count($ids) === 1, 'Announcement identity changed.');
                $demo->prepare('UPDATE announcements SET title=?,body=?,category=? WHERE id=?')
                    ->execute([$row['title'], $row['body'], $row['category'], $ids[0]]);
            }
            $demo->exec("UPDATE users SET full_name='Library Reader',email='reader@example.invalid',year_section='Grade 10' WHERE student_id='user' AND role='student' AND account_status='accepted' AND phone IS NULL");
            $demo->commit();
        } catch (Throwable $error) { if ($demo->inTransaction()) $demo->rollBack(); throw $error; }
        $after = tableSnapshot($demo);
        foreach ($before as $table => $state) {
            requireDemo($after[$table]['count'] === $state['count'], 'Refinement changed record counts.');
            if (!in_array($table, ['books','announcements','users'], true)) requireDemo($after[$table] === $state, 'Unrelated staging records changed.');
        }
        $files = array_merge(array_column($manifest['books'], 'image_path'), array_column($manifest['announcements'], 'image_path'),
            ['demo-assets/manifest.json']);
        foreach ($files as $file) {
            requireDemo((bool)preg_match('#^demo-assets/(?:[a-z-]+/)?[a-z.-]+$#D', $file) && is_file($root . '/' . $file), 'Unsafe asset path.');
            requireDemo(copy($root . '/' . $file, $workspace . '/app/' . $file), 'Could not refresh staging artwork.');
        }
        privateJson($workspace . '/content-refinement-verification.json', ['database'=>$name,'before'=>$before,'after'=>$after,
            'changed_tables'=>['books','announcements','users'],'metadata_only'=>true,'sms_unchanged'=>true,'verified_at'=>date(DATE_ATOM)]);
        privateJson($workspace . '/demo-seeded.json', $after);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

try {
    requireDemo(in_array($command, ['create', 'verify', 'serve', 'reset', 'export', 'refine'], true),
        'Commands: create, verify, serve, reset, export, refine. See DEMO-STAGING.md.');
    requireDemo(DB_NAME === 'bcihs_library' && in_array(DB_HOST, ['127.0.0.1','localhost','::1'], true),
        'Only the existing local development connection may provide the source schema.');
    $workspace = $args['workspace'] ?? '';
    requireDemo(str_starts_with($workspace, '/') && !str_contains($workspace, '..') && !is_link($workspace), 'Use an absolute private workspace.');
    $source = demoPdo(DB_NAME);
    $source->exec("SET time_zone='+08:00'");
    $before = tableSnapshot($source);
    if ($command === 'create') {
        $passwords = demoPasswords($args);
        requireDemo(!file_exists($workspace) && is_dir(dirname($workspace)), 'Use a new workspace under an existing private parent.');
        requireDemo(!str_starts_with(realpath(dirname($workspace)), '/Applications/MAMP/htdocs')
            && !str_starts_with(realpath(dirname($workspace)), $root), 'Workspace must be outside public/project directories.');
        requireDemo(mkdir($workspace, 0700), 'Cannot create workspace.');
        $schema = backupSource($source, $workspace);
        privateJson($workspace . '/source-before.json', $before);
        // Verify the backup by restoring into a newly owned throwaway database.
        $verifyName = 'bcihs_demo_verify_' . bin2hex(random_bytes(6));
        $server = demoPdo();
        $server->exec('CREATE DATABASE `' . $verifyName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $verification = demoPdo($verifyName);
            $verification->exec(file_get_contents($workspace . '/development-backup.sql'));
            requireDemo(tableSnapshot($verification) === $before, 'Backup restore did not match source records.');
        } finally { $server->exec('DROP DATABASE `' . $verifyName . '`'); }
        $name = 'bcihs_demo_' . date('Ymd') . '_' . bin2hex(random_bytes(4));
        $server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $demo = demoPdo($name);
        $demo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($schema as $ddl) $demo->exec($ddl);
        $demo->exec('SET FOREIGN_KEY_CHECKS=1');
        // SHOW CREATE carries source AUTO_INCREMENT counters; demo IDs start at 1.
        foreach (['admins','users','books','announcements','attendance','borrow_requests','reservations','sms_logs','sms_notification_audit'] as $table)
            $demo->exec('ALTER TABLE `' . $table . '` AUTO_INCREMENT=1');
        $empty = tableSnapshot($demo);
        privateJson($workspace . '/demo-empty.json', $empty);
        $manifest = json_decode(file_get_contents($root . '/demo-assets/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        seedDemo($demo, $passwords, $manifest);
        unset($passwords);
        copyDemoApp($root, $workspace . '/app');
        $config = ['BCIHS_DB_HOST'=>DB_HOST, 'BCIHS_DB_PORT'=>DB_PORT, 'BCIHS_DB_NAME'=>$name, 'BCIHS_DB_USER'=>DB_USER, 'BCIHS_DB_PASS'=>DB_PASS];
        file_put_contents($workspace . '/app/includes/db-local.php', '<?php return ' . var_export($config, true) . ";\n");
        file_put_contents($workspace . '/app/includes/sms-local.php', "<?php return ['mode'=>'simulate','provider'=>'simulation','live_enabled'=>false];\n");
        file_put_contents($workspace . '/app/includes/pickup-local.php', "<?php return ['BCIHS_PUBLIC_BASE_URL'=>'http://127.0.0.1:8899'];\n");
        mkdir($workspace . '/sessions', 0700);
        privateJson($workspace . '/demo-owner.json', ['database'=>$name,'source'=>DB_NAME,'created_at'=>date(DATE_ATOM),
            'schema_sha256'=>hash('sha256', json_encode(array_keys($schema))), 'local_only'=>true]);
        privateJson($workspace . '/demo-seeded.json', tableSnapshot($demo));
        requireDemo(tableSnapshot($source) === $before, 'Source records changed during preparation; inspect before continuing.');
        echo json_encode(['database'=>$name,'workspace'=>$workspace,'backup_restore_verified'=>true,
            'source_unchanged'=>true,'demo_counts'=>array_map(static fn($row)=>$row['count'],tableSnapshot($demo))], JSON_PRETTY_PRINT), "\n";
        exit;
    }
    requireDemo(is_file($workspace . '/demo-owner.json') && !is_link($workspace . '/demo-owner.json')
        && (fileperms($workspace . '/demo-owner.json') & 0777) === 0600, 'Private ownership marker missing.');
    $owner = json_decode(file_get_contents($workspace . '/demo-owner.json'), true, 16, JSON_THROW_ON_ERROR);
    $name = $owner['database'] ?? '';
    requireDemo((bool)preg_match('/^bcihs_demo_[0-9]{8}_[a-f0-9]{8}$/D', $name) && $name !== DB_NAME,
        'Refusing a non-owned or source database.');
    $local = require $workspace . '/app/includes/db-local.php';
    requireDemo(($local['BCIHS_DB_NAME'] ?? '') === $name, 'Demo application target mismatch.');
    $demo = demoPdo($name);
    if ($command === 'refine') {
        requireDemo(($args['confirm'] ?? '') === $name, 'Confirm the exact staging database before content refinement.');
        refineDemo($demo, $root, $workspace, $name);
    } elseif ($command === 'reset') {
        requireDemo(($args['confirm'] ?? '') === $name, 'Reset requires --confirm=the_exact_demo_database_name.');
        $passwords = demoPasswords($args);
        $lock = fopen($workspace . '/server.lock', 'c');
        requireDemo($lock && flock($lock, LOCK_EX | LOCK_NB), 'Another reset is in progress.');
        requireDemo(!is_file($workspace . '/server-running'), 'Stop the demo preview before reset.');
        privateJson($workspace . '/demo-before-reset-' . date('YmdHis') . '.json', tableSnapshot($demo));
        $demo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($demo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
                requireDemo((bool)preg_match('/^[a-z_]+$/D', $table), 'Unexpected demo table.');
                $demo->exec('TRUNCATE TABLE `' . $table . '`');
            }
        } finally { $demo->exec('SET FOREIGN_KEY_CHECKS=1'); }
        seedDemo($demo, $passwords, json_decode(file_get_contents($root . '/demo-assets/manifest.json'), true, 64, JSON_THROW_ON_ERROR));
        file_put_contents($workspace . '/app/includes/sms-local.php', "<?php return ['mode'=>'simulate','provider'=>'simulation','live_enabled'=>false];\n");
        foreach (glob($workspace . '/sessions/*') as $session) if (is_file($session)) unlink($session);
        // These belong only to the demo copy; source policies/audits are never touched.
        foreach (['borrowing-policy.json', 'report-local.json', '.borrowing-policy.lock'] as $file)
            if (is_file($workspace . '/app/includes/' . $file)) unlink($workspace . '/app/includes/' . $file);
        privateJson($workspace . '/demo-seeded.json', tableSnapshot($demo));
        flock($lock, LOCK_UN); fclose($lock);
    } elseif ($command === 'serve') {
        $lock = fopen($workspace . '/server.lock', 'c');
        requireDemo($lock && flock($lock, LOCK_EX | LOCK_NB), 'A preview or reset is already running.');
        $port = $args['port'] ?? '8899';
        requireDemo(ctype_digit($port) && (int)$port >= 1024 && (int)$port <= 65535, 'Invalid local port.');
        $router = $workspace . '/router.php';
        copy($root . '/scripts/demo-router.php', $router);
        $env = getenv();
        foreach (['SEMAPHORE_API_KEY','SEMAPHORE_SENDER_NAME','UNISMS_API_KEY','UNISMS_API_SECRET'] as $secretName)
            unset($env[$secretName]);
        foreach ($local as $key=>$value) $env[$key] = $value;
        $env['BCIHS_SMS_MODE']='simulate'; $env['BCIHS_SMS_PROVIDER']='simulation'; $env['BCIHS_SMS_LIVE_ENABLED']='0';
        $env['BCIHS_PUBLIC_BASE_URL']='http://127.0.0.1:' . $port;
        $env['BCIHS_DEMO_WEBROOT']=$workspace . '/app';
        file_put_contents($workspace . '/server-running', (string)getmypid());
        try {
            $process = proc_open([PHP_BINARY,'-d','display_errors=0','-d','disable_functions=curl_exec',
                '-d','session.save_path=' . $workspace . '/sessions','-S','127.0.0.1:' . $port,'-t',$workspace . '/app',$router],
                [0=>STDIN,1=>['file',$workspace . '/server.log','a'],2=>['file',$workspace . '/server.log','a']],$pipes,null,$env);
            requireDemo(is_resource($process), 'Could not start loopback preview.');
            echo 'Restricted local demo: http://127.0.0.1:' . $port . "/index.html\n";
            $serverExit = proc_close($process);
        } finally {
            if (is_file($workspace . '/server-running')) unlink($workspace . '/server-running');
            flock($lock, LOCK_UN); fclose($lock);
        }
        exit($serverExit);
    } elseif ($command === 'export') {
        requireDemo(tableSnapshot($demo) === json_decode(file_get_contents($workspace . '/demo-seeded.json'), true),
            'Only the clean owned demo can be exported for staging.');
        backupSource($demo, $workspace, $name, 'demo');
    }
    requireDemo(tableSnapshot($source) === $before, 'Source changed during this operation.');
    $baseline = json_decode(file_get_contents($workspace . '/source-before.json'), true, 32, JSON_THROW_ON_ERROR);
    echo json_encode(['database'=>$name,'source_unchanged'=>true,'source_matches_preparation_baseline'=>$before === $baseline,
        'demo_counts'=>array_map(static fn($row)=>$row['count'],tableSnapshot($demo)),
        'matches_clean_seed'=>tableSnapshot($demo) === json_decode(file_get_contents($workspace . '/demo-seeded.json'), true)],JSON_PRETTY_PRINT),"\n";
} catch (Throwable $error) {
    // Never print PDO/config exceptions: those may contain credentials or personal rows.
    fwrite(STDERR, "Demo operation stopped safely. Check local prerequisites and ownership; no automatic source restore/reset is performed.\n");
    exit(1);
}
