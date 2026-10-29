<?php
require_once __DIR__ . '/../scripts/local-backup.php';
require_once __DIR__ . '/../includes/attendance.php';
$mode=$argv[1]??'';$directory=$argv[2]??'';
if(!in_array($mode,['setup','cleanup'],true) || !is_dir($directory)
    || str_starts_with(realpath($directory).'/',realpath(dirname(__DIR__,2)).'/')) {
    fwrite(STDERR,"Usage: php tests/phase5-browser-fixtures.php setup|cleanup PRIVATE_DIRECTORY\n");exit(1);
}
$file=realpath($directory).'/phase5-browser-fixtures.json';
if($mode==='setup') {
    if(is_file($file))throw new RuntimeException('Clean existing fixtures first.');
    if(attendanceCounts($pdo,date('Y-m-d'))['visits'] || attendanceOccupancy($pdo))throw new RuntimeException('Existing visits found; do not change policy.');
    $backup=backupLocalDatabase($pdo,$directory,'/Applications/MAMP/Library/bin/mysql80/bin/mysqldump','phase5-ui');
    $state=['prefix'=>'PHASE5-UI-'.bin2hex(random_bytes(4)),'password'=>bin2hex(random_bytes(16)),
        'baseline'=>localRowSnapshot($pdo),'settings'=>attendanceSettings($pdo),'students'=>[],'admin'=>null];
    $save=static function()use(&$state,$file){file_put_contents($file,json_encode($state,JSON_PRETTY_PRINT));chmod($file,0600);};$save();
    foreach(['Student','Other'] as $label) {
        $email=strtolower($state['prefix'].'-'.$label).'@example.invalid';
        $pdo->prepare('INSERT INTO users(full_name,email,student_id,year_section,password_hash,role,account_status)VALUES(:name,:email,:identifier,"Grade 10 / Test Section",:hash,"student","accepted")')
            ->execute(['name'=>$state['prefix'].' '.$label,'email'=>$email,'identifier'=>$state['prefix'].'-'.$label,'hash'=>password_hash($state['password'],PASSWORD_DEFAULT)]);
        $state['students'][]=['id'=>(int)$pdo->lastInsertId(),'email'=>$email];$save();
    }
    $pdo->prepare('INSERT INTO admins(username,password_hash)VALUES(:name,:hash)')->execute(['name'=>$state['prefix'],'hash'=>password_hash($state['password'],PASSWORD_DEFAULT)]);
    $state['admin']=(int)$pdo->lastInsertId();$save();
    echo 'Disposable UI fixtures ready. Private manifest: '.$file.PHP_EOL.'Backup: '.$backup.PHP_EOL;
} else {
    if(!is_file($file))throw new RuntimeException('No fixture manifest.');
    $state=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    $pdo->beginTransaction();
    try {
        foreach($state['students'] as $student) {
            $q=$pdo->prepare('SELECT email FROM users WHERE id=:id FOR UPDATE');$q->execute(['id'=>$student['id']]);
            if($q->fetchColumn()!==$student['email'])throw new RuntimeException('Fixture identity mismatch.');
        }
        $pdo->prepare('DELETE FROM attendance WHERE student_name LIKE :prefix')->execute(['prefix'=>$state['prefix'].'%']);
        foreach($state['students'] as $student)$pdo->prepare('DELETE FROM users WHERE id=:id AND email=:email')->execute(['id'=>$student['id'],'email'=>$student['email']]);
        if($state['admin'])$pdo->prepare('DELETE FROM admins WHERE id=:id AND username=:name')->execute(['id'=>$state['admin'],'name'=>$state['prefix']]);
        $pdo->prepare('UPDATE attendance_settings SET daily_visit_limit=:daily,occupancy_limit=:occupancy,track_departures=:tracking WHERE id=1')
            ->execute(['daily'=>$state['settings']['daily_visit_limit'],'occupancy'=>$state['settings']['occupancy_limit'],'tracking'=>$state['settings']['track_departures']]);
        if(localRowSnapshot($pdo)!==$state['baseline'])throw new RuntimeException('Original row verification failed; cleanup rolled back.');
        $pdo->commit();unlink($file);echo "Browser fixtures removed. All original rows/settings verified unchanged.\n";
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
