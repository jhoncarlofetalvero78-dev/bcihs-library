<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/announcements.php';
$newPhoto=null;
try {
    $input=borrowingPost($pdo,true);
    $id=isset($input['id']) && $input['id']!==''?borrowingId($input['id']):null;
    $title=accountText($input['title']??null,200);
    $body=$input['body']??null;
    if (!is_string($body) || trim($body)==='' || strlen($body)>60000 || !mb_check_encoding($body,'UTF-8') || str_contains($body,"\0")) throw new DomainException('Description is required and must be at most 60000 UTF-8 bytes.',422);
    $body=trim($body);$category=$input['category']??'General';
    if (!in_array($category,['General','Event','Notice'],true)) throw new DomainException('Choose a valid category.',422);
    $key=$input['submission_key']??null;
    if (!is_string($key) || !array_key_exists($key,$_SESSION['announcement_forms']??[])) throw new DomainException('Reload Announcements before saving a fresh form.',422);
    $photoHash=null;
    if (isset($_FILES['photo']) && ($_FILES['photo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) {
        if (!is_string($_FILES['photo']['tmp_name']??null) || !is_uploaded_file($_FILES['photo']['tmp_name'])) throw new DomainException('Invalid photo upload.',422);
        $photoHash=hash_file('sha256',$_FILES['photo']['tmp_name']);
    }
    $hash=hash('sha256',json_encode([$id,$title,$body,$category,$photoHash],JSON_THROW_ON_ERROR));
    $saved=$_SESSION['announcement_forms'][$key];
    if ($saved!==null) {
        if (!hash_equals($saved['hash'],$hash)) throw new DomainException('This form was already saved. Reload to start another announcement.',409);
        $q=$pdo->prepare('SELECT id FROM announcements WHERE id=:id');$q->execute(['id'=>$saved['id']]);
        if (!$q->fetch()) throw new DomainException('The saved announcement was removed. Reload before creating another.',409);
        echo json_encode(['success'=>true,'id'=>$saved['id'],'replayed'=>true,'submissionToken'=>announcementToken()]);exit;
    }
$newPhoto = null;
if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
    $upload = $_FILES['photo'];
    if (!is_array($upload) || !is_int($upload['error']) || !is_string($upload['tmp_name']) || !is_numeric($upload['size'])) bookError(422, 'Invalid photo upload.');
    if ($upload['error'] !== UPLOAD_ERR_OK) {
        bookError(422, 'The photo could not be uploaded. Please try again.');
    }
    if ($upload['size'] > 5 * 1024 * 1024) {
        bookError(422, 'Photo must be 5 MB or smaller.');
    }
    if (!is_uploaded_file($upload['tmp_name'])) {
        bookError(422, 'Invalid photo upload.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($extensions[$mime]) || @getimagesize($upload['tmp_name'])===false) {
        bookError(422, 'Photo must be a JPG, PNG, or WebP image.');
    }

    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'announcements';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        bookError(500, 'Photo storage is unavailable. Please contact the administrator.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($upload['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
        bookError(500, 'The photo could not be saved. Please try again.');
    }
    $newPhoto = 'uploads/announcements/' . $filename;
}

    $pdo->beginTransaction();$oldPhoto=null;
    if ($id!==null) {
        $q=$pdo->prepare('SELECT image_path FROM announcements WHERE id=:id FOR UPDATE');$q->execute(['id'=>$id]);
        $old=$q->fetch();if (!$old) throw new DomainException('Announcement not found.',404);$oldPhoto=$old['image_path'];
        $pdo->prepare('UPDATE announcements SET title=:title,body=:body,category=:category,image_path=:image WHERE id=:id')
            ->execute(['title'=>$title,'body'=>$body,'category'=>$category,'image'=>$newPhoto??$oldPhoto,'id'=>$id]);
    } else {
        $pdo->prepare('INSERT INTO announcements(title,body,category,image_path,posted_by)VALUES(:title,:body,:category,:image,:user)')
            ->execute(['title'=>$title,'body'=>$body,'category'=>$category,'image'=>$newPhoto,'user'=>isset($_SESSION['admin_id'])?null:$_SESSION['user_id']]);
        $id=(int)$pdo->lastInsertId();
    }
    $pdo->commit();
    $_SESSION['announcement_forms'][$key]=['hash'=>$hash,'id'=>$id];
    unset($_SESSION['announcement_token']);
    if ($newPhoto && $oldPhoto) removeAnnouncementPhoto($oldPhoto);
    echo json_encode(['success'=>true,'id'=>$id,'replayed'=>false,'submissionToken'=>announcementToken()]);
} catch(Throwable $exception){
    if ($newPhoto) removeAnnouncementPhoto($newPhoto);
    borrowingFailure($pdo,$exception);
}
