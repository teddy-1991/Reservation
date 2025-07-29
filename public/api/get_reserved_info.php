<?php
// api/get_reserved_times.php
// usage examples:
//   /api/get_reserved_times.php?date=2025-07-11&rooms=1,2,3
//   /api/get_reserved_times.php?date=2025-07-11&room=4

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';   // $pdo

$date = $_GET['date'] ?? null;

/* rooms 파라미터 처리: 다중 rooms=1,2,3 또는 단일 room=4 모두 지원 */
if (isset($_GET['rooms'])) {
    $roomArr = array_map('intval', explode(',', $_GET['rooms']));
} elseif (isset($_GET['room'])) {
    $roomArr = [ (int) $_GET['room'] ];
} else {
    http_response_code(400);
    echo json_encode(['error' => 'room(s) param missing']);
    exit;
}

if (!$date || empty($roomArr)) {
    http_response_code(400);
    echo json_encode(['error' => 'date and rooms are required']);
    exit;
}

$placeH = rtrim(str_repeat('?,', count($roomArr)), ',');

$sql = "
    SELECT GB_room_no    AS room_no,
           GB_start_time AS start_time,
           GB_end_time   AS end_time,
           GB_name, GB_phone, GB_email
      FROM gb_reservation
     WHERE GB_date = ?
       AND GB_room_no IN ($placeH)
";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$date], $roomArr));

echo json_encode($stmt->fetchAll());