<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/account-management.php';
$identifierLock=false;
try {
    $input=borrowingPost($pdo);
    if (isset($_SESSION['admin_id'])) throw new DomainException('Administrator credentials are managed separately; this form cannot edit a patron account.',403);
    $id=(int)$_SESSION['user_id'];
    $pdo->beginTransaction();
    $stmt=$pdo->prepare('SELECT role,account_status FROM users WHERE id=:id FOR UPDATE');$stmt->execute(['id'=>$id]);$user=$stmt->fetch();
    if (!$user || $user['account_status']!=='accepted') throw new DomainException('Your account is not approved.',401);
    $name=accountText($input['full_name']??null,150);$phone=accountPhone($input['phone']??null);
    if ($phone===null) throw new DomainException('Enter a valid Philippine mobile number.',422);
    $values=['full_name'=>$name,'phone'=>$phone];
    if ($user['role']==='student') $values['year_section']=accountText($input['year_section']??null,50);
    elseif (in_array($user['role'],['teacher','librarian','staff','employee'],true)) $values['department']=accountText($input['department']??'',150,false)?:null;
    else {
        if ((int)$pdo->query("SELECT GET_LOCK('bcihs_registration_identifier',5)")->fetchColumn()!==1) throw new DomainException('Account updates are busy. Try again.',409);
        $identifierLock=true;
        $email=accountText($input['email']??null,190);
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new DomainException('Enter a valid email address.',422);
        $q=$pdo->prepare('SELECT id FROM users WHERE id<>:id AND (email=:email OR student_id=:student OR id_number=:employee) LIMIT 1');
        $q->execute(['id'=>$id,'email'=>$email,'student'=>$email,'employee'=>$email]);
        $admin=$pdo->prepare('SELECT id FROM admins WHERE username=:email LIMIT 1');$admin->execute(['email'=>$email]);
        if ($admin->fetch() || $q->fetch()) throw new DomainException('An account already uses this email identifier.',409);
        $values['email']=$email;
    }
    $clauses=[];foreach($values as $field=>$value)$clauses[]=$field.'=:'.$field;
    $pdo->prepare('UPDATE users SET '.implode(',',$clauses).' WHERE id=:id')->execute($values+['id'=>$id]);
    $pdo->commit();$_SESSION['full_name']=$name;
    echo json_encode(['success'=>true,'message'=>'Profile updated successfully.']);
} catch(Throwable $exception){
    if ($pdo->inTransaction())$pdo->rollBack();
    $code=$exception instanceof DomainException?$exception->getCode():500;
    http_response_code($code);echo json_encode(['success'=>false,'message'=>$exception instanceof DomainException?$exception->getMessage():'Could not save your profile. Please try again.']);
}
 finally {
    if ($identifierLock) $pdo->query("SELECT RELEASE_LOCK('bcihs_registration_identifier')");
}
