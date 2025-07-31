<?php
// api/get_reserved_times.php

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';   // $pdo

$date = $_GET['date'] ?? null;

// rooms 파라미터 처리 (rooms=1,2,3 또는 room=4 지원)
if (isset($_GET['rooms'])) {
    $roomArr = array_map('intval', explode(',', $_GET['rooms']));
} elseif (isset($_GET['room'])) {
    $roomArr = [ (int) $_GET['room'] ];
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Missing room(s) parameter']);
    exit;
}

if (!$date || empty($roomArr)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameters (date or room)']);
    exit;
}

try {
    $placeholders = rtrim(str_repeat('?,', count($roomArr)), ',');

    $sql = "
        SELECT GB_room_no    AS room_no,
               GB_start_time AS start_time,
               GB_end_time   AS end_time,
               GB_name, GB_phone, GB_email, GB_id, Group_id
          FROM gb_reservation
         WHERE GB_date = ?
           AND GB_room_no IN ($placeholders)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$date], $roomArr));
    $results = $stmt->fetchAll();

    echo json_encode($results);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server', 'details' => $e->getMessage()]);
}
