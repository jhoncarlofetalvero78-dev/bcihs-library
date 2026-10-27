<?php
require_once __DIR__ . '/../scripts/local-backup.php';
require_once __DIR__ . '/../includes/attendance.php';
$mode=$argv[1]??'';$directory=$argv[2]??'';
if(!in_array($mode,['setup','cleanup'],true) || !is_dir($directory)
    || str_starts_with(realpath($directory).'/',realpath(dirname(__DIR__,2)).'/')) {
    fwrite(STDERR,"Usage: php tests/phase6a-browser-fixtures.php setup|cleanup PRIVATE_DIRECTORY\n");exit(1);
}
$file=realpath($directory).'/phase6a-browser-fixtures.json';
if($mode==='setup') {
    if(is_file($file))throw new RuntimeException('Clean existing fixtures first.');
    $backup=backupLocalDatabase($pdo,$directory,'/Applications/MAMP/Library/bin/mysql80/bin/mysqldump','phase6a-ui');
    $state=['prefix'=>'PHASE6A-UI-'.bin2hex(random_bytes(4)),'password'=>bin2hex(random_bytes(16)),
        'baseline'=>localRowSnapshot($pdo),'settings'=>attendanceSettings($pdo),'students'=>[],'books'=>[],'admin'=>null];
    $save=static function()use(&$state,$file){file_put_contents($file,json_encode($state,JSON_PRETTY_PRINT));chmod($file,0600);};$save();
    foreach(['Student','Other'] as $label) {
        $email=strtolower($state['prefix'].'-'.$label).'@example.invalid';
        $pdo->prepare('INSERT INTO users(full_name,email,student_id,year_section,password_hash,role,account_status)VALUES(:name,:email,:identifier,"Grade 10 / Test Section",:hash,"student","accepted")')
            ->execute(['name'=>$state['prefix'].' '.$label,'email'=>$email,'identifier'=>$state['prefix'].'-'.$label,'hash'=>password_hash($state['password'],PASSWORD_DEFAULT)]);
        $state['students'][]=['id'=>(int)$pdo->lastInsertId(),'email'=>$email];$save();
    }
    $pdo->prepare('INSERT INTO admins(username,password_hash)VALUES(:name,:hash)')->execute(['name'=>$state['prefix'],'hash'=>password_hash($state['password'],PASSWORD_DEFAULT)]);
    $state['admin']=(int)$pdo->lastInsertId();$save();
    $pdo->prepare('INSERT INTO books(title,author,total_copies,borrowed_copies,ddc,accession_number)VALUES(:title,"Local Test",5,2,"500",:accession)')
        ->execute(['title'=>$state['prefix'].' Circulation Book','accession'=>$state['prefix'].'-1']);
    $state['books'][]=(int)$pdo->lastInsertId();$save();
    $pdo->prepare('INSERT INTO books(title,author,total_copies,borrowed_copies)VALUES(:title,:author,2,0)')
        ->execute(['title'=>$state['prefix']." Café, \"Reference\"\nEdition",'author'=>'=TEST ONLY']);
    $state['books'][]=(int)$pdo->lastInsertId();$save();
    $book=$state['books'][0];$student=$state['students'][0]['id'];
    foreach(['pending','awaiting_pickup','checked_out','returned','rejected'] as $status) {
        $pdo->prepare('INSERT INTO borrow_requests(user_id,book_id,status,checked_out_at,returned_at,due_date)VALUES(:user,:book,:status,:out,:returned,DATE_SUB(NOW(),INTERVAL 1 DAY))')
            ->execute(['user'=>$student,'book'=>$book,'status'=>$status,'out'=>in_array($status,['checked_out','returned'],true)?date('Y-m-d').' 08:00:00':null,'returned'=>$status==='returned'?date('Y-m-d').' 09:00:00':null]);
    }
    foreach(['waiting','ready','fulfilled','returned','cancelled'] as $status) {
        $pdo->prepare('INSERT INTO reservations(user_id,book_id,status,pickup_at,fulfilled_at,returned_at,due_date)VALUES(:user,:book,:status,:pickup,:out,:returned,DATE_ADD(NOW(),INTERVAL 1 DAY))')
            ->execute(['user'=>$state['students'][1]['id'],'book'=>$book,'status'=>$status,'pickup'=>date('Y-m-d').' 15:00:00','out'=>in_array($status,['fulfilled','returned'],true)?date('Y-m-d').' 10:00:00':null,'returned'=>$status==='returned'?date('Y-m-d').' 11:00:00':null]);
    }
    foreach([[$student,'08:00:00',0,null],[$student,'09:00:00',1,date('Y-m-d').' 09:30:00'],[null,'10:00:00',0,null]] as [$user,$time,$tracking,$out]) {
        $pdo->prepare('INSERT INTO attendance(user_id,student_name,year_level,section,time_in,tracks_departure,time_out)VALUES(:user,:name,"Grade 10","Test",:time,:tracking,:out)')
            ->execute(['user'=>$user,'name'=>$state['prefix'].' Visitor','time'=>date('Y-m-d').' '.$time,'tracking'=>$tracking,'out'=>$out]);
    }
    echo 'Disposable UI fixtures ready. Private manifest: '.$file.PHP_EOL.'Backup: '.$backup.PHP_EOL;
} else {
    if(!is_file($file))throw new RuntimeException('No fixture manifest.');
    $state=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    $pdo->beginTransaction();
    try {
        foreach($state['books'] as $book) {
            $q=$pdo->prepare('SELECT title FROM books WHERE id=:id FOR UPDATE');$q->execute(['id'=>$book]);
            if(!str_starts_with((string)$q->fetchColumn(),$state['prefix']))throw new RuntimeException('Fixture book mismatch.');
            foreach(['borrow_requests','reservations'] as $table)$pdo->prepare('DELETE FROM '.$table.' WHERE book_id=:id')->execute(['id'=>$book]);
            $pdo->prepare('DELETE FROM books WHERE id=:id')->execute(['id'=>$book]);
        }
        foreach($state['students'] as $student) {
            $q=$pdo->prepare('SELECT email FROM users WHERE id=:id FOR UPDATE');$q->execute(['id'=>$student['id']]);
            if($q->fetchColumn()!==$student['email'])throw new RuntimeException('Fixture identity mismatch.');
        }
        $pdo->prepare('DELETE FROM attendance WHERE student_name LIKE :prefix')->execute(['prefix'=>$state['prefix'].'%']);
        foreach($state['students'] as $student)$pdo->prepare('DELETE FROM users WHERE id=:id AND email=:email')->execute(['id'=>$student['id'],'email'=>$student['email']]);
        if($state['admin'])$pdo->prepare('DELETE FROM admins WHERE id=:id AND username=:name')->execute(['id'=>$state['admin'],'name'=>$state['prefix']]);
        if(localRowSnapshot($pdo)!==$state['baseline'])throw new RuntimeException('Original row verification failed; cleanup rolled back.');
        $pdo->commit();unlink($file);echo "Browser fixtures removed. All original rows/settings verified unchanged.\n";
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
