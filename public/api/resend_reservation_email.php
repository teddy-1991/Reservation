<?php
// /api/resend_reservation_email.php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Edmonton');

function hm($t) { return substr(trim((string)$t), 0, 5); } // "HH:MM"


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

  $isGroup  = (isset($groupId) && $groupId !== '');
  $isSingle = (isset($id) && $id !== '' && ctype_digit((string)$id));

  if (!$isGroup && !$isSingle) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing id or group_id']);
    exit;
  }

  $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

  $subject = ($reason === 'moved' || $reason === 'updated')
    ? 'Your reservation has been updated'
    : null; // null이면 기본 제목 유지(새 예약 메일)

  $introHtml = ($reason === 'moved' || $reason === 'updated')
    ? 'Your reservation details were updated as requested. Please review the latest details below.'
    : '';

  // --- 예약 데이터 조회 (group_id가 있으면 그걸로) ---
  if ($isGroup) {
    // 1) 대표 정보 1건 (이름/이메일/날짜/시간)
    $st = $pdo->prepare("
      SELECT GB_name, GB_email, GB_date, GB_start_time, GB_end_time
      FROM GB_Reservation
      WHERE Group_id = ?
      LIMIT 1
    ");
    $st->execute([$groupId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      http_response_code(404);
      echo json_encode(['success' => false, 'error' => 'Reservation not found']);
      exit;
    }

    // 2) 동일 그룹의 모든 방 번호를 "1,2,3"으로 결합
    $st2 = $pdo->prepare("
      SELECT GROUP_CONCAT(DISTINCT GB_room_no ORDER BY GB_room_no SEPARATOR ',') AS rooms
      FROM GB_Reservation
      WHERE Group_id = ?
    ");
    $st2->execute([$groupId]);
    $roomList = (string)($st2->fetchColumn() ?: '');

    // 3) 메일 전송 (room에 $roomList 사용)
    $ok = sendReservationEmail(
      (string)$row['GB_email'],      // toEmail
      (string)$row['GB_name'],       // toName
      (string)$row['GB_date'],       // date
      hm($row['GB_start_time']),  
      hm($row['GB_end_time']),   
      $roomList,                      //  "1,2,3"
      $subject,    // ✅ 7번째: 제목 오버라이드(없으면 null)
      $introHtml   // ✅ 8번째: 상단 인트로 문장(없으면 빈 문자열)
    );

    echo json_encode(['success' => (bool)$ok]);
    exit;
  }
  // 단일 케이스
  if ($isSingle) {
    $st = $pdo->prepare("
      SELECT GB_name, GB_email, GB_date, GB_room_no, GB_start_time, GB_end_time
      FROM GB_Reservation
      WHERE GB_id = ?
      LIMIT 1
    ");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      http_response_code(404);
      echo json_encode(['success' => false, 'error' => 'Reservation not found']);
      exit;
    }

    $ok = sendReservationEmail(
      (string)$row['GB_email'],
      (string)$row['GB_name'],
      (string)$row['GB_date'],
      hm($row['GB_start_time']),  
      hm($row['GB_end_time']),    
      (string)$row['GB_room_no'],   // 단일은 그대로
      $subject,    // ✅ 7번째: 제목 오버라이드(없으면 null)
      $introHtml   // ✅ 8번째: 상단 인트로 문장(없으면 빈 문자열)
    );

    echo json_encode(['success' => (bool)$ok]);
    exit;
  }

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}