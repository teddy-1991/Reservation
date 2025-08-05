<?php
// api/update_group_reservation.php

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

$groupId        = $_POST['Group_id'] ?? null;
$date           = $_POST['GB_date'] ?? null;
$startTime      = $_POST['GB_start_time'] ?? null;
$endTime        = $_POST['GB_end_time'] ?? null;
$name           = $_POST['GB_name'] ?? null;
$email          = $_POST['GB_email'] ?? null;
$phone          = $_POST['GB_phone'] ?? null;

if (!$groupId || !$date || !$startTime || !$endTime || !$name || !$phone) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    // 1. 기존 예약 삭제
    $stmt = $pdo->prepare("DELETE FROM GB_Reservation WHERE Group_id = ?");
    $stmt->execute([$groupId]);

    // 2. 새로 예약 정보 insert (방마다 하나씩)
    $rooms = $_POST['GB_room_no'] ?? [];
    if (!is_array($rooms)) $rooms = [$rooms];

    $stmt = $pdo->prepare("
        INSERT INTO GB_Reservation 
        (GB_date, GB_start_time, GB_end_time, GB_name, GB_email, GB_phone, GB_room_no, Group_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($rooms as $room) {
        $stmt->execute([
            $date, $startTime, $endTime, $name, $email, $phone, $room, $groupId
        ]);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Update failed']);
}