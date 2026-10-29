<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/borrowing.php';
try {
    $input = borrowingPost($pdo, true);
    $source = $input['source'] ?? null;
    if (!in_array($source, ['borrow','reservation','manual'], true)) throw new DomainException('Choose a valid checkout source.', 422);
    $id = borrowingId($input['id'] ?? null);
    $table = match ($source) {'borrow'=>'borrow_requests', 'reservation'=>'reservations', default=>'books'};
    $stmt = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE id=:id'); $stmt->execute(['id'=>$id]);
    if (!$stmt->fetch()) throw new DomainException('That checkout record no longer exists.', 404);
    $policy = loanPolicy();
    $now = $pdo->query('SELECT NOW()')->fetchColumn();
    $due = calculateLoanDue($input, $policy, new DateTimeImmutable($now, new DateTimeZone('Asia/Manila')));
    $token = bin2hex(random_bytes(32));
    $_SESSION['loan_due_previews'][$token] = ['source'=>$source, 'id'=>$id, 'due_at'=>$due,
        'mode'=>$input['due_mode'] ?? 'default', 'policy_version'=>$policy['version'], 'expires'=>time()+600];
    $_SESSION['loan_due_previews'] = array_slice($_SESSION['loan_due_previews'], -20, null, true);
    echo json_encode(['due_at'=>$due,'timezone'=>'Asia/Manila','due_preview_token'=>$token,'default_days'=>$policy['days']]);
} catch (Throwable $exception) { borrowingFailure($pdo, $exception); }
