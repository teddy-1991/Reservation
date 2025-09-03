<?php
// update_reservation.php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php'; // $pdo
require_once __DIR__ . '/../includes/functions.php'; // ✅ 추가: get_client_ip()

function pick(...$keys) {
  foreach ($keys as $k) {
    if (isset($_POST[$k])) return $_POST[$k];
  }
  return null;
}

function norm_time($t) {
  // Keep HH:MM only
  $t = trim((string)$t);
  return substr($t, 0, 5);
}

function norm_rooms($val): array {
  // Accept: array, "1,2,3", "2"
  if (is_array($val)) $arr = $val;
  else $arr = preg_split('/\s*,\s*/', (string)$val, -1, PREG_SPLIT_NO_EMPTY);

  // keep numeric only, unique, preserve order
  $seen = [];
  $out = [];
  foreach ($arr as $v) {
    $v = (string)(int)$v;
    if ($v === '0') continue;
    if (!isset($seen[$v])) {
      $seen[$v] = true;
      $out[] = $v;
    }
  }
  return $out;
}

try {
  // ---- read inputs (tolerant to various key names) ----
  $id        = pick('GB_id', 'id');                  // single reservation id
  $groupId   = pick('Group_id', 'group_id');         // group reservation id
  $date      = pick('GB_date', 'date');
  $startTime = norm_time(pick('GB_start_time', 'start_time', 'start'));
  $endTime   = norm_time(pick('GB_end_time', 'end_time', 'end'));
  $roomsRaw  = pick('GB_room_no', 'rooms', 'room', 'room_no');
  $ip = get_client_ip(); // 프록시 헤더까지 보는 함수라면 더 좋음

  // Optional guest fields (if your admin modal sends them)
  $name  = pick('GB_name', 'name');
  $phone = pick('GB_phone', 'phone');
  $email = pick('GB_email', 'email');

  if (!$date || !$startTime || !$endTime || (!$id && !$groupId) || !$roomsRaw) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'bad_request']); exit;
  }
  if ($endTime <= $startTime) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'end_must_be_after_start']); exit;
  }

  $rooms = norm_rooms($roomsRaw);
  if (empty($rooms)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'no_rooms']); exit;
  }

  // ---- conflict check (exclude self/group) ----
  // overlap if NOT (existing.end <= new.start OR existing.start >= new.end)
  $phRooms = implode(',', array_fill(0, count($rooms), '?'));

  $params = [$date, $startTime, $endTime, ...$rooms];
  if ($groupId) {
    $exclude = "AND (Group_id IS NULL OR Group_id <> ?)";
    $params[] = $groupId;
  } else {
    $exclude = "AND GB_id <> ?";
    $params[] = $id;
  }

  $sqlConflict = "
    SELECT 1
    FROM GB_Reservation
    WHERE GB_date = ?
      AND NOT (GB_end_time <= ? OR GB_start_time >= ?)
      AND GB_room_no IN ($phRooms)
      $exclude
    LIMIT 1
  ";
  $st = $pdo->prepare($sqlConflict);
  $st->execute($params);
  if ($st->fetchColumn()) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'time_conflict']); exit;
  }

  // ---- apply update ----
  $pdo->beginTransaction();

  if ($groupId) {
    // 1) fetch one existing row to preserve missing guest fields
    $st = $pdo->prepare("SELECT * FROM GB_Reservation WHERE Group_id = ? LIMIT 1");
    $st->execute([$groupId]);
    $base = $st->fetch(PDO::FETCH_ASSOC);
    if (!$base) {
      $pdo->rollBack();
      http_response_code(400);
      echo json_encode(['success' => false, 'message' => 'group_not_found']); exit;
    }

    // prefer posted values, fallback to previous
    $name  = $name  ?? ($base['GB_name']  ?? null);
    $phone = $phone ?? ($base['GB_phone'] ?? null);
    $email = $email ?? ($base['GB_email'] ?? null);

    // 2) delete old rows of this group
    $del = $pdo->prepare("DELETE FROM GB_Reservation WHERE Group_id = ?");
    $del->execute([$groupId]);
    // 기존에 IP가 있으면 그걸 유지, 없으면 이번 요청 IP 사용
    $ipToUse = !empty($base['GB_ip']) ? $base['GB_ip'] : $ip;
    // 3) re-insert one row per room with same group id
    $ins = $pdo->prepare("
      INSERT INTO GB_Reservation
        (GB_name, GB_phone, GB_email, GB_date, GB_start_time, GB_end_time, GB_room_no, Group_id, GB_ip)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($rooms as $r) {
      $ins->execute([$name, $phone, $email, $date, $startTime, $endTime, $r, $groupId, $ipToUse]);
    }
  } else {
    // single reservation (exactly one room is used; take first)
    $room = $rooms[0];
    $upd = $pdo->prepare("
      UPDATE GB_Reservation
      SET GB_date = ?, GB_start_time = ?, GB_end_time = ?, GB_room_no = ?
          ".($name  !== null ? ", GB_name = ?"  : "")."
          ".($phone !== null ? ", GB_phone = ?" : "")."
          ".($email !== null ? ", GB_email = ?" : "")."
      WHERE GB_id = ?
    ");
    $bind = [$date, $startTime, $endTime, $room];
    if ($name  !== null) $bind[] = $name;
    if ($phone !== null) $bind[] = $phone;
    if ($email !== null) $bind[] = $email;
    $bind[] = $id;

    $upd->execute($bind);
    if ($upd->rowCount() < 1) {
      // no-op update is fine; just continue
    }
  }

  $pdo->commit();
      $gid = null;
    if (!empty($_POST['group_id'])) {
        $gid = trim((string)$_POST['group_id']);
    } elseif (!empty($_POST['Group_id'])) {
        $gid = trim((string)$_POST['Group_id']);
    }

    if ($gid) {
        // 그룹 대표 한 건에서 이메일만 확보 (대표 1건이면 충분)
        $stmt = $pdo->prepare("SELECT GB_email FROM GB_Reservation WHERE Group_id = ? LIMIT 1");
        $stmt->execute([$gid]);
        $email = $stmt->fetchColumn() ?: '';
        echo json_encode([
            'success'  => true,
            'group_id' => $gid,     // 문자열 그대로
            'email'    => $email
        ]);
        exit;
    }

    // 단일 예약 업데이트 케이스 (GB_id 사용)
    $id = null;
    if (!empty($_POST['id'])) {
        $id = (int)$_POST['id'];
    } elseif (!empty($_POST['GB_id'])) {
        $id = (int)$_POST['GB_id'];
    }

    if ($id) {
        $stmt = $pdo->prepare("SELECT GB_email FROM GB_Reservation WHERE GB_id = ? LIMIT 1");
        $stmt->execute([$id]);
        $email = $stmt->fetchColumn() ?: '';
        echo json_encode([
            'success' => true,
            'id'      => $id,       // 숫자면 int 유지
            'email'   => $email
        ]);
        exit;
    }

  exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("update_reservation.php response error: ".$e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
    exit;
}