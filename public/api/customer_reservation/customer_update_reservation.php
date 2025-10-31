<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Edmonton');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

/* helpers */
function hm(string $t): string { return substr(trim($t), 0, 5); }
function toMin(string $hhmm): int { return (int)substr($hhmm,0,2)*60 + (int)substr($hhmm,3,2); }
function norm_name($s){ $s = preg_replace('/\s+/u',' ', trim((string)$s)); return $s === '' ? null : $s; }
function norm_email($s){ $s = trim((string)$s); return $s === '' ? null : strtolower($s); }
function norm_phone($s){ $s = trim((string)$s); return $s === '' ? null : $s; }
function dbg($label, $data = null): void {
    static $n = 0; $n++;
    $val = is_scalar($data) ? (string)$data : json_encode($data, JSON_UNESCAPED_UNICODE);
    header("X-D{$n}-{$label}: {$val}", false);
}

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    // 1) 입력 (가장 먼저!)
    $token     = (string)($_POST['token']      ?? '');
    $date      = (string)($_POST['date']       ?? '');
    $startTime = (string)($_POST['start_time'] ?? '');
    $endTime   = (string)($_POST['end_time']   ?? '');
    $roomsCsv  = (string)($_POST['rooms_csv']  ?? '');

    dbg('start', ['post'=>$_POST]);

    if ($token === '') {
        http_response_code(400);
        echo json_encode(['success'=>false, 'error'=>'missing_token']); exit;
    }

    // 2) 토큰 검증
    $chk = validate_edit_token($pdo, $token);
    dbg('token.checked', $chk['ok'] ?? false);
    if (!($chk['ok'] ?? false)) {
        http_response_code(400);
        echo json_encode(['success'=>false, 'error'=>$chk['code'] ?? 'invalid_token']); exit;
    }
    $groupId = (string)$chk['group_id'];
    dbg('groupId', $groupId);

    // 3) 파라미터 검증/정규화
    $date      = trim($date);
    $startTime = hm($startTime);
    $endTime   = hm($endTime);
    $roomsCsv  = trim($roomsCsv);

    dbg('params', compact('date','startTime','endTime','roomsCsv'));

    if ($date === '' || $startTime === '' || $endTime === '' || $roomsCsv === '') {
        http_response_code(422);
        echo json_encode(['success'=>false,'error'=>'missing_params']); exit;
    }

    dbg('rooms.before', $roomsCsv);
    $rooms = array_values(array_filter(
        array_unique(array_map('intval', explode(',', $roomsCsv))),
        fn($v)=>$v>0
    ));
    dbg('rooms.after', $rooms);
    if (empty($rooms)) {
        http_response_code(422);
        echo json_encode(['success'=>false,'error'=>'invalid_rooms']); exit;
    }

    // 4) 시간 계산 (자정 넘김 허용)
    $startMin = toMin($startTime);
    $endMin   = toMin($endTime);
    $spansNextDay = ($endMin <= $startMin) && ($endMin !== $startMin);
    dbg('time', compact('startMin','endMin','spansNextDay'));

    $newStartDT = $date.' '.$startTime.':00';
    $newEndDT   = $spansNextDay
        ? date('Y-m-d H:i:s', strtotime($date.' '.$endTime.':00 +1 day'))
        : $date.' '.$endTime.':00';
    dbg('range', compact('newStartDT','newEndDT'));

    // 5) 대표 정보 + cid
    $stmt = $pdo->prepare("
        SELECT GB_name AS name, GB_email AS email, GB_phone AS phone,
               GB_consent AS consent, MIN(customer_id) AS cid
        FROM GB_Reservation WHERE Group_id = :gid LIMIT 1
    ");
    $stmt->execute([':gid'=>$groupId]);
    $head = $stmt->fetch(PDO::FETCH_ASSOC);
    dbg('head.found', !!$head);
    if (!$head) {
        http_response_code(404);
        echo json_encode(['success'=>false,'error'=>'group_not_found']); exit;
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

        $fx = $pdo->prepare("SELECT id FROM customers_info
                             WHERE full_name <=> :n AND email <=> :e AND phone <=> :p
                             LIMIT 1");
        $fx->execute([':n'=>$nName, ':e'=>$nEmail, ':p'=>$nPhone]);
        $cid = (int)($fx->fetchColumn() ?: 0);

        if (!$cid) {
            $ix = $pdo->prepare("INSERT INTO customers_info (full_name,email,phone)
                                 VALUES (:n,:e,:p)");
            $ix->execute([':n'=>$nName, ':e'=>$nEmail, ':p'=>$nPhone]);
            $cid = (int)$pdo->lastInsertId();
        }
    }
    dbg('cid.final', $cid);

    // 6) 겹침 검사
    $phRooms = implode(',', array_fill(0, count($rooms), '?'));
    $sqlConflict = "
      SELECT 1 FROM (
        SELECT
          CONCAT(r.GB_date, ' ', r.GB_start_time, ':00') AS s,
          CASE
            WHEN TIME_TO_SEC(r.GB_end_time) <= TIME_TO_SEC(r.GB_start_time)
                 AND TIME_TO_SEC(r.GB_start_time) <> 0
              THEN DATE_ADD(CONCAT(r.GB_date, ' ', r.GB_end_time, ':00'), INTERVAL 1 DAY)
            ELSE CONCAT(r.GB_date, ' ', r.GB_end_time, ':00')
          END AS e,
          r.GB_room_no, r.Group_id
        FROM GB_Reservation r
        WHERE r.GB_room_no IN ($phRooms)
      ) t
      WHERE NOT (t.e <= ? OR t.s >= ?)
        AND t.Group_id <> ?
      LIMIT 1
    ";
    $params = [...$rooms, $newStartDT, $newEndDT, $groupId];
    $stc = $pdo->prepare($sqlConflict);
    dbg('conflict.range', [$newStartDT, $newEndDT]);
    $stc->execute($params);
    $hasConflict = (bool)$stc->fetchColumn();
    dbg('conflict.result', $hasConflict);
    if ($hasConflict) {
        http_response_code(409);
        echo json_encode(['success'=>false,'error'=>'conflict']); exit;
    }

    // 7) 트랜잭션: 삭제→재삽입
    $pdo->beginTransaction();
    dbg('tx', 'begin');

    $del = $pdo->prepare("DELETE FROM GB_Reservation WHERE Group_id = :gid");
    $del->execute([':gid'=>$groupId]);
    dbg('deleted.old', $del->rowCount());

    $ins = $pdo->prepare("
        INSERT INTO GB_Reservation
            (customer_id, GB_date, GB_room_no, GB_start_time, GB_end_time,
             GB_name, GB_email, GB_phone, GB_consent, Group_id, GB_ip)
        VALUES
            (:cid,:d,:room,:s_time,:e_time,:name,:email,:phone,:consent,:gid,:ip)
    ");
    $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');

    foreach ($rooms as $r) {
        $ins->execute([
            ':cid'=>$cid, ':d'=>$date, ':room'=>$r,
            ':s_time'=>$startTime, ':e_time'=>$endTime,
            ':name'=>$toName, ':email'=>$toEmail, ':phone'=>$phone,
            ':consent'=>$consent, ':gid'=>$groupId, ':ip'=>$ip
        ]);
    }
    dbg('inserted.count', count($rooms));

    // 8) 토큰 만료 갱신
    $expiresAt = (new DateTime($newStartDT))->modify('-24 hours');
    try {
        upsert_edit_token_for_group($pdo, $groupId, $expiresAt);
        dbg('token.upsert', 'ok');
    } catch (Throwable $tx) {
        dbg('token.upsert.error', $tx->getMessage());
        // 계속 진행 (예약은 이미 성공)
    }

    $pdo->commit();
    dbg('tx', 'commit');

    // 9) 메일 재발송 (실패해도 500로 번지지 않게)
    $roomList = implode(',', $rooms);
    $subject  = 'Your reservation has been updated';
    $intro    = 'Your reservation details were updated as requested. Please review the latest details below.';
    $mailOk = false;
    try {
        $mailOk = sendReservationEmail(
            $toEmail, $toName, $date, $startTime, $endTime, $roomList,
            $subject, $intro, ['group_id'=>$groupId, 'rooms_csv'=>$roomList]
        );
        dbg('mail', $mailOk ? 'sent' : 'fail');
    } catch (Throwable $mx) {
        dbg('mail.error', $mx->getMessage());
    }

    echo json_encode(['success'=>true, 'mail'=>$mailOk, 'group_id'=>$groupId]); exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        'success'=>false,'error'=>'server',
        'message'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()
    ]); exit;
}
