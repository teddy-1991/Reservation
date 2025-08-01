<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

$date = $_GET['date'] ?? null;

if (!$date) {
  http_response_code(400);
  echo json_encode(['error' => 'Date is required']);
  exit;
}

$timestamp = strtotime($date);
$weekday = strtolower(date('D', $timestamp)); // 'mon', 'tue', ...

try {
    $stmt = $pdo->prepare("
    SELECT open_time, close_time, closed
    FROM business_hours
    WHERE :date BETWEEN start_date AND end_date
        AND weekday = :weekday
    ORDER BY DATEDIFF(end_date, start_date) ASC, start_date DESC
    LIMIT 1
    ");
  $stmt->execute([
    ':date' => $date,
    ':weekday' => $weekday
  ]);

  $result = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($result) {
    echo json_encode(['success' => true, 'data' => $result]);
  } else {
    echo json_encode(['success' => false, 'message' => 'No hours found']);
  }
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Server error']);
}
