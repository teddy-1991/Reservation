<?php
// /public/api/create_reservation.php  (POST 전용)
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../includes/config.php';     // $pdo
// 메일 함수가 여기 있으면 include, 없으면 주석 처리
require_once __DIR__ . '/../includes/functions.php';  // sendReservationEmail()

// 1) POST 데이터 수집
$date      = $_POST['GB_date']       ?? null;
$rooms     = $_POST['GB_room_no']    ?? [];   // name="GB_room_no[]" 인 경우 배열로 들어옴
$startTime = $_POST['GB_start_time'] ?? null;
$endTime   = $_POST['GB_end_time']   ?? null;
$name      = $_POST['GB_name']       ?? null;
$email     = $_POST['GB_email']      ?? null;
$phone     = $_POST['GB_phone']      ?? null;
$consent   = isset($_POST['GB_consent']) ? 1 : 0;

// rooms 정규화
if (!is_array($rooms)) $rooms = [$rooms];
$rooms = array_values(array_filter(array_unique(array_map('intval', $rooms)), fn($v) => $v > 0));

// 2) 1차 검증
if (!$date || empty($rooms) || !$startTime || !$endTime || !$name || !$email) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'missing', 'fields' => [
        'date' => (bool)$date, 'rooms' => !empty($rooms), 'start' => (bool)$startTime,
        'end' => (bool)$endTime, 'name' => (bool)$name, 'email' => (bool)$email
    ]]);
    exit;
}

try {
    $pdo->beginTransaction();
    $groupId = uniqid('', true); // 그룹 ID

    // 호스팅/엔진에 따라 FOR UPDATE가 문제될 수 있으므로 플래그로 제어
    $useForUpdate = true; // 문제 생기면 false로 바꿔 테스트

    $checkSQL = "
        SELECT 1
          FROM GB_Reservation
         WHERE GB_date = ?
           AND GB_room_no = ?
           AND NOT (? >= GB_end_time OR ? <= GB_start_time)
         LIMIT 1
    ";

    $insertSQL = "
        INSERT INTO GB_Reservation
            (GB_date, GB_room_no, GB_start_time, GB_end_time,
             GB_name, GB_email, GB_phone, GB_consent, Group_id)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $chkStmt = $pdo->prepare($checkSQL);
    $insStmt = $pdo->prepare($insertSQL);

    foreach ($rooms as $room) {
        // 겹침 체크
        $chkStmt->execute([$date, $room, $startTime, $endTime]);
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

        // 삽입
        $insStmt->execute([
            $date, $room, $startTime, $endTime,
            $name, $email, $phone, $consent, $groupId
        ]);
    }

    $pdo->commit();

    // 🎯 메일 전송은 트랜잭션 밖에서, 실패해도 예약은 성공 처리
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
    // 프론트 안깨지게 200으로 내려도 되지만, 여기선 명확히 500 유지
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server', 'details' => $e->getMessage()]);
    exit;
}