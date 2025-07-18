<?php
// api/create_reservation.php  (POST 전용)
header('Content-Type: application/json');
require_once __DIR__.'/../includes/config.php';    // $pdo

// 1) POST 데이터 수집
$date           = $_POST['GB_date']         ?? null;
$rooms          = $_POST['GB_room_no']      ?? [];

if (!is_array($rooms)) {
    $rooms = [$rooms];
}

$rooms = array_filter(array_unique($rooms), 'strlen');


$startTime      = $_POST['GB_start_time']   ?? null;
$endTime        = $_POST['GB_end_time']     ?? null;
$numGuests      = $_POST['GB_num_guests']   ?? null;
$hand           = $_POST['GB_preferred_hand'] ?? null;
$name           = $_POST['GB_name']         ?? null;
$email          = $_POST['GB_email']        ?? null;
$phone          = $_POST['GB_phone']        ?? null;
$consent        = isset($_POST['GB_consent']) ? 1 : 0;

// 2) 1차 검증
if (!$date || !$rooms || !$startTime || !$endTime || !$name || !$email) {
    http_response_code(422);
    echo json_encode(['error'=>'missing required fields']);
    exit;
}

try {
    $pdo->beginTransaction();

    /* 3) 범위 겹침 검사 + 삽입 (방마다 반복) */
    $checkSQL = "
        SELECT 1
          FROM gb_reservation
         WHERE GB_date     = ?
           AND GB_room_no  = ?
           AND NOT ( ? >= GB_end_time OR ? <= GB_start_time )
         LIMIT 1
         FOR UPDATE
    ";

    $insertSQL = "
        INSERT INTO gb_reservation
        ( GB_date, GB_room_no, GB_start_time, GB_end_time,
          GB_num_guests, GB_preferred_hand, GB_name, GB_email,
          GB_phone, GB_consent )
        VALUES (?,?,?,?,?,?,?,?,?,?)
    ";

    $chkStmt = $pdo->prepare($checkSQL);
    $insStmt = $pdo->prepare($insertSQL);

    foreach ($rooms as $room) {
        // 겹침 여부 확인
        $chkStmt->execute([$date, $room, $startTime, $endTime]);
        if ($chkStmt->fetch()) {
            // 이미 예약이 존재 → 전체 롤백 후 409
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode([
                'error'   => 'conflict',
                'message' => "Room $room already booked in that time range"
            ]);
            exit;
        }

        // 겹침 없으면 삽입
        $insStmt->execute([
            $date, $room, $startTime, $endTime,
            $numGuests, $hand, $name, $email,
            $phone, $consent
        ]);
    }

    $pdo->commit();

    require_once __DIR__. '/../includes/functions.php';

    sendReservationEmail($email, $name, $date, $startTime, $endTime, implode(',', $rooms));
    echo json_encode(["success" => true]);
    exit;
    

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error'=>'server', 'details'=>$e->getMessage()]);
}