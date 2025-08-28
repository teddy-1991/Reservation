<?php
// /api/resend_reservation_email.php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Edmonton');

try {
  require_once __DIR__ . '/../includes/config.php';
  require_once __DIR__ . '/../includes/functions.php'; // sendReservationEmail(...) 이 안에 있다고 가정

  // --- 입력 파라미터: group_id 우선, 없으면 id ---
  $groupId = null;
  if (isset($_POST['group_id']))      $groupId = trim((string)$_POST['group_id']);
  elseif (isset($_POST['Group_id']))  $groupId = trim((string)$_POST['Group_id']);

  $id = null;
  if (isset($_POST['id']))            $id = trim((string)$_POST['id']);
  elseif (isset($_POST['GB_id']))     $id = trim((string)$_POST['GB_id']);

  if ($groupId <= 0 && $id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing id or group_id']);
    exit;
  }

  // --- 예약 데이터 조회 (group_id가 있으면 그걸로) ---
  if ($groupId > 0) {
    $st = $pdo->prepare("
      SELECT GB_name, GB_email, GB_date, GB_room_no, GB_start_time, GB_end_time
      FROM GB_Reservation
      WHERE Group_id = ?
      ORDER BY GB_room_no ASC
      LIMIT 1
    ");
    $st->execute([$groupId]);
  } else {
    $st = $pdo->prepare("
      SELECT GB_name, GB_email, GB_date, GB_room_no, GB_start_time, GB_end_time
      FROM GB_Reservation
      WHERE GB_id = ?
      LIMIT 1
    ");
    $st->execute([$id]);
  }

  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Reservation not found']);
    exit;
  }

  // --- 메일 발송 (인자 6개 시그니처) ---
  // 함수명이 다르거나 시그니처가 다르면 여기 한 줄만 너희 프로젝트에 맞게 바꿔줘.
  $ok = sendReservationEmail(
    (string)$row['GB_email'],     // toEmail
    (string)$row['GB_name'],      // toName
    (string)$row['GB_date'],      // date
    (string)$row['GB_start_time'],// start
    (string)$row['GB_end_time'],  // end
    (string)$row['GB_room_no']    // room
  );

  echo json_encode(['success' => (bool)$ok]);
  exit;


} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
