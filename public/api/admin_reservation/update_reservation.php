<?php
// update_reservation.php
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/config.php'; // $pdo
require_once __DIR__ . '/../../includes/functions.php'; // get_client_ip()

/* ---------- utils ---------- */
function pick(...$keys) { foreach ($keys as $k) if (isset($_POST[$k])) return $_POST[$k]; return null; }
function norm_time($t) { $t = trim((string)$t); return substr($t, 0, 5); }
function norm_rooms($val): array {
  if (is_array($val)) $arr = $val; else $arr = preg_split('/\s*,\s*/', (string)$val, -1, PREG_SPLIT_NO_EMPTY);
  $seen = []; $out = [];
  foreach ($arr as $v) { $v = (string)(int)$v; if ($v==='0') continue; if (!isset($seen[$v])) { $seen[$v]=true; $out[]=$v; } }
  return $out;
}
function nz($v) { if ($v === null) return null; $t = trim((string)$v); return ($t === '') ? null : $t; }

/* ---------- customer helpers ---------- */
// (이름+이메일+전화) "정확 일치"로 고객 찾기, 없으면 생성
function upsert_customer_id(PDO $pdo, ?string $name, ?string $email, ?string $phone): int {
  $name  = nz($name);  $email = nz($email);  $phone = nz($phone);
  $find = $pdo->prepare("
    SELECT id FROM customers_info
    WHERE full_name <=> :n AND email <=> :e AND phone <=> :p
    LIMIT 1
  ");
  $find->execute([':n'=>$name, ':e'=>$email, ':p'=>$phone]);
  $cid = $find->fetchColumn();
  if ($cid) return (int)$cid;

  $ins = $pdo->prepare("
    INSERT INTO customers_info (full_name, email, phone, created_at, updated_at)
    VALUES (:n, :e, :p, NOW(), NOW())
  ");
  $ins->execute([':n'=>$name, ':e'=>$email, ':p'=>$phone]);
  return (int)$pdo->lastInsertId();
}

// 연락처 정규화 (merge 판단용)
function normalize_email(?string $e): ?string { if ($e === null) return null; $e = strtolower(trim($e)); return $e === '' ? null : $e; }
function normalize_phone(?string $p): ?string { if ($p === null) return null; $p = preg_replace('/\D+/', '', $p); return $p === '' ? null : $p; }

/**
 * 수정으로 customer_id가 old→new로 바뀐 경우 정리:
 * - 연락처(email+phone)가 같으면: 모든 참조를 new로 옮기고 old 삭제(머지)
 * - 연락처 다르면: old가 고아면 삭제, 아니면 유지
 * return: merged | deleted_orphan | kept_has_refs
 */
function cleanup_customer_after_reassign(PDO $pdo, int $oldId, int $newId): string {
  if ($oldId <= 0 || $newId <= 0 || $oldId === $newId) return 'kept_has_refs';

  // 두 고객의 연락처 비교
  $q = $pdo->prepare("
    SELECT id, LOWER(COALESCE(email,'')) AS e, REGEXP_REPLACE(COALESCE(phone,''), '\\\\D','') AS p
    FROM customers_info
    WHERE id IN (?,?)
    ORDER BY id
  ");
  $q->execute([$oldId, $newId]);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);
  if (count($rows) !== 2) return 'kept_has_refs';

  // 매핑
  $map = [];
  foreach ($rows as $r) $map[(int)$r['id']] = ['e'=>$r['e'], 'p'=>$r['p']];
  $sameContact = ($map[$oldId]['e'] === $map[$newId]['e']) && ($map[$oldId]['p'] === $map[$newId]['p']);

  if ($sameContact) {
    // 모든 참조 이전 후 삭제
    $pdo->prepare("UPDATE GB_Reservation SET customer_id=? WHERE customer_id=?")->execute([$newId, $oldId]);
    $pdo->prepare("UPDATE event_registrations SET customer_id=? WHERE customer_id=?")->execute([$newId, $oldId]);
    $pdo->prepare("DELETE FROM customers_info WHERE id=?")->execute([$oldId]);
    return 'merged';
  }

  // 연락처 다르면: 고아 여부 확인
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM GB_Reservation WHERE customer_id=?");
  $stmt->execute([$oldId]);
  $cnt1 = (int)$stmt->fetchColumn();

  $stmt = $pdo->prepare("SELECT COUNT(*) FROM event_registrations WHERE customer_id=?");
  $stmt->execute([$oldId]);
  $cnt2 = (int)$stmt->fetchColumn();

  if (($cnt1 + $cnt2) === 0) {
    $pdo->prepare("DELETE FROM customers_info WHERE id=?")->execute([$oldId]);
    return 'deleted_orphan';
  }
  return 'kept_has_refs';
}

/* ---------- main ---------- */
try {
  $id        = pick('GB_id', 'id');                  // single reservation id
  $groupId   = pick('Group_id', 'group_id');         // group reservation id
  $date      = pick('GB_date', 'date');
  $startTime = norm_time(pick('GB_start_time', 'start_time', 'start'));
  $endTime   = norm_time(pick('GB_end_time', 'end_time', 'end'));
  $roomsRaw  = pick('GB_room_no', 'rooms', 'room', 'room_no');
  $ip        = get_client_ip();

  // Snapshot fields (옵션)
  $name  = pick('GB_name', 'name');
  $phone = pick('GB_phone', 'phone');
  $email = pick('GB_email', 'email');

  if (!$date || !$startTime || !$endTime || (!$id && !$groupId) || !$roomsRaw) {
    http_response_code(400); echo json_encode(['success'=>false,'message'=>'bad_request']); exit;
  }
  // ---- validate start/end (00:00만 예외 허용) ----
  $toMin = fn($t) => (int)substr($t,0,2)*60 + (int)substr($t,3,2);
  $startMin = $toMin($startTime);
  $endMin   = $toMin($endTime);

  $spansNextDay = false;
  if ($endMin <= $startMin) {
    // 예외: 종료가 정확히 00:00이고 시작이 00:00이 아닐 때 → 허용(논리상 익일 24:00로 간주)
    if ($endTime === '00:00' && $startTime !== '00:00') {
      $spansNextDay = true;
    } else {
      http_response_code(400);
      echo json_encode(['success'=>false,'message'=>'end_must_be_after_start']); exit;
    }
  }

  $rooms = norm_rooms($roomsRaw);
  if (empty($rooms)) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'no_rooms']); exit; }

  // 시간 충돌 검사 (자기 자신/자기 그룹 제외)
  $phRooms = implode(',', array_fill(0, count($rooms), '?'));

  // 00:00 종료는 당일의 '마지막 시각'으로 비교되도록 파라미터만 보정
  $endForConflict = ($spansNextDay ? '23:59' : $endTime);

  $params = [$date, $startTime, $endForConflict, ...$rooms];
  if ($groupId) { $exclude = "AND (Group_id IS NULL OR Group_id <> ?)"; $params[] = $groupId; }
  else          { $exclude = "AND GB_id <> ?";                           $params[] = $id; }


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
  if ($st->fetchColumn()) { http_response_code(409); echo json_encode(['success'=>false,'message'=>'time_conflict']); exit; }

  $pdo->beginTransaction();

  $cleanup = 'kept_has_refs'; // 디폴트

  if ($groupId) {
    // 기준행(이전 customer_id 확보용)
    $st = $pdo->prepare("SELECT * FROM GB_Reservation WHERE Group_id = ? LIMIT 1");
    $st->execute([$groupId]);
    $base = $st->fetch(PDO::FETCH_ASSOC);
    if (!$base) { $pdo->rollBack(); http_response_code(400); echo json_encode(['success'=>false,'message'=>'group_not_found']); exit; }

    $oldCustomerId = (int)($base['customer_id'] ?? 0);

    // 스냅샷 보존/대체
    $name  = ($name  !== null) ? $name  : ($base['GB_name']  ?? null);
    $phone = ($phone !== null) ? $phone : ($base['GB_phone'] ?? null);
    $email = ($email !== null) ? $email : ($base['GB_email'] ?? null);

    // 새 customer_id 재매칭
    $customerId = upsert_customer_id($pdo, $name, $email, $phone);

    // 기존 그룹 삭제 후 재삽입
    $pdo->prepare("DELETE FROM GB_Reservation WHERE Group_id = ?")->execute([$groupId]);

    $ipToUse = !empty($base['GB_ip']) ? $base['GB_ip'] : $ip;
    $ins = $pdo->prepare("
      INSERT INTO GB_Reservation
        (customer_id, GB_name, GB_phone, GB_email, GB_date, GB_start_time, GB_end_time, GB_room_no, Group_id, GB_ip)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($rooms as $r) {
      $ins->execute([$customerId, $name, $phone, $email, $date, $startTime, $endTime, $r, $groupId, $ipToUse]);
    }

    // 자동 정리(merge/delete orphan)
    $cleanup = cleanup_customer_after_reassign($pdo, $oldCustomerId, (int)$customerId);

    $pdo->commit();

    // 응답
    $emailOut = $email ?? ($base['GB_email'] ?? '');
    echo json_encode([
      'success'      => true,
      'group_id'     => (string)$groupId,
      'customer_id'  => (int)$customerId,
      'cleanup'      => $cleanup, // merged | deleted_orphan | kept_has_refs
      'email'        => $emailOut
    ]);
    exit;

  } else {
    // 단일 예약
    $st = $pdo->prepare("SELECT GB_name, GB_phone, GB_email, customer_id FROM GB_Reservation WHERE GB_id = ? LIMIT 1");
    $st->execute([$id]);
    $base = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$base) { $pdo->rollBack(); http_response_code(400); echo json_encode(['success'=>false,'message'=>'not_found']); exit; }

    $oldCustomerId = (int)($base['customer_id'] ?? 0);

    $snapName  = ($name  !== null) ? $name  : ($base['GB_name']  ?? null);
    $snapPhone = ($phone !== null) ? $phone : ($base['GB_phone'] ?? null);
    $snapEmail = ($email !== null) ? $email : ($base['GB_email'] ?? null);

    $customerId = upsert_customer_id($pdo, $snapName, $snapEmail, $snapPhone);

    $room = $rooms[0];
    $sql = "
      UPDATE GB_Reservation
      SET GB_date = ?, GB_start_time = ?, GB_end_time = ?, GB_room_no = ?,
          customer_id = ?
          ".($name  !== null ? ", GB_name = ?"  : "")."
          ".($phone !== null ? ", GB_phone = ?" : "")."
          ".($email !== null ? ", GB_email = ?" : "")."
      WHERE GB_id = ?
    ";
    $bind = [$date, $startTime, $endTime, $room, $customerId];
    if ($name  !== null) $bind[] = $name;
    if ($phone !== null) $bind[] = $phone;
    if ($email !== null) $bind[] = $email;
    $bind[] = $id;
    $pdo->prepare($sql)->execute($bind);

    // 자동 정리
    $cleanup = cleanup_customer_after_reassign($pdo, $oldCustomerId, (int)$customerId);

    $pdo->commit();

    // 응답
    $emailOut = $snapEmail ?? '';
    echo json_encode([
      'success'      => true,
      'id'           => (int)$id,
      'customer_id'  => (int)$customerId,
      'cleanup'      => $cleanup, // merged | deleted_orphan | kept_has_refs
      'email'        => $emailOut
    ]);
    exit;
  }

} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  error_log("update_reservation.php response error: ".$e->getMessage());
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'Server error']);
  exit;
}
