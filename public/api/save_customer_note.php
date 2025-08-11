<?php
// /api/save_customer_note.php
require_once __DIR__ . '/../includes/config.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

// 관리자 체크
if (empty($_SESSION['is_admin'])) {
  http_response_code(401);
  echo json_encode(['success'=>false, 'message'=>'Unauthorized']);
  exit;
}

// 파라미터 수집
$name  = trim($_POST['name']  ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$note  = trim($_POST['note']  ?? '');

// 필수값 검증
if ($name === '' || $phone === '' || $email === '') {
  http_response_code(400);
  echo json_encode(['success'=>false, 'message'=>'Missing fields']);
  exit;
}

// (선택) 메모 길이 제한
if (mb_strlen($note) > 5000) {
  http_response_code(413);
  echo json_encode(['success'=>false, 'message'=>'Note too long']);
  exit;
}

try {
  // 업서트(있으면 업데이트, 없으면 인서트)
  $sql = "INSERT INTO customer_notes (name, phone, email, note)
          VALUES (:n, :p, :e, :note)
          ON DUPLICATE KEY UPDATE note = VALUES(note), updated_at = CURRENT_TIMESTAMP";
  $stmt = $pdo->prepare($sql);
  $ok = $stmt->execute([
    ':n' => $name,
    ':p' => $phone,
    ':e' => $email,
    ':note' => $note
  ]);

  echo json_encode(['success' => $ok]);
} catch (Throwable $e) {
  error_log('[save_customer_note] ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success'=>false, 'message'=>'Server error']);
}