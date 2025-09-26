<?php
require_once __DIR__ . '/../../includes/config.php';  // $pdo 포함된 config 파일

$dateStr = $_GET['date'] ?? '';
if (!$dateStr) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing date']);
    exit;
}

$date = date('Y-m-d', strtotime($dateStr));
$weekday = strtolower(date('D', strtotime($date))); // 예: mon, tue, ...

// 1. special 테이블 먼저 조회
$stmt = $pdo->prepare("
    SELECT open_time, close_time
    FROM business_hours_special
    WHERE date = :date
");
$stmt->execute([':date' => $date]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$result) {
    // 2. special이 없으면 weekly 조회
    $stmt = $pdo->prepare("
        SELECT open_time, close_time, is_closed
        FROM business_hours_weekly
        WHERE weekday = :weekday
    ");
    $stmt->execute([':weekday' => $weekday]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$result) {
    // 3. weekly도 없으면 기본값
    $result = [
        'open_time' => '09:00',
        'close_time' => '21:00',
        'is_closed' => 0
    ];
}

header('Content-Type: application/json');
echo json_encode($result);