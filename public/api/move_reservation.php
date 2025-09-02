<?php
// /api/move_reservation.php
header('Content-Type: application/json; charset=utf-8');

try {
  require_once __DIR__ . '/../includes/config.php';
  require_once __DIR__ . '/../includes/functions.php'; // 필요시

  // ---- 입력 파라미터 수집 (호환 키 모두 허용) ----
  $date       = $_POST['date']        ?? ($_POST['GB_date'] ?? '');
  $start_time = $_POST['start_time']  ?? ($_POST['GB_start_time'] ?? '');
  $end_time   = $_POST['end_time']    ?? ($_POST['GB_end_time'] ?? '');

  // first_room 기본은 JS가 보내는 값, 호환으로 room_no/GB_room_no도 허용
  if (isset($_POST['first_room'])) {
    $first_room = (int)$_POST['first_room'];
  } elseif (isset($_POST['room_no'])) {
    $first_room = (int)$_POST['room_no'];
  } elseif (isset($_POST['GB_room_no'])) {
    $first_room = (int)$_POST['GB_room_no'];
  } else {
    $first_room = 0;
  }

  // group / id 호환
  $group_id = $_POST['group_id'] ?? ($_POST['Group_id'] ?? '');
  $id       = $_POST['id']       ?? ($_POST['GB_id']    ?? '');

  // ---- 기본 검증 ----
  $start_time = substr((string)$start_time, 0, 5);
  $end_time   = substr((string)$end_time,   0, 5);

  if (!$date || !$start_time || !$end_time || $first_room <= 0 || (!$group_id && !$id)) {
    http_response_code(400);
    echo json_encode(['success'=>false, 'message'=>'invalid params']);
    exit;
  }

  // ---- 시간 검증 (여기만 '강화'됨: 00:00을 종료 24:00으로 허용) ----
  [$sh, $sm] = array_map('intval', explode(':', $start_time));
  [$eh, $em] = array_map('intval', explode(':', $end_time));
  $start_min = $sh * 60 + $sm;

  if ($end_time === '00:00') {
    // 종료 00:00은 같은 날의 24:00으로 간주 (단, 시작도 00:00이면 0분이므로 금지)
    $end_min = ($start_min > 0) ? 1440 : 0;
  } else {
    $end_min = $eh * 60 + $em;
  }

  if ($end_min <= $start_min) {
    http_response_code(400);
    echo json_encode(['success'=>false, 'message'=>'invalid time range']);
    exit;
  }

  // (다음 단계에서 충돌체크 개선 시 쓰기 위해 준비만 해둠 — 지금은 SQL 그대로 사용)
  $s_sec = $start_min * 60;
  $e_sec = $end_min   * 60;

  // 방 범위 (필요 시 조정)
  $ROOM_MIN = 1;
  $ROOM_MAX = 5;

  $pdo->beginTransaction();

  if ($group_id) {
    // ---- 그룹 이동 ----
    $stmt = $pdo->prepare("
      SELECT GB_id, GB_room_no
      FROM GB_Reservation
      WHERE Group_id = ?
      FOR UPDATE
    ");
    $stmt->execute([$group_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
      $pdo->rollBack();
      http_response_code(404);
      echo json_encode(['success'=>false,'message'=>'group not found']);
      exit;
    }

    // 기존 방 오름차순 정렬 → 델타 계산
    usort($rows, fn($a,$b)=> intval($a['GB_room_no']) <=> intval($b['GB_room_no']));
    $oldFirst = intval($rows[0]['GB_room_no']);
    $delta    = $first_room - $oldFirst;

    // 타겟 방 계산 + 범위 체크
    $targets = [];
    foreach ($rows as $r) {
      $newRoom = intval($r['GB_room_no']) + $delta;
      if ($newRoom < $ROOM_MIN || $newRoom > $ROOM_MAX) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>'target room out of range']);
        exit;
      }
      $targets[] = ['GB_id'=>intval($r['GB_id']), 'GB_room_no'=>$newRoom];
    }

    // 충돌 체크(자기 그룹 제외) — ※ 현재는 '문자열 HH:MM' 비교 그대로 둠 (Step 2에서 초단위로 개선 예정)
    $conf = $pdo->prepare("
      SELECT 1
      FROM GB_Reservation
      WHERE GB_date = :d
        AND GB_room_no = :room
        AND NOT (GB_end_time <= :s OR GB_start_time >= :e)
        AND Group_id <> :gid
      LIMIT 1
    ");
    foreach ($targets as $t) {
      $conf->execute([
        ':d'=>$date, ':room'=>$t['GB_room_no'], ':s'=>$start_time, ':e'=>$end_time, ':gid'=>$group_id
      ]);
      if ($conf->fetch()) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>'conflict']);
        exit;
      }
    }

    // UPDATE
    $upd = $pdo->prepare("
      UPDATE GB_Reservation
      SET GB_date = :d,
          GB_start_time = :s,
          GB_end_time = :e,
          GB_room_no = :room
      WHERE GB_id = :id
    ");
    foreach ($targets as $t) {
      $upd->execute([
        ':d'=>$date, ':s'=>$start_time, ':e'=>$end_time,
        ':room'=>$t['GB_room_no'],
        ':id'=>$t['GB_id']
      ]);
    }

  } else {
    // ---- 단일 이동 ----
    $stmt = $pdo->prepare("
      SELECT GB_id, GB_room_no, Group_id, GB_email
      FROM GB_Reservation
      WHERE GB_id = ?
      FOR UPDATE
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      $pdo->rollBack();
      http_response_code(404);
      echo json_encode(['success'=>false,'message'=>'reservation not found']);
      exit;
    }

    if ($first_room < $ROOM_MIN || $first_room > $ROOM_MAX) {
      $pdo->rollBack();
      http_response_code(409);
      echo json_encode(['success'=>false,'message'=>'target room out of range']);
      exit;
    }

    // 충돌 체크(자기 자신 제외) — ※ 현재는 '문자열 HH:MM' 비교 그대로 둠 (Step 2에서 초단위로 개선 예정)
    $conf = $pdo->prepare("
      SELECT 1
      FROM GB_Reservation
      WHERE GB_date = :d
        AND GB_room_no = :room
        AND NOT (GB_end_time <= :s OR GB_start_time >= :e)
        AND GB_id <> :id
      LIMIT 1
    ");
    $conf->execute([
      ':d'=>$date, ':room'=>$first_room, ':s'=>$start_time, ':e'=>$end_time, ':id'=>$id
    ]);
    if ($conf->fetch()) {
      $pdo->rollBack();
      http_response_code(409);
      echo json_encode(['success'=>false,'message'=>'conflict']);
      exit;
    }

    // UPDATE
    $upd = $pdo->prepare("
      UPDATE GB_Reservation
      SET GB_date = :d,
          GB_start_time = :s,
          GB_end_time = :e,
          GB_room_no = :room
      WHERE GB_id = :id
    ");
    $upd->execute([
      ':d'=>$date, ':s'=>$start_time, ':e'=>$end_time,
      ':room'=>$first_room, ':id'=>$id
    ]);
  }

  $pdo->commit();
  if (!empty($_POST['Group_id']) || !empty($_POST['group_id'])) {
      // 그룹 이동 케이스
      $gid = !empty($_POST['Group_id']) 
          ? $_POST['Group_id'] 
          : (!empty($_POST['group_id']) ? $_POST['group_id'] : null);
      $stmt = $pdo->prepare("SELECT GB_email FROM GB_Reservation WHERE Group_id = ? LIMIT 1");
      $stmt->execute([$gid]);
      $email = $stmt->fetchColumn() ?: '';

      echo json_encode([
          "success"   => true,
          "group_id"  => (string)$gid,
          "email"     => $email
      ]);
  } else {
      // 단일 이동 케이스
      $id = !empty($_POST['id']) 
         ? $_POST['id'] 
         : (!empty($_POST['GB_id']) ? $_POST['GB_id'] : null);
      $stmt = $pdo->prepare("SELECT GB_email FROM GB_Reservation WHERE GB_id = ? LIMIT 1");
      $stmt->execute([$id]);
      $email = $stmt->fetchColumn() ?: '';

      echo json_encode([
          "success" => true,
          "id"      => (string)$id,
          "email"   => $email
      ]);
  }
  exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("move_reservation.php error: " . $e->getMessage());
    echo json_encode([
        "success" => false,
        "message" => "Server error",
        "error"   => $e->getMessage()
    ]);
    exit;
}