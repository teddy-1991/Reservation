<?php
// /api/search_customer.php

require_once __DIR__ . '/../includes/config.php';
session_start();

if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// ✅ 입력값
$name  = $_GET['name']  ?? '';
$phone = $_GET['phone'] ?? '';
$email = $_GET['email'] ?? '';

if (!$name && !$phone && !$email) {
    http_response_code(400);
    echo json_encode(['error' => 'At least one search field is required.']);
    exit;
}

// ✅ WHERE/파라미터 빌드
$where  = [];
$params = [];

if ($name !== '')  { $where[] = "GB_name  LIKE :name";  $params[':name']  = "%{$name}%"; }
if ($phone !== '') { $where[] = "GB_phone LIKE :phone"; $params[':phone'] = "%{$phone}%"; }
if ($email !== '') { $where[] = "GB_email LIKE :email"; $params[':email'] = "%{$email}%"; }

// ⚠️ 테이블명이 서버에서 대소문자 구분될 수 있음.
//    DB가 `GB_Reservation`이면 아래 FROM/쿼리의 테이블명을 정확히 맞춰주세요.
$sql = "
  SELECT
    GB_name  AS name,
    GB_phone AS phone,
    GB_email AS email,
    COUNT(*) AS visit_count,
    ROUND(SUM(TIME_TO_SEC(TIMEDIFF(GB_end_time, GB_start_time))) / 60) AS total_minutes
  FROM gb_reservation
";

if ($where) {
  $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= "
  GROUP BY GB_name, GB_phone, GB_email
  ORDER BY visit_count DESC, name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($rows);
