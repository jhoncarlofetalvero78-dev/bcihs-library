<?php
if(PHP_SAPI!=='cli')exit;
ini_set('display_errors','0');date_default_timezone_set('Asia/Manila');umask(0077);
require dirname(__DIR__).'/includes/db_config.php';
$release=$argv[1]??'';$server=null;$created=false;$userCreated=false;$checks=0;$temp=sys_get_temp_dir().'/bcihs-hosting-'.bin2hex(random_bytes(6));
function hostingCheck(bool $value,string $label):void{global $checks;if(!$value)throw new RuntimeException($label);$checks++;}
function hostingHttp(string $endpoint,?array $input=null,?array $session=null,bool $form=false):array{
 $url='http://127.0.0.1:8901/'.$endpoint;$headers=[];$cookie=null;
 $curl=curl_init($url);$options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false];
 if($session){$headers[]='Cookie: BCIHS_HOSTED_SESSION='.$session['cookie'];if(isset($session['csrf']))$headers[]='X-CSRF-Token: '.$session['csrf'];}
 if($input!==null){$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=$form?http_build_query($input):json_encode($input);if(!$form)$headers[]='Content-Type: application/json';}
 $options[CURLOPT_HTTPHEADER]=$headers;
 $options[CURLOPT_HEADERFUNCTION]=static function($curl,$line)use(&$cookie){if(preg_match('/^Set-Cookie: BCIHS_HOSTED_SESSION=([^;]+);/i',$line,$m))$cookie=$m[1];return strlen($line);};
 curl_setopt_array($curl,$options);$body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
 if(!is_string($body))throw new RuntimeException('Local package HTTP request');
 return [$status,json_decode($body,true),$body,$cookie];
}
function hostedPost(string $endpoint,array $input,array $session,int $expected=200):array{
 [$code,$data]=hostingHttp('backend/'.$endpoint.'.php',$input,$session);hostingCheck($code===$expected,'HTTP '.$endpoint.' expected '.$expected);return $data??[];
}
try{
 hostingCheck(DB_NAME==='bcihs_library'&&in_array(DB_HOST,['127.0.0.1','localhost'],true),'Local-only source connection');
 $record=json_decode(file_get_contents($release.'/database-verification.json'),true);
 hostingCheck(($record['source_database']??'')==='bcihs_demo_20261028_236ee935','Final refined export source');
 hostingCheck(hash_file('sha256',$release.'/bcihs-refined.sql')===$record['sql_sha256'],'Export checksum');
 $name='bcihs_hostverify_'.bin2hex(random_bytes(6));$username='bcihsqa_'.bin2hex(random_bytes(5));$dbSecret=bin2hex(random_bytes(24));
 $dsn='mysql:host='.DB_HOST.';port='.DB_PORT.';charset=utf8mb4';
 $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
 $master=new PDO($dsn,DB_USER,DB_PASS,$options);
 $master->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
 $db=new PDO($dsn.';dbname='.$name,DB_USER,DB_PASS,$options);$db->exec(file_get_contents($release.'/bcihs-refined.sql'));$db->exec("SET time_zone='+08:00'");
 foreach($record['tables'] as $table=>$count)hostingCheck((int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()===$count,'Imported '.$table.' count');
 $oldHashes=[$db->query('SELECT password_hash FROM admins')->fetchColumn(),$db->query('SELECT password_hash FROM users')->fetchColumn()];
 $master->exec('CREATE USER '.$master->quote($username).'@\'localhost\' IDENTIFIED BY '.$master->quote($dbSecret));$userCreated=true;
 $master->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON `'.$name.'`.* TO '.$master->quote($username).'@\'localhost\'');
 mkdir($temp,0700);
 $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($release.'/application',FilesystemIterator::SKIP_DOTS));
 foreach($iterator as $file){if(!$file->isFile())continue;$relative=substr($file->getPathname(),strlen($release.'/application/'));$to=$temp.'/'.$relative;if(!is_dir(dirname($to)))mkdir(dirname($to),0700,true);copy($file->getPathname(),$to);}
 mkdir($temp.'/bcihs-private/sessions',0700);mkdir($temp.'/bcihs-private/rate-limits',0700);
 file_put_contents($temp.'/bcihs-private/config.php','<?php return '.var_export(['database'=>['host'=>DB_HOST,'port'=>DB_PORT,'name'=>$name,'user'=>$username,'password'=>$dbSecret],'public_base_url'=>'https://staging.example.invalid'],true).';');
 $probe='require '.var_export($temp.'/public_html/includes/db_config.php',true).'; echo "UNSAFE";';
 $process=proc_open([PHP_BINARY,'-r',$probe],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$result=stream_get_contents($pipes[1]);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
 hostingCheck(!str_contains($result,'UNSAFE')&&str_contains($result,'setup is incomplete'),'Unrotated imports cannot open database-backed pages');
 $passwords=['admin'=>'A9!'.bin2hex(random_bytes(18)),'user'=>'B8!'.bin2hex(random_bytes(18))];$input=$temp.'/rotation-input.json';file_put_contents($input,json_encode($passwords));chmod($input,0600);
 $process=proc_open([PHP_BINARY,$temp.'/bcihs-private/rotate-credentials.php','--rotate',$input,'--confirm='.$name],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 fclose($pipes[0]);$response=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
 hostingCheck(proc_close($process)===0&&$errors===''&&str_contains($response,'Both imported accounts rotated'),'Credential rotation in disposable imported database');unlink($input);
 foreach(['admin'=>'admins','user'=>'users'] as $key=>$table){$hash=$db->query('SELECT password_hash FROM '.$table)->fetchColumn();hostingCheck(password_verify($passwords[$key],$hash)&&!in_array($hash,$oldHashes,true),'New unique hashed '.$key.' password');}
 hostingCheck(json_decode(file_get_contents($temp.'/bcihs-private/activation.json'),true)['credentials_rotated']===true,'Private activation marker created after rotation');
 // Simulates HTTPS server metadata for local API testing only, not TLS/Hostinger acceptance.
 file_put_contents($temp.'/router.php','<?php $_SERVER["HTTPS"]="on"; $path=rawurldecode(parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH)); if(preg_match("#^/(includes|vendor|bcihs-private)/|/\\\\.#",$path)){http_response_code(403);exit;} return false;');
 $env=getenv();foreach(['BCIHS_DB_HOST','BCIHS_DB_PORT','BCIHS_DB_NAME','BCIHS_DB_USER','BCIHS_DB_PASS','SEMAPHORE_API_KEY'] as $key)unset($env[$key]);
 // Even hostile inherited mode flags cannot turn this staging bundle live.
 $env['BCIHS_SMS_PROVIDER']='unisms';$env['BCIHS_SMS_MODE']='live';$env['BCIHS_SMS_LIVE_ENABLED']='1';
 $server=proc_open([PHP_BINARY,'-d','disable_functions=curl_exec','-d','display_errors=0','-S','127.0.0.1:8901','-t',$temp.'/public_html',$temp.'/router.php'],[0=>['pipe','r'],1=>['file',$temp.'/server.log','a'],2=>['file',$temp.'/server.log','a']],$pipes,null,$env);fclose($pipes[0]);
 $ready=false;for($i=0;$i<30;$i++){if(@file_get_contents('http://127.0.0.1:8901/index.html')!==false){$ready=true;break;}usleep(100000);}hostingCheck($ready,'Owned loopback package probe ready');
 foreach(['admin','user'] as $key){[$code,,,$cookie]=hostingHttp('backend/login-process.php',['identifier'=>$key,'password'=>$passwords[$key]],null,true);hostingCheck($code===302&&is_string($cookie),'Imported '.$key.' authenticates after rotation');[$code,$catalog]=hostingHttp('backend/books-data.php',null,['cookie'=>$cookie]);hostingCheck($code===200&&count($catalog['books'])===10,'Imported catalog for '.$key);$sessions[$key]=['cookie'=>$cookie,'csrf'=>$catalog['csrfToken']];}
 $staff=$sessions['admin'];$student=$sessions['user'];
 [$code,$settings]=hostingHttp('backend/sms-settings.php',null,$staff);hostingCheck($code===200&&$settings['delivery_mode']==='Disabled'&&!$settings['permission']['allowed']&&$settings['provider']==='simulation','Imported SMS OFF and forced simulation despite inherited live flags');
 [$code,$notices]=hostingHttp('backend/announcements-data.php?latest=2');hostingCheck($code===200&&count($notices['announcements'])===2&&$notices['announcements'][0]['title']==='Welcome to the BCIHS Library Portal','Two latest refined publications');
 [$code,$all]=hostingHttp('backend/announcements-data.php');hostingCheck($code===200&&count($all['announcements'])===4,'All four refined announcements');
 $manifest=json_decode(file_get_contents($release.'/application-manifest.json'),true);
 foreach($manifest['refined_images'] as $path){[$code,,$image]=hostingHttp($path);hostingCheck($code===200&&hash('sha256',$image)===hash_file('sha256',$temp.'/public_html/'.$path),'Imported image path/hash');}
 foreach(['includes/db_config.php','bcihs-private/config.php'] as $path){[$code]=hostingHttp($path);hostingCheck($code===403,'Local router denies internal resource');}
 [$code,$policy]=hostingHttp('backend/borrowing-policy.php',null,$staff);hostingCheck($code===200&&$policy['days']===14,'Default fourteen-day policy');
 foreach([['default',null,1],['days',3,2],['date',(new DateTimeImmutable('+5 days',new DateTimeZone('Asia/Manila')))->format('Y-m-d'),3]] as [$mode,$value,$book]){
  $request=hostedPost('borrow-request-process',['book_id'=>$book],$student)['id'];
  hostedPost('borrow-request-action',['id'=>$request,'action'=>'approve'],$staff);
  [$code,$qr]=hostingHttp('backend/pickup-qr-data.php',['id'=>$request],$student);
  hostingCheck($code===200&&str_starts_with($qr['url']??'','https://staging.example.invalid/'),'Configurable hosted HTTPS pickup URL');
  parse_str(parse_url($qr['url'],PHP_URL_QUERY),$qrQuery);$qr['token']=$qrQuery['token'];
  [$code,$verification]=hostingHttp('backend/pickup-verification-data.php?token='.$qr['token'],null,$staff);
  hostingCheck($code===200&&$verification['eligible'],'Staff verification before handover');
  [$code]=hostingHttp('backend/pickup-verification-data.php?token='.$qr['token'],null,$student);
  hostingCheck($code===403,'Borrower cannot access staff verification details');
  $preview=['source'=>'borrow','id'=>$request,'due_mode'=>$mode];if($mode==='days')$preview['days']=$value;if($mode==='date')$preview['due_date']=$value;
  $due=hostedPost('checkout-due-preview',$preview,$staff);
  hostedPost('borrow-request-action',['id'=>$request,'action'=>'checkout','pickup_token'=>$qr['token'],'due_preview_token'=>$due['due_preview_token']],$staff);
  $row=$db->query('SELECT * FROM borrow_requests WHERE id='.(int)$request)->fetch();hostingCheck($row['due_date']===$due['due_at']&&$row['status']==='checked_out','Saved '.$mode.' checkout deadline');
  hostedPost('borrow-request-action',['id'=>$request,'action'=>'checkout','pickup_token'=>$qr['token']],$staff,409);
 }
 [$code,$report]=hostingHttp('backend/statistical-report.php?month='.date('Y-m').'&months=3&school_year=2026-2027',null,$staff);hostingCheck($code===200&&$report['total']===3,'Imported report reflects only disposable verification checkouts');
 [$code,,$xlsx]=hostingHttp('backend/statistical-report.php?month='.date('Y-m').'&months=3&school_year=2026-2027&format=xlsx',null,$staff);
 $file=$temp.'/check.xlsx';file_put_contents($file,$xlsx);$zip=new ZipArchive();$open=$zip->open($file);hostingCheck($code===200&&$open===true&&$zip->locateName('xl/charts/chart1.xml')!==false,'Private Composer dependencies produce Excel charts');if($open===true)$zip->close();
 $changed=hostedPost('borrowing-policy',['days'=>7,'version'=>0,'reason'=>'Disposable package verification','confirm_change'=>true],$staff);
 hostingCheck($changed['days']===7&&is_file($temp.'/bcihs-private/borrowing-policy.json')&&!is_file($temp.'/public_html/includes/borrowing-policy.json'),'Writable due-date policy remains outside public root');
 // Exercise a reservation only in this temporary imported database; the release SQL stays clean.
 $db->exec('UPDATE books SET total_copies=0 WHERE id=4');
 $pickup=(new DateTimeImmutable('next monday 10:00',new DateTimeZone('Asia/Manila')))->format('Y-m-d\TH:i');
 $reservation=hostedPost('reservation-process',['book_id'=>4,'pickup_at'=>$pickup],$student)['id'];
 $db->exec('UPDATE books SET total_copies=2 WHERE id=4');
 hostedPost('reservation-action',['id'=>$reservation,'action'=>'ready'],$staff);
 $preview=hostedPost('checkout-due-preview',['source'=>'reservation','id'=>$reservation,'due_mode'=>'days','days'=>3],$staff);
 hostedPost('reservation-action',['id'=>$reservation,'action'=>'fulfill','due_preview_token'=>$preview['due_preview_token']],$staff);
 hostingCheck($db->query('SELECT due_date FROM reservations WHERE id='.(int)$reservation)->fetchColumn()===$preview['due_at'],'Imported reservation checkout retains its selected deadline');
 hostedPost('reservation-action',['id'=>$reservation,'action'=>'return'],$staff);
 hostingCheck((int)$db->query('SELECT borrowed_copies FROM books WHERE id=4')->fetchColumn()===0,'Imported reservation return restores inventory');
 [$code,$attendance]=hostingHttp('backend/attendance-data.php',null,$staff);
 hostingCheck($code===200&&(int)$db->query('SELECT track_departures FROM attendance_settings WHERE id=1')->fetchColumn()===0,'Imported attendance is Time In only');
 hostedPost('attendance-add',['mode'=>'registered','user_id'=>1,'submission_key'=>$attendance['formToken']],$staff);
 hostingCheck((int)$db->query('SELECT COUNT(*) FROM attendance')->fetchColumn()===1&&$db->query('SELECT time_out FROM attendance')->fetchColumn()===null,'Disposable registered arrival has no departure');
 // Uploaded image paths remain relative to the website root; no original uploaded files are copied.
 $image=$temp.'/upload.png';file_put_contents($image,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jk1sAAAAASUVORK5CYII='));
 $c=curl_init('http://127.0.0.1:8901/backend/book-save.php');
 curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Cookie: BCIHS_HOSTED_SESSION='.$staff['cookie'],'X-CSRF-Token: '.$staff['csrf']],
  CURLOPT_POSTFIELDS=>['title'=>'Disposable upload verification','author'=>'Verification','total_copies'=>1,'photo'=>new CURLFile($image,'image/png','cover.png')]]);
 $uploaded=json_decode(curl_exec($c),true);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
 hostingCheck($code===200&&isset($uploaded['id']),'Packaged Manage Books accepts validated image upload');
 $path=$db->query('SELECT image_path FROM books WHERE id='.(int)$uploaded['id'])->fetchColumn();
 hostingCheck((bool)preg_match('#^uploads/books/[a-f0-9]{32}\.png$#D',$path),'Safe non-executable upload filename');
 [$code,,$served]=hostingHttp($path);hostingCheck($code===200&&hash('sha256',$served)===hash_file('sha256',$image),'Uploaded image loads from website root');
 hostedPost('book-delete',['id'=>$uploaded['id']],$staff);
 [$code]=hostingHttp('backend/book-save.php',['title'=>'Unauthorized'],$student);hostingCheck($code===403,'Imported student cannot modify catalog');
 hostingCheck((int)$db->query('SELECT allow_notifications FROM sms_notification_settings WHERE id=1')->fetchColumn()===0,'Permission remains OFF after workflows');
 $verification=['checks'=>$checks,'restored_application_passed'=>true,'zero_live_provider_calls'=>true,'verification_database_removed_after_run'=>true];
 file_put_contents($release.'/application-verification.json',json_encode($verification,JSON_PRETTY_PRINT));
 echo 'Completed '.$checks." imported-package checks. Only an owned disposable database/copy was changed; zero provider calls.\n";
}catch(Throwable $error){$label=$error instanceof PDOException?'Database prerequisite or assertion':preg_replace('/[^a-zA-Z0-9 ._-]/','',$error->getMessage());fwrite(STDERR,'Package verification stopped at check '.($checks+1).': '.$label."\n");$failed=true;
}finally{
 if(is_resource($server)){proc_terminate($server);proc_close($server);}
 if($created)$master->exec('DROP DATABASE `'.$name.'`');
 if($userCreated)$master->exec('DROP USER '.$master->quote($username).'@\'localhost\'');
 if(is_dir($temp)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($temp);}
}
exit(isset($failed)?1:0);
