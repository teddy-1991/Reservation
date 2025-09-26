<?php
// api/get_reserved_times.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';   // provides $pdo

$date = $_GET['date'] ?? null;

// rooms 파라미터 처리 (rooms=1,2,3 또는 room=4 지원)
$roomArr = [];
if (isset($_GET['rooms']) && $_GET['rooms'] !== '') {
    $roomArr = array_values(array_filter(array_map('intval', explode(',', (string)$_GET['rooms'])), fn($v) => $v > 0));
} elseif (isset($_GET['room']) && $_GET['room'] !== '') {
    $roomArr = [ max(0, (int)$_GET['room']) ];
}

if (!$date || empty($roomArr)) {
    // 프론트 안정성을 위해 200 + [] 반환 (필요시 400으로 바꿔도 됨)
    echo json_encode([]);
    exit;
}

try {
    // placeholders (?, ?, ? ...)
    $placeholders = implode(',', array_fill(0, count($roomArr), '?'));

    $sql = "
        SELECT
            GB_room_no    AS room_no,
            GB_start_time AS start_time,
            GB_end_time   AS end_time,
            GB_name,
            GB_phone,
            GB_email,
            GB_id,
            Group_id
        FROM GB_Reservation
        WHERE GB_date = ?
          AND GB_room_no IN ($placeholders)
          -- AND status <> 'cancelled'  -- 상태 컬럼 쓰는 경우 주석 해제
        ORDER BY GB_start_time ASC
    ";

    $params = array_merge([$date], $roomArr);
    $stmt   = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 항상 배열 보장
    echo json_encode(is_array($rows) ? $rows : []);
} catch (Throwable $e) {
    // 화면 안 깨지게 빈 배열 반환 (서버 로그에만 남김)
    error_log('[get_reserved_times] ' . $e->getMessage());
    echo json_encode([]);
}