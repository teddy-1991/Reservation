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
$all = (isset($_GET['all']) && $_GET['all'] === '1');

if (!$name && !$phone && !$email && !$all) {
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
    COUNT(*) AS visit_count,                             -- 예약(그룹) 개수
    SUM(g.group_minutes * g.room_count) AS total_minutes, -- 룸-아워 합계
    COALESCE(MAX(cn.note), '') AS memo,
    COALESCE(r.ips, '') AS ips,
    COALESCE(r.ip_count, 0) AS ip_count
  FROM (
    /* === 예약 1건(Group_id)당 '그룹 길이' ⨉ '방 개수' === */
    SELECT
      /* 동일 예약을 하나로 묶는 키 (Group_id 없을 때의 예비키 유지) */
      COALESCE(
        Group_id,
        CONCAT(GB_date, '|', DATE_FORMAT(GB_start_time, '%H:%i'), '|', GB_phone, '|', GB_email)
      ) AS visit_key,

      MIN(GB_name)  AS name,
      MIN(GB_phone) AS phone,
      MIN(GB_email) AS email,

      /* 그룹 길이(분): 그룹 내 MIN(start) ~ MAX(end) 한 번만 계산 */
      GREATEST(
        (
          (CASE
            WHEN TIME_TO_SEC(MAX(GB_end_time)) = 0 THEN 1440
            ELSE TIME_TO_SEC(MAX(GB_end_time)) / 60
          END)
          - (TIME_TO_SEC(MIN(GB_start_time)) / 60)
        ),
        0
      ) AS group_minutes,

      /* 방 개수(중복 없이) */
      COUNT(DISTINCT GB_room_no) AS room_count
    FROM GB_Reservation
    WHERE 1=1 {$whereSql}
    GROUP BY visit_key
  ) AS g

  /* 메모 조인(그대로) */
  LEFT JOIN customer_notes cn
    ON cn.name = g.name AND cn.phone = g.phone AND cn.email = g.email

  /* ⛳️ IP는 먼저 고객별로 집계한 뒤 조인(중복 방지!) */
  LEFT JOIN (
    SELECT 
      GB_name, GB_phone, GB_email,
      GROUP_CONCAT(DISTINCT GB_ip ORDER BY GB_ip SEPARATOR ', ') AS ips,
      COUNT(DISTINCT GB_ip) AS ip_count
    FROM GB_Reservation
    WHERE 1=1 {$whereSql}
    GROUP BY GB_name, GB_phone, GB_email
  ) r
    ON r.GB_name = g.name AND r.GB_phone = g.phone AND r.GB_email = g.email

  GROUP BY g.name, g.phone, g.email
  ORDER BY visit_count DESC, g.name ASC
";


$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));