<?php
// /api/get_single_reservation.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/config.php'; // $pdo

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  echo json_encode(['error' => 'invalid id']);
  exit;
}

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // 1) 우선 단건을 가져와서 group/date/time 파악
  $sqlOne = "
    SELECT
      r.GB_id,
      r.GB_date,
      r.GB_room_no,
      r.GB_start_time,
      r.GB_end_time,
      r.GB_name,
      r.GB_email,
      r.GB_phone,
      r.Group_id,
      r.customer_id,
      r.GB_created_at,

      ci.full_name AS customer_name,
      ci.email     AS customer_email,
      ci.phone     AS customer_phone,
      COALESCE(ci.full_name, r.GB_name) AS display_name
    FROM GB_Reservation r
    LEFT JOIN customers_info ci ON ci.id = r.customer_id
    WHERE r.GB_id = ?
    LIMIT 1
  ";
  $stmt = $pdo->prepare($sqlOne);
  $stmt->execute([$id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    echo json_encode(['error' => 'not found']);
    exit;
  }

  // 2) 같은 그룹(멀티룸) 모으기: Group_id가 있으면 같은 그룹의 모든 방을 수집
  $rooms = [];
  if (!empty($row['Group_id'])) {
    $sqlGroup = "
      SELECT GB_room_no
      FROM GB_Reservation
      WHERE Group_id = ?
        AND GB_date = ?
        AND GB_start_time = ?
        AND GB_end_time = ?
      ORDER BY GB_room_no
    ";
    $stmt = $pdo->prepare($sqlGroup);
    $stmt->execute([
      $row['Group_id'],
      $row['GB_date'],
      $row['GB_start_time'],
      $row['GB_end_time'],
    ]);
    $rooms = array_map(static fn($r) => (string)$r['GB_room_no'], $stmt->fetchAll(PDO::FETCH_ASSOC));
  } else {
    // 그룹이 없으면 단일 방만 배열로
    $rooms = [(string)$row['GB_room_no']];
  }

  // 3) 프론트 기대형식에 맞춰 정리
  $out = [
    'GB_id'          => (int)$row['GB_id'],
    'GB_date'        => $row['GB_date'],
    'GB_start_time'  => $row['GB_start_time'],
    'GB_end_time'    => $row['GB_end_time'],
    // ⚠️ JS가 배열을 기대하므로 배열로 반환
    'GB_room_no'     => $rooms,

    // 스냅샷
    'GB_name'        => $row['GB_name'],
    'GB_email'       => $row['GB_email'],
    'GB_phone'       => $row['GB_phone'],

    // 마스터
    'customer_id'    => $row['customer_id'] ? (int)$row['customer_id'] : null,
    'customer_name'  => $row['customer_name'] ?? null,
    'customer_email' => $row['customer_email'] ?? null,
    'customer_phone' => $row['customer_phone'] ?? null,
    'display_name'   => $row['display_name'],

    'Group_id'       => $row['Group_id'],
    'GB_created_at'  => $row['GB_created_at'],
  ];

  echo json_encode($out, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  error_log('[get_single_reservation] ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['error' => 'server error']);
}
