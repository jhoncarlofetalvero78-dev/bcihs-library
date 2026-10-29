<?php
if(PHP_SAPI!=='cli')exit;
ini_set('display_errors','0');umask(0077);
require dirname(__DIR__).'/includes/db_config.php';
$created=false;
try{
 if(DB_NAME!=='bcihs_library'||!in_array(DB_HOST,['localhost','127.0.0.1'],true))throw new RuntimeException();
 $sql=file_get_contents($argv[1]??'');
 if(!preg_match('/CREATE TABLE `announcements`.*?;\n/s',$sql,$match))throw new RuntimeException();
 $dsn='mysql:host='.DB_HOST.';port='.DB_PORT.';charset=utf8mb4';
 $server=new PDO($dsn,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $name='bcihs_fkdiag_'.bin2hex(random_bytes(6));
 $server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
 $db=new PDO($dsn.';dbname='.$name,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->exec('SET FOREIGN_KEY_CHECKS=1');$failed=false;$code=null;
 try{$db->exec($match[0]);}catch(PDOException $error){$code=$error->errorInfo[1]??null;$failed=$code===1824;}
 if(!$failed)throw new RuntimeException();
 // Demonstrates exactly why the original one-connection local restore could succeed.
 $db->exec($sql);
 if((int)$db->query('SELECT COUNT(*) FROM announcements')->fetchColumn()!==4
 ||(int)$db->query('SELECT @@foreign_key_checks')->fetchColumn()!==1)throw new RuntimeException();
 echo json_encode(['original_child_before_parent_with_checks_on'=>'rejected','local_error_code'=>$code,
  'original_full_file_in_one_session'=>'passes_locally_using_its_checks_off_statement',
  'remote_cause_proven'=>false,'tested_server'=>$server->getAttribute(PDO::ATTR_SERVER_VERSION)]),"\n";
}catch(Throwable $error){fwrite(STDERR,"Foreign-key diagnostic stopped; no source records modified.\n");$failedRun=true;}
finally{if($created)$server->exec('DROP DATABASE `'.$name.'`');}
exit(isset($failedRun)?1:0);
