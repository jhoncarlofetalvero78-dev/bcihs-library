<?php
require_once __DIR__.'/../scripts/local-backup.php';
require_once __DIR__.'/../includes/pickup-qr.php';
$mode=$argv[1]??'';$directory=$argv[2]??'';
if(!in_array($mode,['setup','cleanup'],true) || !is_dir($directory)
    || str_starts_with(realpath($directory).'/',realpath(dirname(__DIR__,2)).'/')) {
    fwrite(STDERR,"Usage: php tests/phase3b-browser-fixtures.php setup|cleanup PRIVATE_DIRECTORY\n");exit(1);
}
$file=realpath($directory).'/phase3b-browser-fixtures.json';
if($mode==='setup') {
    if(is_file($file))throw new RuntimeException('Clean existing UI fixtures first.');
    $backup=backupLocalDatabase($pdo,$directory,'/Applications/MAMP/Library/bin/mysql80/bin/mysqldump','phase3b-ui');
    $state=['prefix'=>'PHASE3B-UI-'.bin2hex(random_bytes(4)),'password'=>bin2hex(random_bytes(16)),
        'baseline'=>localRowSnapshot($pdo),'student'=>null,'admin'=>null,'book'=>null,'request'=>null];
    $save=static function()use(&$state,$file){file_put_contents($file,json_encode($state,JSON_PRETTY_PRINT));chmod($file,0600);};$save();
    $pdo->prepare('INSERT INTO users(full_name,student_id,year_section,password_hash,role,account_status)VALUES(:name,:identifier,"Grade 10 — Test Section",:hash,"student","accepted")')
        ->execute(['name'=>$state['prefix'].' Student','identifier'=>$state['prefix'],'hash'=>password_hash($state['password'],PASSWORD_DEFAULT)]);
    $state['student']=(int)$pdo->lastInsertId();$save();
    $pdo->prepare('INSERT INTO admins(username,password_hash)VALUES(:name,:hash)')->execute(['name'=>$state['prefix'].'-Admin','hash'=>password_hash($state['password'],PASSWORD_DEFAULT)]);
    $state['admin']=(int)$pdo->lastInsertId();$save();
    $pdo->prepare('INSERT INTO books(title,author,total_copies,borrowed_copies,call_number)VALUES(:title,"Disposable Test Author",1,0,"500 P3B")')->execute(['title'=>$state['prefix'].' Test Book']);
    $state['book']=(int)$pdo->lastInsertId();$save();
    $state['token']=bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO borrow_requests(user_id,book_id,status,approved_at,pickup_token)VALUES(:user,:book,"awaiting_pickup",NOW(),:token)')
        ->execute(['user'=>$state['student'],'book'=>$state['book'],'token'=>$state['token']]);
    $state['request']=(int)$pdo->lastInsertId();$save();
    echo 'Disposable pickup fixtures ready. Private manifest: '.$file.PHP_EOL.'Backup: '.$backup.PHP_EOL;
} else {
    if(!is_file($file))throw new RuntimeException('No UI fixture manifest.');
    $state=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    // Manual testing can add legitimate records after fixture setup. Preserve
    // today's non-fixture rows instead of restoring the old setup baseline.
    $backup=backupLocalDatabase($pdo,$directory,'/Applications/MAMP/Library/bin/mysql80/bin/mysqldump','phase3b-cleanup-current');
    $before=localRowSnapshot($pdo);
    $pdo->beginTransaction();
    try {
        if(localRowSnapshot($pdo)!==$before)throw new RuntimeException('Data changed after backup; retry after local activity stops.');
        $q=$pdo->prepare('SELECT student_id FROM users WHERE id=:id FOR UPDATE');$q->execute(['id'=>$state['student']]);if($q->fetchColumn()!==$state['prefix'])throw new RuntimeException('Student fixture mismatch.');
        $q=$pdo->prepare('SELECT title FROM books WHERE id=:id FOR UPDATE');$q->execute(['id'=>$state['book']]);if($q->fetchColumn()!==$state['prefix'].' Test Book')throw new RuntimeException('Book fixture mismatch.');
        $q=$pdo->prepare('SELECT username FROM admins WHERE id=:id FOR UPDATE');$q->execute(['id'=>$state['admin']]);if($q->fetchColumn()!==$state['prefix'].'-Admin')throw new RuntimeException('Admin fixture mismatch.');
        $q=$pdo->prepare('SELECT user_id,book_id,pickup_token,status FROM borrow_requests WHERE id=:id FOR UPDATE');$q->execute(['id'=>$state['request']]);$request=$q->fetch();
        if(!$request || (int)$request['user_id']!==$state['student'] || (int)$request['book_id']!==$state['book'] || !hash_equals($state['token'],$request['pickup_token']))throw new RuntimeException('Request fixture mismatch.');
        if(!in_array($request['status'],['awaiting_pickup','returned','rejected'],true))throw new RuntimeException('Return the disposable checked-out book through the existing workflow before cleanup.');
        $q=$pdo->prepare('SELECT borrowed_copies FROM books WHERE id=:id');$q->execute(['id'=>$state['book']]);if((int)$q->fetchColumn()!==0)throw new RuntimeException('Disposable book still has borrowed copies.');
        foreach(['borrow_requests','reservations'] as $table){
            $q=$pdo->prepare('SELECT id FROM '.$table.' WHERE user_id=:user OR book_id=:book');$q->execute(['user'=>$state['student'],'book'=>$state['book']]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
            if($ids!==($table==='borrow_requests'?[$state['request']]:[]))throw new RuntimeException('Additional linked records require review; nothing deleted.');
        }
        foreach(['attendance'=>'user_id','sms_logs'=>'user_id','announcements'=>'posted_by'] as $table=>$column){
            $q=$pdo->prepare('SELECT COUNT(*) FROM '.$table.' WHERE '.$column.'=:id');$q->execute(['id'=>$state['student']]);if((int)$q->fetchColumn())throw new RuntimeException('Additional user history requires review; nothing deleted.');
        }
        $excluded=['users'=>$state['student'],'admins'=>$state['admin'],'books'=>$state['book'],'borrow_requests'=>$state['request']];$expected=[];
        foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){
            $rows=$pdo->query('SELECT * FROM `'.str_replace('`','``',$table).'` ORDER BY id')->fetchAll();
            if(isset($excluded[$table]))$rows=array_values(array_filter($rows,static fn($row)=>(int)$row['id']!==$excluded[$table]));
            $expected[$table]=['rows'=>count($rows),'hash'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
        }
        $pdo->prepare('DELETE FROM borrow_requests WHERE id=:id AND user_id=:user AND book_id=:book')->execute(['id'=>$state['request'],'user'=>$state['student'],'book'=>$state['book']]);
        $pdo->prepare('DELETE FROM books WHERE id=:id')->execute(['id'=>$state['book']]);
        $pdo->prepare('DELETE FROM users WHERE id=:id AND student_id=:identifier')->execute(['id'=>$state['student'],'identifier'=>$state['prefix']]);
        $pdo->prepare('DELETE FROM admins WHERE id=:id AND username=:name')->execute(['id'=>$state['admin'],'name'=>$state['prefix'].'-Admin']);
        if(localRowSnapshot($pdo)!==$expected)throw new RuntimeException('Non-fixture verification failed; cleanup rolled back.');
        $pdo->commit();
        $report=['removed'=>$excluded,'request_status'=>$request['status'],'backup'=>$backup,'preserved'=>$expected];
        $reportFile=realpath($directory).'/phase3b-cleanup-verification.json';file_put_contents($reportFile,json_encode($report,JSON_PRETTY_PRINT));chmod($reportFile,0600);
        unlink($file);
        $access=realpath($directory).'/phase3b-phone-test-access.txt';if(is_file($access))unlink($access);
        echo "Exactly four declared fixtures removed. Every current non-fixture record and setting preserved.\nPrivate backup: ".$backup.PHP_EOL;
    } catch(Throwable $error){$pdo->rollBack();throw $error;}
}
