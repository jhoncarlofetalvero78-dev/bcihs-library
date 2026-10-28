<?php
// Explicit local-only disposable QA. Never imports or changes rows in bcihs_library.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--run') exit("Usage: php scripts/phase8-isolated-qa.php --run\n");
require_once __DIR__ . '/../includes/db_config.php';
if (!in_array(DB_HOST, ['127.0.0.1','localhost','::1'], true) || DB_NAME !== 'bcihs_library') exit("Requires the local source schema.\n");
$php = PHP_BINARY;
$name = 'bcihs_phase8_test_' . bin2hex(random_bytes(6));
$directory = sys_get_temp_dir() . '/' . $name;
$server = null; $created = false; $exit = 1;
$dsn = 'mysql:host='.DB_HOST.(DB_PORT !== '' ? ';port='.DB_PORT : '').';charset=utf8mb4';
$source = new PDO($dsn.';dbname='.DB_NAME, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
try {
    // CREATE without IF NOT EXISTS guarantees this run owns the empty disposable database.
    $source->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true;
    $test = new PDO($dsn.';dbname='.$name, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $test->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($source->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $ddl = $source->query('SHOW CREATE TABLE `'.str_replace('`','``',$table).'`')->fetch(PDO::FETCH_NUM)[1];
        $test->exec($ddl);
    }
    $test->exec('SET FOREIGN_KEY_CHECKS=1');
    if ((int)$test->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) throw new RuntimeException('Isolation failed.');
    mkdir($directory, 0700);
    $root = dirname(__DIR__);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $relative = substr($file->getPathname(), strlen($root)+1);
        if (preg_match('#^(\.git/|uploads/|tests/|scripts/)#', $relative)
            || preg_match('#(^|/)(db-local|sms-local|pickup-local|semaphore-api-key)\.php$#', $relative)
            || $relative === 'includes/report-local.json' || str_contains($relative, '.sms-local-safe-')) continue;
        $destination = $directory.'/'.$relative;
        if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0700, true);
        copy($file->getPathname(), $destination);
    }
    $recoveryFile=$directory.'/backend/forgot-password-request.php';
    $recoverySource=file_get_contents($recoveryFile);
    $recoverySource=str_replace("str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)", "'123456'", $recoverySource, $replaced);
    if ($replaced!==1) throw new RuntimeException('Recovery fixture injection did not match.');
    file_put_contents($recoveryFile,$recoverySource);
    file_put_contents($directory.'/includes/sms-local.php', "<?php return ['mode'=>'simulate','provider'=>'simulation','live_enabled'=>false];\n");
    mkdir($directory.'/sessions',0700);
    file_put_contents($directory.'/router.php', <<<'ROUTER'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__isolation') {
    require __DIR__.'/includes/db_config.php';
    header('Content-Type: application/json');echo json_encode(['database'=>DB_NAME,'sms'=>'disabled']);return;
}
if (preg_match('#^/(includes|scripts|tests|sql)/|/\.|\.sql$#i', $path)) { http_response_code(403); return; }
return false;
ROUTER);
    $env = getenv();
    $env['BCIHS_DB_HOST']=DB_HOST; $env['BCIHS_DB_PORT']=DB_PORT; $env['BCIHS_DB_USER']=DB_USER;
    $env['BCIHS_DB_PASS']=DB_PASS; $env['BCIHS_DB_NAME']=$name;
    $env['BCIHS_SMS_MODE']='simulate'; $env['BCIHS_SMS_LIVE_ENABLED']='0'; $env['BCIHS_SMS_PROVIDER']='simulation';
    $env['BCIHS_PHASE8_PASSWORD']='Phase8-Only!'.bin2hex(random_bytes(12));
    $env['BCIHS_PUBLIC_BASE_URL']='http://127.0.0.1:8898';
    $env['BCIHS_PHASE8_ISOLATED']='1'; $env['PHP_CLI_SERVER_WORKERS']='4';
    $server = proc_open([$php,'-d','disable_functions=curl_exec','-d','session.save_path='.$directory.'/sessions','-S','127.0.0.1:8898','-t',$directory,$directory.'/router.php'],
        [0=>['pipe','r'],1=>['file',$directory.'/server.log','a'],2=>['file',$directory.'/server.log','a']],$pipes,null,$env);
    if (!is_resource($server)) throw new RuntimeException('Cannot start isolated server.');
    fclose($pipes[0]);
    $ready = false;
    for ($i=0;$i<30;$i++) {
        $probe = @file_get_contents('http://127.0.0.1:8898/__isolation');
        if ($probe && (json_decode($probe,true)['database']??'') === $name) { $ready=true; break; }
        usleep(100000);
    }
    if (!$ready) throw new RuntimeException('No matching isolated server; no HTTP tests run.');
    $process = proc_open([$php,$root.'/tests/phase8-workflows.php','http://127.0.0.1:8898'],
        [0=>['pipe','r'],1=>STDOUT,2=>STDERR],$testPipes,null,$env);
    fclose($testPipes[0]); $exit=proc_close($process);
    if ($exit===0 && ($argv[2]??'')==='--browser') {
        $access=sys_get_temp_dir().'/'.$name.'-browser-access.json';
        file_put_contents($access,json_encode(['base'=>'http://127.0.0.1:8898','identifier'=>'phase8-admin','password'=>$env['BCIHS_PHASE8_PASSWORD']]));chmod($access,0600);
        echo "Private disposable access file: ".$access."\n";
        echo "Isolated browser environment ready. Type finish to remove the owned test database and fixtures.\n";
        fgets(STDIN);
        unlink($access);
    }
} finally {
    if (is_resource($server)) {
        // Stop worker children as well as their supervisor, before deleting the owned DB.
        $pid = proc_get_status($server)['pid'];
        exec('pkill -TERM -P '.(int)$pid);
        proc_terminate($server); proc_close($server);
    }
    $budgetDirectory=sys_get_temp_dir().'/bcihs-security-'.substr(hash('sha256',$directory),0,16);
    if (is_dir($budgetDirectory)) { foreach(glob($budgetDirectory.'/*.json') as $file) unlink($file); rmdir($budgetDirectory); }
    if ($created) $source->exec('DROP DATABASE `'.$name.'`');
    if (is_dir($directory)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($directory);
    }
}
exit($exit);
