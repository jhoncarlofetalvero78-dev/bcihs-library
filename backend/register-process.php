<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/account-management.php';
function backToRegister(string $role,string $error): void {
    header('Location: ../select-role.html?'.http_build_query(['role'=>$role,'error'=>$error]));exit;
}
$role=is_string($_POST['role']??null)?$_POST['role']:'';
try {
    if ($_SERVER['REQUEST_METHOD']!=='POST') {header('Location: ../select-role.html');exit;}
    if (!in_array($role,['student','teacher','librarian','visitor'],true)) throw new DomainException('Choose a supported registration role.',422);
    $token=$_POST['csrf_token']??null;
    if (!is_string($token) || !isset($_SESSION['book_csrf_token']) || !hash_equals($_SESSION['book_csrf_token'],$token)) throw new DomainException('Reload registration and try again; your form session expired.',403);
    $name=accountText($_POST['full_name']??null,150);
    $password=$_POST['password']??null;$confirm=$_POST['confirm_password']??null;
    if (!is_string($password) || strlen($password)<8 || strlen($password)>72) throw new DomainException('Password must be 8 to 72 bytes long.',422);
    if (!is_string($confirm) || $password!==$confirm) throw new DomainException('Passwords do not match.',422);
    $phone=accountPhone($_POST['phone']??null);
    if ($phone===null) throw new DomainException('Enter a valid Philippine mobile number: 09XXXXXXXXX or +639XXXXXXXXX.',422);
    $student=$employee=$email=$year=$department=null;
    if ($role==='student') {
        $student=accountText($_POST['student_id']??null,50);
        $grade=accountText($_POST['grade_level']??'',30,false);$section=accountText($_POST['section']??'',30,false);
        $year=accountText($grade!=='' && $section!==''?$grade.' — '.$section:($_POST['year_section']??''),50);
        $identifier=$student;
    } elseif ($role==='visitor') {
        $email=accountText($_POST['email']??null,190);
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new DomainException('Enter a valid email address.',422);
        $identifier=$email;
    } else {
        $employee=accountText($_POST['id_number']??null,50);
        $department=accountText($_POST['department']??'',150,false)?:null;$identifier=$employee;
    }
    if ((int)$pdo->query("SELECT GET_LOCK('bcihs_registration_identifier',5)")->fetchColumn()!==1) throw new DomainException('Registration is busy. Please try again.',409);
    // Identifiers are used by the same sign-in field, so prevent cross-column collisions too.
    $stmt=$pdo->prepare('SELECT id FROM users WHERE student_id=:student OR id_number=:employee OR email=:email LIMIT 1');
    $stmt->execute(['student'=>$identifier,'employee'=>$identifier,'email'=>$identifier]);
    if ($stmt->fetch()) throw new DomainException('An account with that identifier already exists.',409);
    $pdo->prepare('INSERT INTO users(full_name,student_id,id_number,email,year_section,department,phone,password_hash,role,account_status)
        VALUES(:name,:student,:employee,:email,:year,:department,:phone,:hash,:role,"pending")')
        ->execute(['name'=>$name,'student'=>$student,'employee'=>$employee,'email'=>$email,'year'=>$year,'department'=>$department,
            'phone'=>$phone,'hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>$role]);
    $pdo->query("SELECT RELEASE_LOCK('bcihs_registration_identifier')");
    header('Location: ../login.html?registered=1');exit;
} catch (Throwable $exception) {
    $pdo->query("SELECT RELEASE_LOCK('bcihs_registration_identifier')");
    if ($exception instanceof PDOException && $exception->getCode()==='23000') backToRegister($role,'An account with that identifier already exists.');
    if ($exception instanceof DomainException) backToRegister(in_array($role,['student','teacher','librarian','visitor'],true)?$role:'student',$exception->getMessage());
    error_log('Registration failed.');backToRegister('student','Registration could not be completed. Please try again later.');
}
