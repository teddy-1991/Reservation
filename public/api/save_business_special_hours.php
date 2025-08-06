<?php
require_once __DIR__ . '/../includes/config.php';  // $pdo 포함

session_start();

if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$date = $_POST['date'] ?? '';
$open = $_POST['open_time'] ?? '';
$close = $_POST['close_time'] ?? '';

if (!$date || !$open || !$close) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO business_hours_special (date, open_time, close_time)
        VALUES (:date, :open_time, :close_time)
        ON DUPLICATE KEY UPDATE
            open_time = VALUES(open_time),
            close_time = VALUES(close_time)
    ");

    $stmt->execute([
        ':date' => $date,
        ':open_time' => $open,
        ':close_time' => $close
    ]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save special hours', 'message' => $e->getMessage()]);
}