<?php
// api/check_verified_phone.php

require_once __DIR__.'/../includes/config.php'; // $pdo 포함되어야 함

header('Content-Type: application/json');

$phone = $_GET['phone'] ?? '';
if (!$phone) {
  echo json_encode(['verified' => false]);
  exit;
}

// 전화번호로 기존 예약 여부 확인
$stmt = $pdo->prepare("SELECT COUNT(*) FROM GB_Reservation WHERE GB_phone = ?");
$stmt->execute([$phone]);
$count = $stmt->fetchColumn();

echo json_encode(['verified' => $count > 0]);