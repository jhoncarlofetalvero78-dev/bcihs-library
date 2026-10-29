<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');date_default_timezone_set('Asia/Manila');umask(0077);
require dirname(__DIR__).'/includes/db_config.php';
function exportSnapshot(PDO $db):array{
 $result=[];$db->beginTransaction();
 try{foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){
  if(!preg_match('/^[a-z_]+$/D',$table))throw new RuntimeException();
  $rows=$db->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
  $hashes=array_map(static fn($row)=>hash('sha256',serialize($row)),$rows);sort($hashes);
  $result[$table]=['count'=>count($rows),'hash'=>hash('sha256',serialize($hashes))];
 }}finally{$db->rollBack();}return $result;
}
try{
 $workspace=$argv[1]??'';$output=$argv[2]??'';
 if(DB_NAME!=='bcihs_library'||!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true)
  ||!is_dir($output)||!str_starts_with($output,'/')||str_contains($output,'..'))throw new RuntimeException();
 $ownerFile=$workspace.'/demo-owner.json';
 if(!is_file($ownerFile)||is_link($ownerFile)||(fileperms($ownerFile)&0777)!==0600)throw new RuntimeException();
 $owner=json_decode(file_get_contents($ownerFile),true);$name='bcihs_demo_20261028_236ee935';
 $local=require $workspace.'/app/includes/db-local.php';
 if(($owner['database']??'')!==$name||($local['BCIHS_DB_NAME']??'')!==$name)throw new RuntimeException();
 $dsn='mysql:host='.DB_HOST.(DB_PORT!==''?';port='.DB_PORT:'').';charset=utf8mb4';
 $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
 $server=new PDO($dsn,DB_USER,DB_PASS,$options);$development=new PDO($dsn.';dbname='.DB_NAME,DB_USER,DB_PASS,$options);
 $stage=new PDO($dsn.';dbname='.$name,DB_USER,DB_PASS,$options);$stage->exec("SET time_zone='+08:00'");
 $before=exportSnapshot($development);$staging=exportSnapshot($stage);
 if($staging!==json_decode(file_get_contents($workspace.'/demo-seeded.json'),true))throw new RuntimeException();
 $expected=['admins'=>1,'users'=>1,'books'=>10,'announcements'=>4,'attendance'=>0,'attendance_settings'=>1,'borrow_requests'=>0,
 'reservation_settings'=>1,'reservations'=>0,'sms_logs'=>0,'sms_notification_audit'=>0,'sms_notification_settings'=>1];ksort($expected);
 if(array_map(static fn($row)=>$row['count'],$staging)!==$expected)throw new RuntimeException();
 if((int)$stage->query('SELECT SUM(total_copies-borrowed_copies) FROM books')->fetchColumn()!==24
 ||(int)$stage->query('SELECT allow_notifications FROM sms_notification_settings WHERE id=1')->fetchColumn()!==0)throw new RuntimeException();
 $sql="-- Final refined BCIHS staging content. Import into a NEW empty target only.\nSET NAMES utf8mb4;\nSET time_zone='+08:00';\nSET FOREIGN_KEY_CHECKS=0;\n";
 $stage->beginTransaction();
 try{foreach(array_keys($staging) as $table){
  $ddl=$stage->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
  // Remove MySQL instance counters; keep declared columns, foreign keys, indexes and table options.
  $ddl=preg_replace('/ AUTO_INCREMENT=\d+/','',$ddl);$sql.=$ddl.";\n";
 }
 foreach(array_keys($staging) as $table){foreach($stage->query('SELECT * FROM `'.$table.'`')->fetchAll() as $row){
  $columns=implode(',',array_map(static fn($key)=>'`'.$key.'`',array_keys($row)));
  $values=implode(',',array_map(static fn($value)=>$value===null?'NULL':$stage->quote((string)$value),$row));
  $sql.='INSERT INTO `'.$table.'` ('.$columns.') VALUES ('.$values.");\n";
 }}$sql.="SET FOREIGN_KEY_CHECKS=1;\n";
 }finally{$stage->rollBack();}
 if(preg_match('#/Users/|/Applications/|CREATE DATABASE|\bUSE\s+`|DEFINER=|BCIHS_DB_PASS|unisms_secret#i',$sql))throw new RuntimeException();
 $file=$output.'/bcihs-refined.sql';
 if(file_exists($file)||file_put_contents($file,$sql)!==strlen($sql))throw new RuntimeException();chmod($file,0600);
 $verify='bcihs_hostverify_'.bin2hex(random_bytes(6));
 $server->exec('CREATE DATABASE `'.$verify.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
 try{
  $db=new PDO($dsn.';dbname='.$verify,DB_USER,DB_PASS,$options);
  $db->exec(file_get_contents($file));$db->exec("SET time_zone='+08:00'");
  if(exportSnapshot($db)!==$staging)throw new RuntimeException();
  $verified=true;
 }finally{$server->exec('DROP DATABASE `'.$verify.'`');}
 if(exportSnapshot($development)!==$before||exportSnapshot($stage)!==$staging)throw new RuntimeException();
 $record=['source_database'=>$name,'tables'=>$expected,'available_copies'=>24,'sql_sha256'=>hash_file('sha256',$file),
  'restored_row_hashes_match'=>true,'development_unchanged'=>true,'staging_unchanged'=>true,'created_at'=>date(DATE_ATOM)];
 file_put_contents($output.'/database-verification.json',json_encode($record,JSON_PRETTY_PRINT));
 echo json_encode($record,JSON_PRETTY_PRINT),"\n";
}catch(Throwable $error){fwrite(STDERR,"Hosting export stopped safely. No source reset or restore performed.\n");exit(1);}
