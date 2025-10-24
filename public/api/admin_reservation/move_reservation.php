<?php
// /api/move_reservation.php  — Sportech (Virtual Tee Up 방식으로 통일)
header('Content-Type: application/json; charset=utf-8');

try {
  require_once __DIR__ . '/../../includes/config.php';
  require_once __DIR__ . '/../../includes/functions.php'; // 필요시

  // ---- 입력 파라미터 수집 (호환 키 모두 허용) ----
  $date       = $_POST['date']        ?? ($_POST['GB_date'] ?? '');
  $start_time = $_POST['start_time']  ?? ($_POST['GB_start_time'] ?? '');
  $end_time   = $_POST['end_time']    ?? ($_POST['GB_end_time'] ?? '');

  // first_room: 기본은 JS가 보내는 값, room_no/GB_room_no도 허용
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

  // ---- 시간 검증/보정 (버츄어 방식): end <= start 면 익일(+24h) 간주 ----
  [$sh, $sm] = array_map('intval', explode(':', $start_time));
  [$eh, $em] = array_map('intval', explode(':', $end_time));
  $start_min = $sh * 60 + $sm;
  $end_min   = $eh * 60 + $em;

  // 종료 00:00은 일반화 로직으로 그대로 처리(= 0분). end<=start면 아래에서 +1440 돼서 익일로 잡힘.
  if ($end_min <= $start_min) {
    $end_min += 1440; // +24h
  }

  // 초 단위로 변환 (충돌 체크에 사용)
  $s_sec = $start_min * 60;
  $e_sec = $end_min   * 60;

  // 방 범위 (스포텍은 1~5 유지)
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

    // ✅ 충돌 체크(자기 그룹 제외): 초 단위 비교 + 자정 넘김 보정
    $conf = $pdo->prepare("
      SELECT 1
      FROM GB_Reservation
      WHERE GB_date = :d
        AND GB_room_no = :room
        AND NOT (
          (CASE WHEN GB_end_time   = '00:00' THEN 86400 ELSE TIME_TO_SEC(CONCAT(GB_end_time,   ':00')) END) <= :s
          OR
          (CASE WHEN GB_start_time = '00:00' THEN 0     ELSE TIME_TO_SEC(CONCAT(GB_start_time, ':00')) END) >= :e
        )
        AND Group_id <> :gid
      LIMIT 1
    ");
    foreach ($targets as $t) {
      $conf->execute([
        ':d'=>$date, ':room'=>$t['GB_room_no'], ':s'=>$s_sec, ':e'=>$e_sec, ':gid'=>$group_id
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

    // ✅ 충돌 체크(자기 자신 제외): 초 단위 비교 + 자정 넘김 보정
    $conf = $pdo->prepare("
      SELECT 1
      FROM GB_Reservation
      WHERE GB_date = :d
        AND GB_room_no = :room
        AND NOT (
          (CASE WHEN GB_end_time   = '00:00' THEN 86400 ELSE TIME_TO_SEC(CONCAT(GB_end_time,   ':00')) END) <= :s
          OR
          (CASE WHEN GB_start_time = '00:00' THEN 0     ELSE TIME_TO_SEC(CONCAT(GB_start_time, ':00')) END) >= :e
        )
        AND GB_id <> :id
      LIMIT 1
    ");
    $conf->execute([
      ':d'=>$date, ':room'=>$first_room, ':s'=>$s_sec, ':e'=>$e_sec, ':id'=>$id
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

  // 응답: 그룹/단일 케이스 별로 동일 포맷 유지
  if (!empty($_POST['Group_id']) || !empty($_POST['group_id'])) {
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
      $idOut = !empty($_POST['id'])
         ? $_POST['id']
         : (!empty($_POST['GB_id']) ? $_POST['GB_id'] : null);
      $stmt = $pdo->prepare("SELECT GB_email FROM GB_Reservation WHERE GB_id = ? LIMIT 1");
      $stmt->execute([$idOut]);
      $email = $stmt->fetchColumn() ?: '';

      echo json_encode([
          "success" => true,
          "id"      => (string)$idOut,
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
