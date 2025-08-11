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


$whereSql = $where ? (" AND " . implode(" AND ", $where)) : "";

// ✅ 방문 단위(그룹)로 집계
$sql = "
  SELECT 
    g.name,
    g.phone,
    g.email,
    COUNT(*) AS visit_count,                 -- 방문 수 (Group_id 또는 대체키 개수)
    SUM(g.duration_minutes) AS total_minutes -- 룸-시간 합계(분)
  FROM (
    SELECT
      GB_name  AS name,
      GB_phone AS phone,
      GB_email AS email,
      COALESCE(
        Group_id,
        CONCAT(GB_date, '|', DATE_FORMAT(GB_start_time, '%H:%i'), '|', GB_phone, '|', GB_email)
      ) AS visit_key,
      /* 🔥 여기! 그룹 안 '각 행'의 길이를 전부 합산 */
      SUM( TIMESTAMPDIFF(MINUTE, GB_start_time, GB_end_time) ) AS duration_minutes
    FROM GB_Reservation
    WHERE 1=1 {$whereSql}
    GROUP BY name, phone, email, visit_key
  ) AS g
  GROUP BY g.name, g.phone, g.email
  ORDER BY visit_count DESC, g.name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
