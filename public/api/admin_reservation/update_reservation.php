<?php
// /public/api/update_reservation.php (SPORTECH)
// - customers_info 연동(upsert) + merge/orphan 정리
// - 자정 넘김(익일) 허용 + DATETIME 기반 충돌 체크
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';     // $pdo
require_once __DIR__ . '/../../includes/functions.php';  // get_client_ip()

/* ---------------- helpers ---------------- */
function pick(...$keys) {
  foreach ($keys as $k) if (isset($_POST[$k])) return $_POST[$k];
  return null;
}
function norm_time($t) { // HH:MM만 유지
  $t = trim((string)$t);
  return substr($t, 0, 5);
}
function norm_rooms($val): array {
  if (is_array($val)) $arr = $val;
  else $arr = preg_split('/\s*,\s*/', (string)$val, -1, PREG_SPLIT_NO_EMPTY);
  $seen = []; $out = [];
  foreach ($arr as $v) {
    $v = (string)(int)$v;
    if ($v === '0') continue;
    if (!isset($seen[$v])) { $seen[$v]=true; $out[]=$v; }
  }
  return $out;
}
function hhmm_to_min(string $hhmm): int {
  return ((int)substr($hhmm,0,2))*60 + (int)substr($hhmm,3,2);
}
function nz($v) { if ($v === null) return null; $t = trim((string)$v); return ($t === '') ? null : $t; }

/* -------- customers_info helpers -------- */
// 이름+이메일+전화 "정확 일치(NULL-safe)"로 찾고, 없으면 생성
function upsert_customer_id(PDO $pdo, ?string $name, ?string $email, ?string $phone): int {
  $name  = nz($name);
  $email = nz($email) !== null ? strtolower(nz($email)) : null;
  $phone = nz($phone);

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

/**
 * 수정으로 customer_id가 old→new로 바뀐 경우 정리:
 * - 연락처(email/phone)가 동일하면: 참조 모두 new로 이관 후 old 삭제 (merge)
 * - 다르면: old가 고아면 삭제, 참조 있으면 유지
 * return: 'merged' | 'deleted_orphan' | 'kept_has_refs'
 */
function cleanup_customer_after_reassign(PDO $pdo, int $oldId, int $newId): string {
  if ($oldId <= 0 || $newId <= 0 || $oldId === $newId) return 'kept_has_refs';

  $q = $pdo->prepare("
    SELECT id,
           LOWER(COALESCE(email,'')) AS e,
           REGEXP_REPLACE(COALESCE(phone,''), '\\\\D', '') AS p
    FROM customers_info
    WHERE id IN (?,?)
    ORDER BY id
  ");
  $q->execute([$oldId, $newId]);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);
  if (count($rows) !== 2) return 'kept_has_refs';

  $map = [];
  foreach ($rows as $r) $map[(int)$r['id']] = ['e'=>$r['e'], 'p'=>$r['p']];
  $sameContact = ($map[$oldId]['e'] === $map[$newId]['e']) && ($map[$oldId]['p'] === $map[$newId]['p']);

  if ($sameContact) {
    // 참조 모두 new로 이관 후 old 삭제
    $pdo->prepare("UPDATE GB_Reservation SET customer_id=? WHERE customer_id=?")->execute([$newId, $oldId]);
    // 스포텍: event_registrations도 함께 이관
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

/* ---------------- main ---------------- */
try {
  // 입력
  $id        = pick('GB_id','id');                // 단일 예약 id
  $groupId   = pick('Group_id','group_id');       // 그룹 id
  $date      = pick('GB_date','date');            // YYYY-MM-DD
  $startTime = norm_time(pick('GB_start_time','start_time','start')); // HH:MM
  $endTime   = norm_time(pick('GB_end_time','end_time','end'));       // HH:MM
  $roomsRaw  = pick('GB_room_no','rooms','room','room_no');

  // 스냅샷 필드(옵션)
  $name  = pick('GB_name','name');
  $phone = pick('GB_phone','phone');
  $email = pick('GB_email','email');

  $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');

  // 기본 검증
  if (!$date || !$startTime || !$endTime || (!$id && !$groupId) || !$roomsRaw) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'bad_request']); exit;
  }
  $rooms = norm_rooms($roomsRaw);
  if (empty($rooms)) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'no_rooms']); exit;
  }

  // 시간 검증 (자정 넘김 허용: end<=start이면 익일로 간주, 단 0분 구간은 금지)
  $startMin = hhmm_to_min($startTime);
  $endMin   = hhmm_to_min($endTime);
  $spansNextDay = false;
  if ($endMin <= $startMin) {
    if ($endMin === $startMin) { // 0분
      http_response_code(400);
      echo json_encode(['success'=>false,'message'=>'end_must_be_after_start']); exit;
    }
    $spansNextDay = true; // 예: 22:00→00:30, 23:30→01:00 등
  }

    // 비교용 새 구간: 정확히 'Y-m-d H:i:s' 형태로 보정
    $newStartDT = sprintf('%s %s', $date, strlen($startTime)===5 ? $startTime.':00' : $startTime);
    $newEndDT   = sprintf('%s %s', $date, strlen($endTime)===5   ? $endTime.':00'   : $endTime);
    if ($spansNextDay) {
      $newEndDT = date('Y-m-d H:i:s', strtotime($newEndDT.' +1 day'));
    }

    /* ===== 6) 겹침 검사 (TIMESTAMP 결합 + 자정 넘김/전날 spill 보정) ===== */
    $phRooms = implode(',', array_fill(0, count($rooms), '?'));

    // 자기 자신/자기 그룹 제외 조건
    $excludeSql = '';
    $excludeParam = null;
    if ($groupId) {
      $excludeSql = "AND (t.Group_id IS NULL OR t.Group_id <> ?)";
      $excludeParam = $groupId;
    } else {
      $excludeSql = "AND t.GB_id <> ?";
      $excludeParam = $id;
    }

    $sqlConflict = "
      SELECT 1
      FROM (
        SELECT
          TIMESTAMP(r.GB_date, r.GB_start_time) AS s,
          CASE
            -- 자정(00:00) 종료이며 시작이 00:00이 아닌 경우 → 익일 종료로 해석
            WHEN r.GB_end_time = '00:00:00' AND r.GB_start_time <> '00:00:00'
              THEN TIMESTAMP(DATE_ADD(r.GB_date, INTERVAL 1 DAY), r.GB_end_time)
            -- 일반적으로 end <= start (야간跨日) → 익일 종료
            WHEN r.GB_end_time <= r.GB_start_time AND r.GB_start_time <> '00:00:00'
              THEN TIMESTAMP(DATE_ADD(r.GB_date, INTERVAL 1 DAY), r.GB_end_time)
            ELSE TIMESTAMP(r.GB_date, r.GB_end_time)
          END AS e,
          r.GB_room_no,
          r.GB_id,
          r.Group_id
        FROM GB_Reservation r
        WHERE r.GB_room_no IN ($phRooms)
          -- 당일 + 전날(자정 넘김 spill 고려)
          AND r.GB_date IN (?, DATE_SUB(?, INTERVAL 1 DAY))
      ) t
      -- 인접(끝=시작/시작=끝)은 겹침 아님 (반개구간)
      WHERE NOT (t.e <= ? OR t.s >= ?)
      $excludeSql
      LIMIT 1
    ";

    $params = array_merge($rooms, [$date, $date, $newStartDT, $newEndDT, $excludeParam]);
    $st = $pdo->prepare($sqlConflict);
    $st->execute($params);
    if ($st->fetchColumn()) {
      http_response_code(409);
      echo json_encode(['success'=>false,'message'=>'time_conflict']); exit;
    }
  // ---- 적용 ----
  $pdo->beginTransaction();

  if ($groupId) {
    // 기준행 확보 (스냅샷/아이피 보완)
    $st0 = $pdo->prepare("SELECT * FROM GB_Reservation WHERE Group_id = ? LIMIT 1");
    $st0->execute([$groupId]);
    $base = $st0->fetch(PDO::FETCH_ASSOC);
    if (!$base) {
      $pdo->rollBack();
      http_response_code(400);
      echo json_encode(['success'=>false,'message'=>'group_not_found']); exit;
    }

    $oldCustomerId = (int)($base['customer_id'] ?? 0);
    $snapName  = $name  ?? ($base['GB_name']  ?? null);
    $snapPhone = $phone ?? ($base['GB_phone'] ?? null);
    $snapEmail = $email ?? ($base['GB_email'] ?? null);
    $ipToUse   = !empty($base['GB_ip']) ? $base['GB_ip'] : $ip;

    // 새 customer_id 재매칭
    $customerId = upsert_customer_id($pdo, $snapName, $snapEmail, $snapPhone);

    // 기존 그룹 삭제 → 재삽입
    $pdo->prepare("DELETE FROM GB_Reservation WHERE Group_id = ?")->execute([$groupId]);

    $ins = $pdo->prepare("
      INSERT INTO GB_Reservation
        (customer_id, GB_name, GB_phone, GB_email,
         GB_date, GB_start_time, GB_end_time, GB_room_no, Group_id, GB_ip)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($rooms as $r) {
      $ins->execute([$customerId, $snapName, $snapPhone, $snapEmail,
                     $date, $startTime, $endTime, $r, $groupId, $ipToUse]);
    }

    // 고객 정리(merge/orphan delete)
    $cleanup = cleanup_customer_after_reassign($pdo, $oldCustomerId, (int)$customerId);

    $pdo->commit();

    // 응답
    echo json_encode([
      'success'      => true,
      'group_id'     => (string)$groupId,
      'customer_id'  => (int)$customerId,
      'cleanup'      => $cleanup, // merged | deleted_orphan | kept_has_refs
      'email'        => $snapEmail ?? ''
    ]);
    exit;

  } else {
    // 단일 예약
    $st = $pdo->prepare("SELECT GB_name, GB_phone, GB_email, customer_id FROM GB_Reservation WHERE GB_id = ? LIMIT 1");
    $st->execute([$id]);
    $base = $st->fetch(PDO::FETCH_ASSOC);
    if (!$base) {
      $pdo->rollBack();
      http_response_code(400);
      echo json_encode(['success'=>false,'message'=>'not_found']); exit;
    }

    $oldCustomerId = (int)($base['customer_id'] ?? 0);
    $snapName  = $name  !== null ? $name  : ($base['GB_name']  ?? null);
    $snapPhone = $phone !== null ? $phone : ($base['GB_phone'] ?? null);
    $snapEmail = $email !== null ? $email : ($base['GB_email'] ?? null);

    $customerId = upsert_customer_id($pdo, $snapName, $snapEmail, $snapPhone);

    $room = $rooms[0];

    // 동적 SET (스냅샷 필드 넘어온 경우에만 갱신)
    $setExtra = [];
    $bind = [$date, $startTime, $endTime, $room, $customerId];
    if ($name  !== null) { $setExtra[] = "GB_name = ?";  $bind[] = $name;  }
    if ($phone !== null) { $setExtra[] = "GB_phone = ?"; $bind[] = $phone; }
    if ($email !== null) { $setExtra[] = "GB_email = ?"; $bind[] = $email; }
    $bind[] = $id;

    $sqlUpd = "
      UPDATE GB_Reservation
      SET GB_date = ?, GB_start_time = ?, GB_end_time = ?, GB_room_no = ?,
          customer_id = ?
          ".(count($setExtra)? ", ".implode(', ',$setExtra):"")."
      WHERE GB_id = ?
    ";
    $pdo->prepare($sqlUpd)->execute($bind);

    // 고객 정리
    $cleanup = cleanup_customer_after_reassign($pdo, $oldCustomerId, (int)$customerId);

    $pdo->commit();

    echo json_encode([
      'success'      => true,
      'id'           => (int)$id,
      'customer_id'  => (int)$customerId,
      'cleanup'      => $cleanup, // merged | deleted_orphan | kept_has_refs
      'email'        => $snapEmail ?? ''
    ]);
    exit;
  }

} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  error_log("update_reservation.php (sportech) error: ".$e->getMessage());
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'server_error']);
  exit;
}
