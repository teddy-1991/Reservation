<?php
// /api/get_customer_note.php
require_once __DIR__ . '/../../includes/config.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

// 관리자 체크
if (empty($_SESSION['is_admin'])) {
  http_response_code(401);
  echo json_encode(['error' => 'Unauthorized']);
  exit;
}

// 파라미터
$name  = trim($_GET['name']  ?? '');
$phone = trim($_GET['phone'] ?? '');
$email = trim($_GET['email'] ?? '');

if ($name === '' || $phone === '' || $email === '') {
  http_response_code(400);
  echo json_encode(['error' => 'Missing fields']);
  exit;
}

// 조회
$stmt = $pdo->prepare("
  SELECT note
  FROM customer_notes
  WHERE name = :n AND phone = :p AND email = :e
  LIMIT 1
");
$stmt->execute([':n'=>$name, ':p'=>$phone, ':e'=>$email]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode(['note' => $row['note'] ?? '']);