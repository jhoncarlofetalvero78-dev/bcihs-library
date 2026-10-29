<?php
require __DIR__.'/../includes/security.php';
$checks=0;
function verify(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;}
foreach(['09170000000','+63 917 000 0000','639170000000','9170000000','(0917) 000-0000'] as $number) verify(securityPhone($number)==='09170000000','canonical phone');
foreach([null,[],123,'0917<script>','00123','+63 817 000 0000'] as $number) verify(securityPhone($number)===null,'invalid phone');
$_SESSION=[];verify(securitySessionMatches('first hash'),'existing session binds without database write');
verify(securitySessionMatches('first hash'),'same credential remains valid');verify(!securitySessionMatches('changed hash'),'changed credential invalidates bound session');
$dir=sys_get_temp_dir().'/bcihs-budget-test-'.bin2hex(random_bytes(6));mkdir($dir,0700);
try {
    verify(securityBudget('scope','synthetic',1,60,$dir),'first attempt allowed');
    verify(!securityBudget('scope','synthetic',1,60,$dir),'repeat denied persistently');
    verify(securityBudget('other','synthetic',1,60,$dir),'independent scope');
    $file=$dir.'/'.hash('sha256','scope:synthetic').'.json';
    $state=json_decode(file_get_contents($file),true);$state['start']=time()-61;file_put_contents($file,json_encode($state));
    verify(securityBudget('scope','synthetic',1,60,$dir),'elapsed window permits next request');
    file_put_contents($file,'corrupt');$closed=false;try{securityBudget('scope','synthetic',1,60,$dir);}catch(RuntimeException $e){$closed=true;}verify($closed,'corrupt state fails closed');
    $workers=[];
    for($i=0;$i<8;$i++){
        $code='require '.var_export(realpath(__DIR__.'/../includes/security.php'),true).'; echo securityBudget("atomic","test",1,60,'.var_export($dir,true).')?"1":"0";';
        $process=proc_open([PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$process,$pipes];
    }
    $allowed=0;foreach($workers as [$process,$pipes]){$allowed+=(int)stream_get_contents($pipes[1]);fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);verify(proc_close($process)===0 && $error==='','budget worker exits cleanly');}
    verify($allowed===1,'eight simultaneous processes reserve one budget slot');
}finally{foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
echo 'Completed '.$checks.' Phase 8 database-free security checks.'."\n";
