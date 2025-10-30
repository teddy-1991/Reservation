<?php
// /public/api/customer_update_reservation.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Edmonton');

require_once __DIR__ . '/../../includes/config.php';     // $pdo
require_once __DIR__ . '/../../includes/functions.php';  // validate_edit_token, upsert_edit_token_for_group, sendReservationEmail

/* helpers */
function hm(string $t): string { return substr(trim($t), 0, 5); } // "HH:MM"
function toMin(string $hhmm): int { return (int)substr($hhmm,0,2)*60 + (int)substr($hhmm,3,2); }
function norm_name($s){ $s = preg_replace('/\s+/u',' ', trim((string)$s)); return $s === '' ? null : $s; }
function norm_email($s){ $s = trim((string)$s); return $s === '' ? null : strtolower($s); }
function norm_phone($s){ $s = trim((string)$s); return $s === '' ? null : $s; }

try {
    // 1) 입력
    $token     = $_POST['token']      ?? '';
    $date      = $_POST['date']       ?? '';
    $startTime = $_POST['start_time'] ?? '';
    $endTime   = $_POST['end_time']   ?? '';
    $roomsCsv  = $_POST['rooms_csv']  ?? '';

    // 2) 토큰 검증
    $chk = validate_edit_token($pdo, (string)$token);
    if (!($chk['ok'] ?? false)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $chk['code'] ?? 'invalid_token']);
        exit;
    }
    $groupId = (string)$chk['group_id'];

    // 3) 파라미터 검증
    $date      = trim((string)$date);
    $startTime = hm((string)$startTime);
    $endTime   = hm((string)$endTime);

    if ($date === '' || $startTime === '' || $endTime === '' || trim((string)$roomsCsv) === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'missing_params']);
        exit;
    }

    // roomsCsv -> 배열 (양수만)
    $rooms = array_values(array_filter(array_unique(array_map('intval', explode(',', $roomsCsv))), fn($v)=>$v>0));
    if (empty($rooms)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'invalid_rooms']);
        exit;
    }

    // 4) 시간 유효성 (버츄어 로직처럼 자정 넘김 허용)
    $startMin = toMin($startTime);
    $endMin   = toMin($endTime);
    $spansNextDay = false;

    if ($endMin <= $startMin) {
        if ($endMin === $startMin) { // 0분 예약 금지
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'invalid_time_range']);
            exit;
        }
        $spansNextDay = true; // 예: 23:00→00:30, 22:00→01:00, 22:00→00:00 등(익일)
    }

    // 비교용 구간 (DATETIME)
    $newStartDT = $date . ' ' . $startTime . ':00';
    $newEndDT   = $date . ' ' . $endTime   . ':00';
    if ($spansNextDay) {
        $newEndDT = date('Y-m-d H:i:s', strtotime($newEndDT . ' +1 day'));
    }

    // 5) 현재 그룹의 대표 정보 + cid 확보 (스포텍 유지)
    $stmt = $pdo->prepare("
        SELECT
          GB_name AS name,
          GB_email AS email,
          GB_phone AS phone,
          GB_consent AS consent,
          MIN(customer_id) AS cid
        FROM GB_Reservation
        WHERE Group_id = :gid
        LIMIT 1
    ");
    $stmt->execute([':gid'=>$groupId]);
    $head = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$head) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'group_not_found']);
        exit;
    }
    $toName  = (string)($head['name']  ?? '');
    $toEmail = (string)($head['email'] ?? '');
    $phone   = $head['phone']   ?? null;
    $consent = (int)($head['consent'] ?? 0);
    $cid     = isset($head['cid']) && $head['cid'] !== null ? (int)$head['cid'] : 0;

    if (!$cid) {
        $nName  = norm_name($toName);
        $nEmail = norm_email($toEmail);
        $nPhone = norm_phone($phone);

        $fx = $pdo->prepare("
            SELECT id FROM customers_info
            WHERE full_name <=> :n AND email <=> :e AND phone <=> :p
            LIMIT 1
        ");
        $fx->execute([':n'=>$nName, ':e'=>$nEmail, ':p'=>$nPhone]);
        $cid = (int)($fx->fetchColumn() ?: 0);

        if (!$cid) {
            $ix = $pdo->prepare("INSERT INTO customers_info (full_name, email, phone) VALUES (:n,:e,:p)");
            $ix->execute([':n'=>$nName, ':e'=>$nEmail, ':p'=>$nPhone]);
            $cid = (int)$pdo->lastInsertId();
        }
    }

    // 6) 겹침 검사 (버츄어 방식: 날짜 제한 없이 DATETIME 구간 비교)
    $phRooms = implode(',', array_fill(0, count($rooms), '?'));
    $sqlConflict = "
      SELECT 1
      FROM (
        SELECT
          CONCAT(r.GB_date, ' ', r.GB_start_time, ':00') AS s,
          CASE
            WHEN TIME_TO_SEC(r.GB_end_time) <= TIME_TO_SEC(r.GB_start_time)
                 AND TIME_TO_SEC(r.GB_start_time) <> 0
              THEN DATE_ADD(CONCAT(r.GB_date, ' ', r.GB_end_time, ':00'), INTERVAL 1 DAY)
            ELSE CONCAT(r.GB_date, ' ', r.GB_end_time, ':00')
          END AS e,
          r.GB_room_no,
          r.Group_id
        FROM GB_Reservation r
        WHERE r.GB_room_no IN ($phRooms)
      ) t
      WHERE NOT (t.e <= ? OR t.s >= ?)
        AND t.Group_id <> ?
      LIMIT 1
    ";
    $params = [...$rooms, $newStartDT, $newEndDT, $groupId];
    $stc = $pdo->prepare($sqlConflict);
    $stc->execute($params);
    if ($stc->fetchColumn()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'conflict']);
        exit;
    }

    // 7) 트랜잭션: 기존 그룹 삭제 → 동일 group_id로 재삽입 (스포텍: customer_id 유지)
    $pdo->beginTransaction();

    $del = $pdo->prepare("DELETE FROM GB_Reservation WHERE Group_id = :gid");
    $del->execute([':gid'=>$groupId]);

    $ins = $pdo->prepare("
        INSERT INTO GB_Reservation
            (customer_id, GB_date, GB_room_no, GB_start_time, GB_end_time,
             GB_name, GB_email, GB_phone, GB_consent, Group_id, GB_ip)
        VALUES
            (:cid, :d, :room, :s_time, :e_time,
             :name, :email, :phone, :consent, :gid, :ip)
    ");
    $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');

    foreach ($rooms as $r) {
        $ins->execute([
            ':cid'     => $cid,
            ':d'       => $date,
            ':room'    => $r,
            ':s_time'  => $startTime, // 저장은 원 포맷 그대로('00:00' 허용)
            ':e_time'  => $endTime,
            ':name'    => $toName,
            ':email'   => $toEmail,
            ':phone'   => $phone,
            ':consent' => $consent,
            ':gid'     => $groupId,
            ':ip'      => $ip
        ]);
    }

    // 8) 토큰 만료시각 갱신(새 시작 24h 전)
    $expiresAt = (new DateTime($newStartDT))->modify('-24 hours');
    upsert_edit_token_for_group($pdo, $groupId, $expiresAt);

    $pdo->commit();

    // 9) 메일 재발송
    $roomList = implode(',', $rooms);
    $subject  = 'Your reservation has been updated';
    $intro    = 'Your reservation details were updated as requested. Please review the latest details below.';
    $mailOk   = sendReservationEmail(
        $toEmail, $toName, $date, $startTime, $endTime, $roomList,
        $subject, $intro, ['group_id'=>$groupId]
    );

    echo json_encode(['success'=>true, 'mail'=>$mailOk, 'group_id'=>$groupId]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success'=>false, 'error'=>'server', 'message'=>$e->getMessage()]);
}
