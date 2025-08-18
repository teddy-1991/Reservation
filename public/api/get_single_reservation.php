<?php
// api/get_single_reservation.php

header('Content-Type: application/json');
require_once __DIR__.'/../includes/config.php'; // $pdo

$id = $_GET['id'] ?? null;

if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing reservation ID']);
    exit;
}

try {
    // 1. 예약 하나 조회
    $stmt = $pdo->prepare("SELECT * FROM gb_reservation WHERE GB_id = ?");
    $stmt->execute([$id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        http_response_code(404);
        echo json_encode(['error' => 'Reservation not found']);
        exit;
    }

    // 2. 같은 Group_id로 예약된 모든 방 번호 조회
    $stmt = $pdo->prepare("SELECT GB_room_no FROM GB_Reservation WHERE Group_id = ?");
    $stmt->execute([$data['Group_id']]);
    $rooms = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // 3. 기존 정보에 추가
    $data['GB_room_no'] = $rooms;

    echo json_encode($data);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server', 'details' => $e->getMessage()]);
}
