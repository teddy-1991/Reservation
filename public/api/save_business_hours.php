<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';

try {
    $raw = file_get_contents("php://input:");
    $date = json_decode($raw, true);

    if (!is_array($data)) {
        throw new Exception("Invalid input data");
    }

    $pdo->beginTransaction();

    foreach ($data as $entry) {
        $day = $entry['day'];
        $isClosed = $entry['is_closed'] ? 1 : 0;
        $open = $entry['open_time'] ?? null;
        $close = $entry['close_time'] ?? null;

        $stmt = $pdo->prepare("
        UPDATE Business_Hours
        SET open_time = :open, close_time = :close, is_closed = :is_closed
        WHERE day = :day");

        $stmt->execute([
            ':open' => $open,
            ':close' => $close,
            ':is_closed' => $isClosed,
            ':day' => $day
        ]);
    }

    $pdo->commit();

    echo json_encode(["success" => true]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}