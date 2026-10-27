<?php
require_once __DIR__ . '/../scripts/local-backup.php';
require_once __DIR__ . '/../includes/borrowing.php';

$mode = $argv[1] ?? '';
$directory = $argv[2] ?? '';
if (!in_array($mode, ['setup','stock','cleanup'], true) || !is_dir($directory)
    || str_starts_with(realpath($directory) . '/', realpath(dirname(__DIR__, 2)) . '/')) {
    fwrite(STDERR, "Usage: php tests/phase4-browser-fixtures.php setup|stock|cleanup PRIVATE_DIRECTORY\n");exit(1);
}
$file = realpath($directory) . '/phase4-browser-fixtures.json';
if ($mode === 'setup') {
    if (is_file($file)) { fwrite(STDERR,"Clean up the existing fixture manifest first.\n");exit(1); }
    if ((int)$pdo->query('SELECT COUNT(*) FROM reservations WHERE status IN ("waiting","ready") AND pickup_at>=CURDATE()')->fetchColumn()) {
        fwrite(STDERR,"Existing future schedules found. Do not change their policy.\n");exit(1);
    }
    $backup = backupLocalDatabase($pdo, $directory, '/Applications/MAMP/Library/bin/mysql80/bin/mysqldump');
    $prefix = 'PHASE4-UI-' . bin2hex(random_bytes(4));
    $password = bin2hex(random_bytes(16));
    $state = ['prefix'=>$prefix, 'password'=>$password, 'students'=>[], 'books'=>[], 'admin'=>null,
        'baseline'=>localRowSnapshot($pdo), 'settings'=>$pdo->query('SELECT daily_limit,slot_limit FROM reservation_settings WHERE id=1')->fetch()];
    $save = static function() use (&$state,$file): void { file_put_contents($file,json_encode($state,JSON_PRETTY_PRINT));chmod($file,0600); };
    $save();
    foreach (['Student','Capacity'] as $name) {
        $email = strtolower($prefix.'-'.$name).'@example.invalid';
        $pdo->prepare('INSERT INTO users(full_name,email,password_hash,role,account_status) VALUES(:name,:email,:hash,"student","accepted")')
            ->execute(['name'=>$prefix.' '.$name,'email'=>$email,'hash'=>password_hash($password,PASSWORD_DEFAULT)]);
        $state['students'][] = ['id'=>(int)$pdo->lastInsertId(),'email'=>$email];$save();
    }
    $pdo->prepare('INSERT INTO admins(username,password_hash)VALUES(:name,:hash)')->execute(['name'=>$prefix,'hash'=>password_hash($password,PASSWORD_DEFAULT)]);
    $state['admin']=(int)$pdo->lastInsertId();$save();
    foreach (['Reservation Book','Capacity Book'] as $title) {
        $pdo->prepare('INSERT INTO books(title,author,total_copies,borrowed_copies)VALUES(:title,"Local UI Test",0,0)')->execute(['title'=>$prefix.' '.$title]);
        $state['books'][]=(int)$pdo->lastInsertId();$save();
    }
    $day=new DateTimeImmutable('+2 days');while((int)$day->format('N')>5)$day=$day->modify('+1 day');
    $state['day']=$day->format('Y-m-d');
    $pdo->prepare('INSERT INTO reservations(user_id,book_id,status,pickup_at)VALUES(:user,:book,"waiting",:pickup)')
        ->execute(['user'=>$state['students'][1]['id'],'book'=>$state['books'][1],'pickup'=>$state['day'].' 09:00:00']);
    $pdo->exec('UPDATE reservation_settings SET daily_limit=2,slot_limit=1 WHERE id=1');$save();
    echo 'Disposable browser fixtures prepared. Private manifest: '.$file.PHP_EOL.'Backup: '.$backup.PHP_EOL;
} else {
    if (!is_file($file)) { fwrite(STDERR,"No fixture manifest found.\n");exit(1); }
    $state=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    if ($mode === 'stock') {
        $q=$pdo->prepare('UPDATE books SET total_copies=1 WHERE id=:id AND title=:title AND borrowed_copies=0');
        $q->execute(['id'=>$state['books'][0],'title'=>$state['prefix'].' Reservation Book']);
        echo 'Fixture book stock available: '.$q->rowCount().PHP_EOL;exit;
    }
    $pdo->beginTransaction();
    try {
        foreach($state['books'] as $id){
            $q=$pdo->prepare('SELECT title FROM books WHERE id=:id FOR UPDATE');$q->execute(['id'=>$id]);
            if(!str_starts_with((string)$q->fetchColumn(),$state['prefix'].' '))throw new RuntimeException('Fixture identity mismatch.');
            foreach(['borrow_requests','reservations'] as $table)$pdo->prepare('DELETE FROM '.$table.' WHERE book_id=:id')->execute(['id'=>$id]);
            $pdo->prepare('DELETE FROM books WHERE id=:id')->execute(['id'=>$id]);
        }
        foreach($state['students'] as $student){
            $pdo->prepare('DELETE FROM sms_logs WHERE user_id=:id')->execute(['id'=>$student['id']]);
            $pdo->prepare('DELETE FROM users WHERE id=:id AND email=:email')->execute(['id'=>$student['id'],'email'=>$student['email']]);
        }
        if($state['admin'])$pdo->prepare('DELETE FROM admins WHERE id=:id AND username=:name')->execute(['id'=>$state['admin'],'name'=>$state['prefix']]);
        $pdo->prepare('UPDATE reservation_settings SET daily_limit=:daily,slot_limit=:slot WHERE id=1')->execute(['daily'=>$state['settings']['daily_limit'],'slot'=>$state['settings']['slot_limit']]);
        if(localRowSnapshot($pdo)!==$state['baseline'])throw new RuntimeException('Original row verification failed; cleanup rolled back.');
        $pdo->commit();unlink($file);echo "Browser fixtures removed; original rows and settings verified unchanged.\n";
    }catch(Throwable $exception){$pdo->rollBack();fwrite(STDERR,$exception->getMessage().PHP_EOL);exit(1);}
}
