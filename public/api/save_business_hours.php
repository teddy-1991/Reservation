<?php
require_once __DIR__ . '/../includes/config.php';  // $pdo 포함

header('Content-Type: application/json');

$weekdays = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
// ✅ 이 아래에 바로 추가
$hasValidInput = false;
foreach ($weekdays as $day) {
  if (!empty($_POST[$day . '_open']) || !empty($_POST[$day . '_close'])) {
    $hasValidInput = true;
    break;
  }
}
if (!$hasValidInput) {
  http_response_code(400);
  echo json_encode(['error' => 'No valid input provided']);
  exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO business_hours_weekly (weekday, open_time, close_time, is_closed)
        VALUES (:weekday, :open_time, :close_time, :is_closed)
        ON DUPLICATE KEY UPDATE
            open_time = VALUES(open_time),
            close_time = VALUES(close_time),
            is_closed = VALUES(is_closed)
    ");

    foreach ($weekdays as $day) {
        $openKey = $day . '_open';
        $closeKey = $day . '_close';
        $closedKey = $day . '_closed';

        // 기본값 설정
        $open = $_POST[$openKey] ?? '00:00';
        $close = $_POST[$closeKey] ?? '00:00';
        $isClosed = isset($_POST[$closedKey]) && $_POST[$closedKey] === '1' ? 1 : 0;

        $stmt->execute([
            ':weekday' => $day,
            ':open_time' => $open,
            ':close_time' => $close,
            ':is_closed' => $isClosed
        ]);
    }

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save weekly hours', 'message' => $e->getMessage()]);
}
