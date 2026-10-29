<?php
// Outside web root. CLI only. Never provide password values as command arguments.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors','0');umask(0077);
try {
    if (($argv[1] ?? '') !== '--rotate') throw new RuntimeException();
    $input = $argv[2] ?? '';
    if (!is_file($input) || is_link($input) || (fileperms($input)&0777)!==0600) throw new RuntimeException();
    $secrets=json_decode(file_get_contents($input),true,8,JSON_THROW_ON_ERROR);
    foreach (['admin','user'] as $key) {
        if (!is_string($secrets[$key]??null) || strlen($secrets[$key])<20 || strlen($secrets[$key])>72
            || !preg_match('/[a-z]/',$secrets[$key]) || !preg_match('/[A-Z]/',$secrets[$key])
            || !preg_match('/[0-9]/',$secrets[$key]) || !preg_match('/[^a-zA-Z0-9]/',$secrets[$key])) throw new RuntimeException();
    }
    if (hash_equals($secrets['admin'],$secrets['user'])) throw new RuntimeException();
    $config=require __DIR__.'/config.php';$db=$config['database'];
    $confirm=$argv[3]??'';
    if ($confirm!=='--confirm='.$db['name'] || $db['name']==='bcihs_library' || !preg_match('/^[a-zA-Z0-9_]+$/D',$db['name'])) throw new RuntimeException();
    $lock=fopen(__DIR__.'/activation.lock','c');if(!$lock || !flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException();
    if(is_file(__DIR__.'/activation.json'))throw new RuntimeException();
    $pdo=new PDO('mysql:host='.$db['host'].';port='.$db['port'].';dbname='.$db['name'].';charset=utf8mb4',$db['user'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->beginTransaction();
    $admins=$pdo->query('SELECT id,username,password_hash FROM admins FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $users=$pdo->query('SELECT id,student_id,password_hash FROM users FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    if(count($admins)!==1 || count($users)!==1 || $admins[0]['username']!=='admin' || $users[0]['student_id']!=='user')throw new RuntimeException();
    foreach(['admin'=>$admins[0],'user'=>$users[0]] as $key=>$row)if(password_verify($secrets[$key],$row['password_hash']))throw new RuntimeException();
    $pdo->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash($secrets['admin'],PASSWORD_DEFAULT),$admins[0]['id']]);
    $pdo->prepare('UPDATE users SET password_hash=?,reset_code=NULL,reset_code_expires=NULL WHERE id=?')->execute([password_hash($secrets['user'],PASSWORD_DEFAULT),$users[0]['id']]);
    $pdo->commit();
    $temporary=__DIR__.'/activation-'.bin2hex(random_bytes(8)).'.json';
    $state=json_encode(['credentials_rotated'=>true,'rotated_at'=>gmdate(DATE_ATOM)],JSON_THROW_ON_ERROR);
    if(file_put_contents($temporary,$state)!==strlen($state)||!chmod($temporary,0600)||!rename($temporary,__DIR__.'/activation.json'))throw new RuntimeException();
    foreach(glob(__DIR__.'/sessions/sess_*') as $session)if(is_file($session))unlink($session);
    unset($secrets);
    echo "Both imported accounts rotated. Remove the private plaintext input now. Keep independent access protection enabled.\n";
} catch(Throwable $error) {
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    fwrite(STDERR,"Rotation refused or incomplete; keep access blocked. Inspect private setup and target, never dump secrets.\n");exit(1);
}
