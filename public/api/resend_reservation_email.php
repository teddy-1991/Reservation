<?php
// /api/resend_reservation_email.php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Edmonton');

function hm($t) { return substr(trim((string)$t), 0, 5); } // "HH:MM"

try {
  require_once __DIR__ . '/../includes/config.php';
  require_once __DIR__ . '/../includes/functions.php'; // sendReservationEmail(...) 가 여기 있다고 가정

  // --- 입력 파라미터: group_id 우선, 없으면 id ---
  $groupId = null;
  if (isset($_POST['group_id']))      $groupId = trim((string)$_POST['group_id']);
  elseif (isset($_POST['Group_id']))  $groupId = trim((string)$_POST['Group_id']);

  $id = null;
  if (isset($_POST['id']))            $id = trim((string)$_POST['id']);
  elseif (isset($_POST['GB_id']))     $id = trim((string)$_POST['GB_id']);

  $hasGroup  = ($groupId !== null && $groupId !== '');
  $hasSingle = ($id !== null && $id !== '' && ctype_digit((string)$id));

  if (!$hasGroup && !$hasSingle) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing id or group_id']);
    exit;
  }

  $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

  // 제목/인트로 (updated/moved면 오버라이드, 그 외엔 기본 제목/기본 인트로 사용)
  $subject = ($reason === 'moved' || $reason === 'updated')
    ? 'Your reservation has been updated'
    : null; // null이면 sendReservationEmail 내 기본 제목 사용

  $introHtml = ($reason === 'moved' || $reason === 'updated')
    ? 'Your reservation details were updated as requested. Please review the latest details below.'
    : '';

  // --- ✅ 여기서 "실제 사용할 그룹ID"를 한 번만 확정 ---
  if ($hasGroup) {
    $effectiveGroupId = (string)$groupId;
  } else {
    // 단일로 들어온 경우 해당 예약의 Group_id 먼저 조회
    $g = $pdo->prepare("SELECT Group_id FROM GB_Reservation WHERE GB_id = ? LIMIT 1");
    $g->execute([$id]);
    $effectiveGroupId = (string)($g->fetchColumn() ?: '');
    if ($effectiveGroupId === '') {
      http_response_code(404);
      echo json_encode(['success' => false, 'error' => 'Reservation not found']);
      exit;
    }
  }

  // 토큰은 항상 그룹 기준으로 사용
  $tokenMeta = ['group_id' => $effectiveGroupId];

  // --- 그룹 분기: 그룹 대표 데이터 + 방목록 집계 후, 한 번만 발송 ---
  if ($hasGroup) {
    // 대표 데이터 1건
    $st = $pdo->prepare("
      SELECT GB_name, GB_email, GB_date, GB_start_time, GB_end_time
      FROM GB_Reservation
      WHERE Group_id = ?
      LIMIT 1
    ");
    $st->execute([$effectiveGroupId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      http_response_code(404);
      echo json_encode(['success' => false, 'error' => 'Reservation not found']);
      exit;
    }

    // 동일 그룹 방목록 -> "1,2,3"
    $st2 = $pdo->prepare("
      SELECT GROUP_CONCAT(DISTINCT GB_room_no ORDER BY GB_room_no SEPARATOR ',') AS rooms
      FROM GB_Reservation
      WHERE Group_id = ?
    ");
    $st2->execute([$effectiveGroupId]);
    $roomList = (string)($st2->fetchColumn() ?: '');

    // 메일 전송
    $ok = sendReservationEmail(
      (string)$row['GB_email'],
      (string)$row['GB_name'],
      (string)$row['GB_date'],
      hm($row['GB_start_time']),
      hm($row['GB_end_time']),
      $roomList,         // "1,2,3"
      $subject,          // 제목 오버라이드(없으면 null)
      $introHtml,        // 상단 인트로(없으면 빈 문자열)
      $tokenMeta         // ★ self-service 블록용 메타 (항상 group 기준)
    );

    echo json_encode(['success' => (bool)$ok, 'group_id' => $effectiveGroupId]);
    exit;
  }

  // --- 단일 분기: 단일 예약 데이터 조회 후, 역시 group 기준 토큰으로 발송 ---
  if ($hasSingle) {
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
      (string)$row['GB_room_no'],  // 단일은 해당 방번호
      $subject,
      $introHtml,
      $tokenMeta                   // ★ 위에서 확정한 group 기준 토큰 메타 사용
    );

    echo json_encode([
      'success'  => (bool)$ok,
      'id'       => (int)$id,
      'group_id' => $effectiveGroupId
    ]);
    exit;
  }

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
