<?php
// No network or database. Child processes share only private synthetic test storage.
if (function_exists('curl_init')) {fwrite(STDERR, "Disable curl_init,curl_exec,curl_multi_init for this suite.\n");exit(1);}
require __DIR__ . '/../includes/sms.php';
if (in_array($argv[1] ?? '', ['--worker','--interrupt','--hold'], true)) {
    $data = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    $result = smsDispatch($data['config'], $data['phone'], $data['message'], static function ($payload) use ($argv): array {
        file_put_contents($argv[3], "mock-attempt\n", FILE_APPEND | LOCK_EX);
        if ($argv[1] === '--interrupt') exit(17);
        if ($argv[1] === '--hold') usleep(15000000);
        return ['http' => 201,'body' => '{"message":{"status":"pending","recipient":"+639171234567","reference_id":"msg_guard-mock"}}'];
    }, $data['context']);
    echo json_encode($result); exit;
}
$checks = 0;
function checkGuard(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    $checks++; echo 'PASS: ' . $label . PHP_EOL;
}
$root = realpath(sys_get_temp_dir()) . '/bcihs-live-guard-' . bin2hex(random_bytes(8));
mkdir($root,0700);
function guardFixture(): array {
    global $root;
    $directory = $root . '/' . bin2hex(random_bytes(8)); mkdir($directory,0700);
    $data = ['config' => ['provider' => 'unisms','mode' => 'live','live_enabled' => true,'key' => 'MOCK_ONLY',
        'unisms_verified_networks' => ['09171234567' => 'globe','09911234567' => 'dito'],
        'live_test' => ['enabled' => true,'issued_at' => time()-1,'expires_at' => time()+120,
            'event_id' => 'account:900','recipient' => '09171234567','directory' => $directory]],
        'phone' => '09171234567','message' => smsEventMessage('account_approved'),
        'context' => ['event' => 'account_approved','event_id' => 'account:900','user_id' => 900]];
    $data['config']['configuration_file'] = $directory . '/settings.php';
    file_put_contents($data['config']['configuration_file'], '<?php return ' . var_export($data['config'], true) . ';');
    chmod($data['config']['configuration_file'],0600);
    return $data;
}
function guardDispatch(array $data, callable $transport): array {
    return smsDispatch($data['config'],$data['phone'],$data['message'],$transport,$data['context']);
}
function guardChild(array $data, string $counter, string $mode = '--worker'): array {
    $file = $data['config']['live_test']['directory'] . '/fixture.json';
    if (!is_file($file)) {file_put_contents($file,json_encode($data,JSON_THROW_ON_ERROR));chmod($file,0600);}
    $process = proc_open([PHP_BINARY,'-d','disable_functions=curl_init,curl_exec,curl_multi_init',__FILE__,$mode,$file,$counter],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start child.');
    fclose($pipes[0]); return [$process,$pipes];
}
function guardFinish(array $child): array {
    [$process,$pipes]=$child;$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    return [$exit,$output,$errors];
}
$never = static function () {throw new RuntimeException('Guard allowed an unauthorized transport.');};
try {
    $data=guardFixture();$calls=0;
    $mock=static function($payload)use(&$calls,$data):array {
        $calls++;
        checkGuard(is_file($data['config']['live_test']['directory'].'/unisms-live-test.claim'),'claim exists before transport');
        $safe = require $data['config']['configuration_file'];
        checkGuard($safe['mode']==='simulate'&&$safe['live_enabled']===false&&$safe['live_test']['enabled']===false,'private live gates are reset before transport begins');
        checkGuard($payload['body']===['recipient'=>'+639171234567','content'=>$data['message'],'sender_id'=>'Unisoft'],'actual final payload is the exact authorized recipient/message/sender');
        return ['http'=>201,'body'=>'{"message":{"status":"pending","recipient":"+639171234567","reference_id":"msg_guard-mock"}}'];
    };
    checkGuard(guardDispatch($data,$mock)['state']==='pending'&&$calls===1,'authorized approval submits once');
    checkGuard(guardDispatch($data,$never)['reason']==='live_test_consumed','repeated request cannot resubmit');
    $claim=$data['config']['live_test']['directory'].'/unisms-live-test.claim';
    checkGuard((fileperms($claim)&0777)===0600,'claim permissions are owner-only');
    $claimText=file_get_contents($claim);
    checkGuard(!str_contains($claimText,'09171234567')&&!str_contains($claimText,'MOCK_ONLY'),'claim contains neither phone nor credentials');
    $changed=$data;$changed['config']['live_test']['event_id']='account:901';$changed['context']['event_id']='account:901';$changed['context']['user_id']=901;
    checkGuard(guardDispatch($changed,$never)['reason']==='live_test_consumed','editing authorization cannot reset the fixed consumed claim');
    $separate=guardFixture();
    $separate['config']['live_test']['event_id']='account:901';
    $separate['context']=['event'=>'account_approved','event_id'=>'account:901','user_id'=>901];
    file_put_contents($separate['config']['configuration_file'],'<?php return '.var_export($separate['config'],true).';');
    $separateCalls=0;
    $separateMock=static function()use(&$separateCalls):array {
        $separateCalls++;
        return ['http'=>201,'body'=>'{"message":{"status":"pending","recipient":"+639171234567","reference_id":"msg_separate-mock"}}'];
    };
    checkGuard(guardDispatch($separate,$separateMock)['state']==='pending' && $separateCalls===1,'separately scoped fresh ledger permits exactly one new approval');
    checkGuard(file_get_contents($claim)===$claimText,'separate acceptance preserves the old consumed claim byte-for-byte');
    checkGuard(guardDispatch($separate,$never)['reason']==='live_test_consumed','fresh independently approved ledger cannot be reused');
    $cases=[
        'wrong event'=>static function(&$d){$d['context']['event']='checkout';},
        'wrong immutable event ID'=>static function(&$d){$d['context']['event_id']='account:901';},
        'wrong user ID'=>static function(&$d){$d['context']['user_id']=901;},
        'missing event context'=>static function(&$d){$d['context']=[];},
        'wrong verified recipient'=>static function(&$d){$d['phone']='09911234567';},
        'wrong provider during authorization'=>static function(&$d){$d['config']['provider']='semaphore';},
        'missing authorization'=>static function(&$d){unset($d['config']['live_test']);},
        'malformed authorization'=>static function(&$d){$d['config']['live_test']='enabled';},
        'disabled authorization'=>static function(&$d){$d['config']['live_test']['enabled']=false;},
        'string enable flag'=>static function(&$d){$d['config']['live_test']['enabled']='true';},
        'expired authorization'=>static function(&$d){$d['config']['live_test']['expires_at']=time()-1;},
        'future issue time'=>static function(&$d){$d['config']['live_test']['issued_at']=time()+30;},
        'excessive lifetime'=>static function(&$d){$d['config']['live_test']['expires_at']=time()+3600;},
        'string timestamp'=>static function(&$d){$d['config']['live_test']['issued_at']=(string)time();},
        'noncanonical configured recipient'=>static function(&$d){$d['config']['live_test']['recipient']='+639171234567';},
        'unsupported network'=>static function(&$d){$d['config']['unisms_verified_networks']['09171234567']='smart';},
        'live disabled'=>static function(&$d){$d['config']['live_enabled']=false;},
        'missing secret'=>static function(&$d){$d['config']['key']='';},
        'additional prefix'=>static function(&$d){$d['message']='TEST: '.$d['message'];},
        'additional suffix'=>static function(&$d){$d['message'].=' Extra.';},
        'more than one segment'=>static function(&$d){$d['message']=str_repeat('A',161);},
        'Unicode'=>static function(&$d){$d['message'].=' é';},
        'emoji'=>static function(&$d){$d['message'].=' 📚';},
        'GSM extension characters'=>static function(&$d){$d['message'].=' []';},
        'control characters'=>static function(&$d){$d['message'].="\n";},
        'link'=>static function(&$d){$d['message'].=' example.com';},
        'missing directory'=>static function(&$d){$d['config']['live_test']['directory'].='/missing';},
        'web-root storage'=>static function(&$d){$d['config']['live_test']['directory']=realpath(__DIR__.'/..');},
        'permissive storage'=>static function(&$d){chmod($d['config']['live_test']['directory'],0770);},
        'read-only storage'=>static function(&$d){chmod($d['config']['live_test']['directory'],0500);},
    ];
    foreach($cases as $label=>$change){$d=guardFixture();$dir=$d['config']['live_test']['directory'];$change($d);
        $r=guardDispatch($d,$never);checkGuard($r['state']==='skipped','no transport: '.$label);
        checkGuard(!file_exists($dir.'/unisms-live-test.claim'),'invalid input does not consume the valid slot: '.$label);chmod($dir,0700);
    }
    $d=guardFixture();$d['config']['provider']='simulation';
    checkGuard(guardDispatch($d,$never)['state']==='simulated','simulation stays offline even with an authorization');
    $d=guardFixture();$d['config']['mode']='simulate';
    checkGuard(guardDispatch($d,$never)['state']==='simulated','UniSMS simulation never reserves an attempt');
    $d=guardFixture();file_put_contents($d['config']['live_test']['directory'].'/unisms-live-test.claim','');
    checkGuard(guardDispatch($d,$never)['reason']==='live_test_consumed','empty claim from a crash is consumed');
    $d=guardFixture();symlink($root,$d['config']['live_test']['directory'].'/unisms-live-test.claim');
    checkGuard(guardDispatch($d,$never)['reason']==='live_test_consumed','claim symlink cannot bypass exclusive creation');
    foreach(['timeout','malformed response','exception'] as $failure){$d=guardFixture();$attempts=0;
        $r=guardDispatch($d,static function()use($failure,&$attempts){$attempts++;
            if($failure==='exception')throw new RuntimeException('Mock failure');
            return $failure==='timeout'?['error'=>true]:['http'=>201,'body'=>'not JSON'];});
        checkGuard($r['state']==='unknown'&&$attempts===1,'uncertain attempt: '.$failure);
        $safe=require $d['config']['configuration_file'];
        checkGuard($safe['mode']==='simulate'&&$safe['live_enabled']===false&&$safe['live_test']['enabled']===false,'restoration survives '.$failure);
        checkGuard(guardDispatch($d,$never)['reason']==='live_test_consumed','no reset/retry after '.$failure);
    }
    $restoreFailures=[
        'missing reset path'=>static function(&$d){unset($d['config']['configuration_file']);},
        'missing configuration file'=>static function(&$d){$d['config']['configuration_file'].='.missing';},
        'permissive configuration file'=>static function(&$d){chmod($d['config']['configuration_file'],0644);},
        'invalid private PHP syntax'=>static function(&$d){file_put_contents($d['config']['configuration_file'],'<?php invalid [');},
        'private scope changed concurrently'=>static function(&$d){$c=$d['config'];$c['live_test']['event_id']='account:901';file_put_contents($d['config']['configuration_file'],'<?php return '.var_export($c,true).';');},
        'operator cancelled before submission'=>static function(&$d){$c=$d['config'];$c['live_enabled']=false;file_put_contents($d['config']['configuration_file'],'<?php return '.var_export($c,true).';');},
    ];
    foreach($restoreFailures as $label=>$inject){$d=guardFixture();$inject($d);$r=guardDispatch($d,$never);
        checkGuard($r['reason']==='live_test_restore_failed','no transport after reset failure: '.$label);
        checkGuard(is_file($d['config']['live_test']['directory'].'/unisms-live-test.claim'),'reset failure retains consumed claim: '.$label);
    }
    $d=guardFixture();$target=guardFixture();$d['config']['configuration_file']=$target['config']['configuration_file'];
    file_put_contents($d['config']['configuration_file'],'<?php return '.var_export($d['config'],true).';');
    chmod(dirname($d['config']['configuration_file']),0500);
    $r=guardDispatch($d,$never);chmod(dirname($d['config']['configuration_file']),0700);
    checkGuard($r['reason']==='live_test_restore_failed','temporary-write failure blocks request and retains claim');
    foreach(['BCIHS_SMS_MODE=live','BCIHS_SMS_LIVE_ENABLED=1'] as $override){
        $name=explode('=',$override)[0];$saved=getenv($name);putenv($override);$d=guardFixture();
        $r=guardDispatch($d,$never);putenv($saved===false?$name:$name.'='.$saved);
        checkGuard($r['reason']==='live_test_restore_failed','environment-forced live gate cannot bypass safe reset');
    }
    $d=guardFixture();$counter=$root.'/parallel-attempts';$children=[];
    for($i=0;$i<10;$i++)$children[]=guardChild($d,$counter);
    $accepted=0;$blocked=0;
    foreach($children as $child){[$exit,$out,$error]=guardFinish($child);checkGuard($exit===0&&$error==='','parallel PHP request exited safely');
        $r=json_decode($out,true);if(($r['state']??'')==='pending')$accepted++;if(($r['reason']??'')==='live_test_consumed')$blocked++;}
    checkGuard($accepted===1&&$blocked===9&&count(file($counter))===1,'ten simultaneous processes permit exactly one mock submission');
    [$exit,$out,$error]=guardFinish(guardChild($d,$counter));
    checkGuard($exit===0&&(json_decode($out,true)['reason']??'')==='live_test_consumed'&&count(file($counter))===1,'fresh PHP process/restart cannot reuse consumed slot');
    $d=guardFixture();$counter=$root.'/interrupted-attempt';
    [$exit]=guardFinish(guardChild($d,$counter,'--interrupt'));
    checkGuard($exit===17&&count(file($counter))===1,'process exits during transport after persistent claim');
    [$exit,$out]=guardFinish(guardChild($d,$counter));
    checkGuard($exit===0&&(json_decode($out,true)['reason']??'')==='live_test_consumed'&&count(file($counter))===1,'restart after interruption cannot resubmit');
    $safe=require $d['config']['configuration_file'];
    checkGuard($safe['mode']==='simulate'&&$safe['live_enabled']===false,'PHP exit during transport cannot leave private live mode on');
    $d=guardFixture();$counter=$root.'/killed-attempt';$child=guardChild($d,$counter,'--hold');
    for($i=0;$i<100&&!is_file($counter);$i++)usleep(20000);
    checkGuard(is_file($counter),'hard-termination worker reached mocked transport');
    proc_terminate($child[0],9);guardFinish($child);
    $safe=require $d['config']['configuration_file'];
    checkGuard($safe['mode']==='simulate'&&$safe['live_enabled']===false&&$safe['live_test']['enabled']===false,'SIGKILL during transport leaves configuration already safe');
    [$exit,$out]=guardFinish(guardChild($d,$counter));
    checkGuard($exit===0&&(json_decode($out,true)['reason']??'')==='live_test_consumed'&&count(file($counter))===1,'fresh process after SIGKILL cannot submit again');
    $source=file_get_contents(__DIR__.'/../includes/sms.php');
    checkGuard(str_contains($source,"['event' => \$event, 'event_id' => \$eventKey, 'user_id' => \$userId]"),'actual logging helper supplies immutable event and selected user context');
    $core=file_get_contents(__DIR__.'/../includes/sms-core.php');
    $boundary=substr($core,strpos($core,'function smsUniDispatch('));
    checkGuard(strpos($boundary,'smsReserveLiveTest(')<strpos($boundary,'$response = $transport('),'guard is enforced before the central UniSMS transport callback');
    checkGuard(strpos($boundary,'smsRestoreLiveTest(')<strpos($boundary,'$response = $transport('),'safe reset precedes the actual transport callback');
    echo "Completed {$checks} one-SMS guard checks. Mock transports only; zero database/provider connections.\n";
} finally {
    foreach(glob($root.'/*') as $entry){
        if(is_dir($entry)&&!is_link($entry)){chmod($entry,0700);foreach(glob($entry.'/*') as $file)unlink($file);rmdir($entry);}
        else unlink($entry);
    }
    rmdir($root);
}
