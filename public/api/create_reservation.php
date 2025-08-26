<?php
// /public/api/create_reservation.php  (POST 전용)
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../includes/config.php';     // $pdo
require_once __DIR__ . '/../includes/functions.php';  // sendReservationEmail()

// 1) POST 데이터 수집
$date      = $_POST['GB_date']       ?? null;
$rooms     = $_POST['GB_room_no']    ?? [];   // name="GB_room_no[]" → 배열
$startTime = $_POST['GB_start_time'] ?? null;
$endTime   = $_POST['GB_end_time']   ?? null;
$name      = $_POST['GB_name']       ?? null;
$email     = $_POST['GB_email']      ?? null;
$phone     = $_POST['GB_phone']      ?? null;
$consent   = isset($_POST['GB_consent']) ? 1 : 0;

// rooms 정규화
if (!is_array($rooms)) $rooms = [$rooms];
$rooms = array_values(array_filter(array_unique(array_map('intval', $rooms)), fn($v) => $v > 0));

// 2) 1차 검증 (필수값)
if (!$date || empty($rooms) || !$startTime || !$endTime || !$name || !$email) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'missing', 'fields' => [
        'date' => (bool)$date, 'rooms' => !empty($rooms), 'start' => (bool)$startTime,
        'end' => (bool)$endTime, 'name' => (bool)$name, 'email' => (bool)$email
    ]]);
    exit;
}

// 3) 시간 검증: 같은 날 예약만 허용 + 종료 00:00은 24:00으로 간주
$startTime = substr((string)$startTime, 0, 5);
$endTime   = substr((string)$endTime,   0, 5);

[$sh, $sm] = array_map('intval', explode(':', $startTime));
[$eh, $em] = array_map('intval', explode(':', $endTime));
$startMin  = $sh * 60 + $sm;

// 종료가 00:00이면 24:00으로 (단, 시작도 00:00이면 0분이라 금지)
if ($endTime === '00:00') {
    $endMin = ($startMin > 0) ? 1440 : 0;
} else {
    $endMin = $eh * 60 + $em;
}

// 같은 날 규칙: 종료가 시작보다 커야 함
if ($endMin <= $startMin) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'invalid_time_range']);
    exit;
}

// 초 단위(겹침 체크에 사용)
$sSec = $startMin * 60;
$eSec = $endMin   * 60;

try {
    $pdo->beginTransaction();
    $groupId = uniqid('', true); // 그룹 ID
    $clientIp = get_client_ip();

    // ※ 문자열 비교 대신 "초 단위" 비교: DB의 HH:MM은 TIME_TO_SEC로 변환
    $checkSQL = "
        SELECT 1
          FROM GB_Reservation
         WHERE GB_date = :d
           AND GB_room_no = :room
           AND NOT (
                 (CASE WHEN GB_end_time   = '00:00' THEN 86400 ELSE TIME_TO_SEC(CONCAT(GB_end_time,   ':00')) END) <= :s
             OR  (CASE WHEN GB_start_time = '00:00' THEN 0     ELSE TIME_TO_SEC(CONCAT(GB_start_time, ':00')) END) >= :e
           )
         LIMIT 1
    ";

    $insertSQL = "
        INSERT INTO GB_Reservation
            (GB_date, GB_room_no, GB_start_time, GB_end_time,
             GB_name, GB_email, GB_phone, GB_consent, Group_id, GB_ip)
        VALUES
            (:d, :room, :s_time, :e_time, :name, :email, :phone, :consent, :gid, :ip)
    ";

    $chkStmt = $pdo->prepare($checkSQL);
    $insStmt = $pdo->prepare($insertSQL);

    foreach ($rooms as $room) {
        // 겹침 체크 (초 단위 비교)
        $chkStmt->execute([
            ':d'    => $date,
            ':room' => $room,
            ':s'    => $sSec,
            ':e'    => $eSec,
        ]);
        if ($chkStmt->fetch()) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error'   => 'conflict',
                'message' => "Room {$room} already booked in that time range"
            ]);
            exit;
        }

        // 삽입 (DB에는 기존 포맷 그대로 'HH:MM' 저장, 종료가 00:00이면 '00:00' 그대로)
        $insStmt->execute([
            ':d'      => $date,
            ':room'   => $room,
            ':s_time' => $startTime,
            ':e_time' => $endTime,
            ':name'   => $name,
            ':email'  => $email,
            ':phone'  => $phone,
            ':consent'=> $consent,
            ':gid'    => $groupId,
            ':ip'     => $clientIp
        ]);
    }

    $pdo->commit();

    // 메일 전송(트랜잭션 밖, 실패해도 예약은 성공)
    $mailStatus = true;
    try {
        if (function_exists('sendReservationEmail')) {
            $roomList = implode(',', $rooms);
            sendReservationEmail($email, $name, $date, $startTime, $endTime, $roomList);
        }
    } catch (Throwable $mailEx) {
        $mailStatus = false;
        error_log('[create_reservation:mail] ' . $mailEx->getMessage());
    }

    echo json_encode(['success' => true, 'group_id' => $groupId, 'mail' => $mailStatus]);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[create_reservation] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server', 'details' => $e->getMessage()]);
    exit;
}