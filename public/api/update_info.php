<?php
// /public/api/update_info.php
require_once __DIR__ . '/../includes/config.php';
session_start();
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
  http_response_code(401);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit;
}
header('Content-Type: application/json; charset=utf-8');

try {
  $data = json_decode(file_get_contents('php://input') ?: '[]', true);
  $groupId  = trim((string)($data['group_id']   ?? ''));
  $newEmail = trim((string)($data['new_email']  ?? ''));
  $newName  = trim((string)($data['new_name']   ?? ''));
  $birthday = trim((string)($data['birthday']   ?? ''));


  if ($groupId === '')              throw new RuntimeException('group_id is required');
  if ($newEmail === '' && $newName === '') throw new RuntimeException('nothing to update');

  $fields = [];
  $params = [':gid' => $groupId];

  if ($newEmail !== '') {
    $normEmail = strtolower($newEmail);
    if (!filter_var($normEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('invalid email format');
    $fields[] = 'GB_email = :email';
    $params[':email'] = $normEmail;
  }
  if ($newName !== '') {
    // 이름은 공백 트림 정도만 (원하면 중복 공백 정리)
    $normName = preg_replace('/\s+/u', ' ', trim($newName));
    $fields[] = 'GB_name = :name';
    $params[':name'] = $normName;
  }

  if ($birthday !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) {
      throw new RuntimeException('invalid birthday format');
    }
    $fields[] = 'GB_birthday = :birthday';
    $params[':birthday'] = $birthday;
  }

  $sql = "UPDATE GB_Reservation SET ".implode(', ', $fields)." WHERE Group_id = :gid";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  echo json_encode([
    'ok' => true,
    'group_id' => $groupId,
    'email' => $params[':email'] ?? null,
    'name'  => $params[':name']  ?? null,
    'affected' => $stmt->rowCount()
  ]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
