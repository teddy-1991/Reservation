<?php
// /public/api/customer_update_reservation.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Edmonton');

require_once __DIR__ . '/../../includes/config.php';     // $pdo
require_once __DIR__ . '/../../includes/functions.php';  // validate_edit_token, upsert_edit_token_for_group, sendReservationEmail

/* helpers */
function hm(string $t): string { return substr(trim($t), 0, 5); } // "HH:MM"
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

    // 4) 시간 유효성 (같은 날, 종료>시작). 00:00은 24:00으로 간주
    [$sh,$sm] = array_map('intval', explode(':', $startTime));
    [$eh,$em] = array_map('intval', explode(':', $endTime));
    $startMin = $sh*60 + $sm;
    $endMin   = ($endTime==='00:00') ? (($startMin>0)?1440:0) : ($eh*60 + $em);
    if ($endMin <= $startMin) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'invalid_time_range']);
        exit;
    }
    $sSec = $startMin*60; $eSec = $endMin*60;

    // 5) 현재 그룹의 대표 정보 + cid 확보
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

    /* ✅ cid가 비어 있으면 3키로 복구/생성 */
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

    // 6) 겹침 검사 (요청된 모든 방에 대해)
    $confSQL = "
        SELECT 1
          FROM GB_Reservation
         WHERE GB_date = :d
           AND GB_room_no = :room
           AND Group_id <> :gid
           AND NOT (
                 (CASE WHEN GB_end_time   = '00:00' THEN 86400 ELSE TIME_TO_SEC(CONCAT(GB_end_time,   ':00')) END) <= :s
             OR  (CASE WHEN GB_start_time = '00:00' THEN 0     ELSE TIME_TO_SEC(CONCAT(GB_start_time, ':00')) END) >= :e
           )
         LIMIT 1
    ";
    $chkStmt = $pdo->prepare($confSQL);
    foreach ($rooms as $r) {
        $chkStmt->execute([
            ':d'    => $date,
            ':room' => $r,
            ':gid'  => $groupId,
            ':s'    => $sSec,
            ':e'    => $eSec
        ]);
        if ($chkStmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'conflict', 'room'=>$r]);
            exit;
        }
    }

    // 7) 트랜잭션: 기존 그룹 삭제 → 동일 group_id로 재삽입
    $pdo->beginTransaction();

    // 7-1) 기존 그룹 삭제
    $del = $pdo->prepare("DELETE FROM GB_Reservation WHERE Group_id = :gid");
    $del->execute([':gid'=>$groupId]);

    // 7-2) 재삽입(❗ customer_id 포함)
    $ins = $pdo->prepare("
        INSERT INTO GB_Reservation
            (customer_id, GB_date, GB_room_no, GB_start_time, GB_end_time,
             GB_name, GB_email, GB_phone, GB_consent, Group_id, GB_ip)
        VALUES
            (:cid, :d, :room, :s_time, :e_time,
             :name, :email, :phone, :consent, :gid, :ip)
    ");
    $ip = get_client_ip();

    foreach ($rooms as $r) {
        $ins->execute([
            ':cid'     => $cid,
            ':d'       => $date,
            ':room'    => $r,
            ':s_time'  => $startTime,
            ':e_time'  => $endTime,
            ':name'    => $toName,
            ':email'   => $toEmail,
            ':phone'   => $phone,
            ':consent' => $consent,
            ':gid'     => $groupId,
            ':ip'      => $ip
        ]);
    }

    // 7-3) 토큰 만료시각 갱신(새 시작 24h 전)
    $startDateTime = $date . ' ' . $startTime . ':00';
    $expiresAt = (new DateTime($startDateTime))->modify('-24 hours');
    upsert_edit_token_for_group($pdo, $groupId, $expiresAt);

    $pdo->commit();

    // 8) 메일 재발송
    $roomList = implode(',', $rooms);
    $subject  = 'Your reservation has been updated';
    $intro    = 'Your reservation details were updated as requested. Please review the latest details below.';

    $ok = sendReservationEmail(
        $toEmail, $toName, $date, $startTime, $endTime, $roomList,
        $subject, $intro, ['group_id'=>$groupId]
    );

    echo json_encode(['success'=>true, 'mail'=>$ok, 'group_id'=>$groupId]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success'=>false, 'error'=>'server', 'message'=>$e->getMessage()]);
}
