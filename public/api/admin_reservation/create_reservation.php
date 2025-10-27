<?php
// /public/api/create_reservation.php  (POST 전용)
declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';     // $pdo
require_once __DIR__ . '/../../includes/functions.php';  // sendReservationEmail(), get_client_ip()

/* ===== Helpers (스포텍 공통 규칙) ===== */
function norm_name($s){ $s = preg_replace('/\s+/u',' ', trim((string)$s)); return $s === '' ? null : $s; }
function norm_email($s){ $s = trim((string)$s); return $s === '' ? null : strtolower($s); }
function norm_phone($s){ $s = trim((string)$s); return $s === '' ? null : $s; }
$toMin = fn(string $hhmm) => (int)substr($hhmm,0,2)*60 + (int)substr($hhmm,3,2);

/* ===== 1) 입력 ===== */
$date      = $_POST['GB_date']       ?? null;
$rooms     = $_POST['GB_room_no']    ?? [];   // name="GB_room_no[]" → 배열
$startTime = $_POST['GB_start_time'] ?? null; // 'HH:MM'
$endTime   = $_POST['GB_end_time']   ?? null; // 'HH:MM'
$name      = $_POST['GB_name']       ?? null;
$email     = $_POST['GB_email']      ?? null;
$phone     = $_POST['GB_phone']      ?? null;
$consent   = isset($_POST['GB_consent']) ? 1 : 0;

/* rooms 정규화 */
if (!is_array($rooms)) $rooms = [$rooms];
$rooms = array_values(array_filter(array_unique(array_map('intval', $rooms)), fn($v) => $v > 0));

/* ===== 2) 1차 검증 ===== */
if (!$date || empty($rooms) || !$startTime || !$endTime || !$name || !$email) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'missing', 'fields' => [
        'date' => (bool)$date, 'rooms' => !empty($rooms), 'start' => (bool)$startTime,
        'end' => (bool)$endTime, 'name' => (bool)$name, 'email' => (bool)$email
    ]]);
    exit;
}

/* ===== 3) 시간 검증/보정: 자정 이후(익일) 허용 ===== */
$startTime = substr((string)$startTime, 0, 5); // 'HH:MM'
$endTime   = substr((string)$endTime,   0, 5);

$startMin = $toMin($startTime);
$endMin   = $toMin($endTime);

// 시작=끝=00:00 → 0분 예약 금지
if ($startMin === 0 && $endMin === 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'invalid_time_range']);
    exit;
}

// 종료가 시작보다 같거나 이하면 익일(+24h)로 간주 (예: 23:30→01:00)
$spansNextDay = false;
if ($endMin <= $startMin) {
    if ($endMin === $startMin) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'invalid_time_range']);
        exit;
    }
    $spansNextDay = true;
}

// 비교용 DATETIME (신규 예약 구간)
$newStartDT = $date . ' ' . $startTime . ':00';
$newEndDT   = $date . ' ' . $endTime   . ':00';
if ($spansNextDay) {
    $newEndDT = date('Y-m-d H:i:s', strtotime($newEndDT . ' +1 day'));
}

try {
    $groupId  = uniqid('', true); // 그룹 ID
    $clientIp = get_client_ip();


    /* ===== 5) 고객 식별/생성 (customers_info) ===== */
    $normName  = norm_name($name);
    $normEmail = norm_email($email);
    $normPhone = norm_phone($phone);

    $findSql = "
        SELECT id
          FROM customers_info
         WHERE full_name <=> :name
           AND email     <=> :email
           AND phone     <=> :phone
         LIMIT 1
    ";
    $findStmt = $pdo->prepare($findSql);
    $findStmt->execute([
        ':name'  => $normName,
        ':email' => $normEmail,
        ':phone' => $normPhone,
    ]);
    $customerId = (int)($findStmt->fetchColumn() ?: 0);

    if (!$customerId) {
        $insCust = "
            INSERT INTO customers_info (full_name, email, phone, created_at, updated_at)
            VALUES (:name, :email, :phone, NOW(), NOW())
        ";
        $st = $pdo->prepare($insCust);
        $st->execute([
            ':name'  => $normName,
            ':email' => $normEmail,
            ':phone' => $normPhone,
        ]);
        $customerId = (int)$pdo->lastInsertId();
    }

    /* ===== 6) 겹침 검사 (DATETIME 방식, 자정 넘김 행 보정) ===== */
    // 선택한 모든 방을 한 번에 검사 (같은 날짜만 대상으로 함)
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
          r.GB_room_no
        FROM GB_Reservation r
        WHERE r.GB_date = ? AND r.GB_room_no IN ($phRooms)
      ) t
      WHERE NOT (t.e <= ? OR t.s >= ?)
      LIMIT 1
    ";

    $params = array_merge([$date], $rooms, [$newStartDT, $newEndDT]);
    $stc = $pdo->prepare($sqlConflict);
    $stc->execute($params);
    if ($stc->fetchColumn()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'conflict']);
        exit;
    }

    /* ===== 7) 트랜잭션: 예약 삽입 ===== */
    $pdo->beginTransaction();

    $insertSQL = "
        INSERT INTO GB_Reservation
            (customer_id, GB_date, GB_room_no, GB_start_time, GB_end_time,
             GB_name, GB_email, GB_phone, GB_consent, Group_id, GB_ip)
        VALUES
            (:customer_id, :d, :room, :s_time, :e_time,
             :name, :email, :phone, :consent, :gid, :ip)
    ";
    $insStmt = $pdo->prepare($insertSQL);

    foreach ($rooms as $room) {
        $insStmt->execute([
            ':customer_id' => $customerId,
            ':d'           => $date,
            ':room'        => $room,
            ':s_time'      => $startTime,   // DB에는 'HH:MM' 그대로 저장
            ':e_time'      => $endTime,     // 자정(익일) 여부는 조회 시 보정
            ':name'        => $name,
            ':email'       => $email,
            ':phone'       => $phone,
            ':consent'     => $consent,
            ':gid'         => $groupId,
            ':ip'          => $clientIp
        ]);
    }

    $pdo->commit();

    /* ===== 8) 확인 메일 발송 (실패해도 예약 성공) ===== */
    $subject    = 'Your Sportech Indoor Golf Reservation';
    $roomList   = implode(',', $rooms);
    $mailStatus = true;
    try {
        sendReservationEmail(
            $email, $name, $date,
            substr($startTime, 0, 5),
            substr($endTime,   0, 5),
            $roomList,
            $subject,
            '',
            ['group_id' => (string)$groupId]
        );
    } catch (Throwable $mailEx) {
        $mailStatus = false;
        $mailError  = $mailEx->getMessage();
        error_log('[create_reservation:mail] ' . $mailEx->getMessage());
    }

    /* (선택) 토큰 디버그 */
    $token_debug = [];
    try {
        $stmt = $pdo->prepare("
            SELECT action, expires_at, used_at
              FROM reservation_tokens
             WHERE group_id = :g
             ORDER BY id DESC
             LIMIT 5
        ");
        $stmt->execute([':g' => (string)$groupId]);
        $token_debug = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $token_debug = [['error' => $e->getMessage()]];
    }

    echo json_encode([
        'success'     => true,
        'group_id'    => $groupId,
        'customer_id' => $customerId,
        'mail'        => $mailStatus,
        'mail_error'  => $mailError ?? null,
        'token_debug' => $token_debug
    ]);
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[create_reservation] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server', 'details' => $e->getMessage()]);
    exit;
}
