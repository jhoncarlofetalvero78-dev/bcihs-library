<?php
if(PHP_SAPI!=='cli')exit;
ini_set('display_errors','0');umask(0077);
require dirname(__DIR__).'/includes/db_config.php';
$created=false;
try{
 if(DB_NAME!=='bcihs_library'||!in_array(DB_HOST,['127.0.0.1','localhost'],true))throw new RuntimeException();
 $payload=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);
 $name='bcihs_rotationqa_'.bin2hex(random_bytes(6));
 $dsn='mysql:host='.DB_HOST.';port='.DB_PORT.';charset=utf8mb4';
 $server=new PDO($dsn,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $server->exec('CREATE DATABASE `'.$name.'`');$created=true;
 $db=new PDO($dsn.';dbname='.$name,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $db->exec('CREATE TABLE admins(id INT PRIMARY KEY,username VARCHAR(100),password_hash VARCHAR(255)) ENGINE=InnoDB');
 $db->exec('CREATE TABLE users(id INT PRIMARY KEY,student_id VARCHAR(50),role VARCHAR(20),account_status VARCHAR(20),password_hash VARCHAR(255),reset_code VARCHAR(10),reset_code_expires DATETIME) ENGINE=InnoDB');
 $db->exec('CREATE TABLE sms_notification_settings(id INT PRIMARY KEY,allow_notifications INT) ENGINE=InnoDB');
 $old=password_hash('Synthetic-old-only',PASSWORD_DEFAULT);
 $db->prepare('INSERT INTO admins VALUES(1,?,?)')->execute(['admin',$old]);
 $db->prepare('INSERT INTO users VALUES(1,?,?,?,?,?,?)')->execute(['user','student','accepted',$old,'123456','2026-01-01']);
 $db->exec('INSERT INTO sms_notification_settings VALUES(1,0)');
 $sql=str_replace('u473523930_bcihs_library',$name,$payload['update']);
 $verify=str_replace('u473523930_bcihs_library',$name,$payload['verify']);
 $snapshot=static fn()=>[$db->query('SELECT password_hash FROM admins')->fetchColumn(),$db->query('SELECT password_hash FROM users')->fetchColumn()];
 $before=$snapshot();
 // Qualified target exists but the selected database does not match: both accounts must remain unchanged.
 $server->exec($sql);
 if($snapshot()!==$before)throw new RuntimeException();
 $db->exec("UPDATE users SET account_status='pending'");$db->exec($sql);
 if($snapshot()!==$before)throw new RuntimeException();
 $db->exec("UPDATE users SET account_status='accepted'");
 $db->exec("INSERT INTO users VALUES(2,'other','student','accepted','synthetic',NULL,NULL)");$db->exec($sql);
 if($snapshot()!==$before)throw new RuntimeException();
 $db->exec('DELETE FROM users WHERE id=2');$db->exec($sql);
 $row=$db->query($verify)->fetch();
 if(!$row||count($row)!==5||array_map('intval',array_values($row))!==[1,1,1,1,1])throw new RuntimeException();
 if($snapshot()!==[$payload['admin_hash'],$payload['user_hash']])throw new RuntimeException();
 $after=$snapshot();
 $updateOnly=explode('SELECT ROW_COUNT()',$sql,2)[0];
 $db->exec($updateOnly);
 $repeatedCount=(int)$db->query('SELECT ROW_COUNT()')->fetchColumn();
 $row=$db->query($verify)->fetch();
 if($repeatedCount!==0||$snapshot()!==$after||!$row||array_map('intval',array_values($row))!==[1,1,1,1,1])throw new RuntimeException();
 if((int)$db->query('SELECT allow_notifications FROM sms_notification_settings')->fetchColumn()!==0)throw new RuntimeException();
 if((int)$db->query('SELECT COUNT(*) FROM admins')->fetchColumn()!==1||(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()!==1)throw new RuntimeException();
 echo "Passed isolated hosted-rotation checks, including repeated update returning 0 with unchanged hashes and all five verification checks still 1. No original/hosted database changed.\n";
}catch(Throwable $error){fwrite(STDERR,"Offline rotation verification stopped safely; no credentials displayed.\n");$failed=true;}
finally{if($created)$server->exec('DROP DATABASE `'.$name.'`');}
exit(isset($failed)?1:0);
