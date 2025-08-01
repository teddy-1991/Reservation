<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

try {
    $start_date = $_POST['start_date'] ?? null;
    $end_date   = $_POST['end_date'] ?? null;

    if (!$start_date || !$end_date) {
        throw new Exception("Missing date range.");
    }

    // 기존 기간 삭제
    $stmt = $pdo->prepare("DELETE FROM business_hours WHERE start_date = :start_date AND end_date = :end_date");
    $stmt->execute([
        ':start_date' => $start_date,
        ':end_date'   => $end_date
    ]);

    $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    foreach ($days as $day) {
        $open   = $_POST["{$day}_open"]   ?? null;
        $close  = $_POST["{$day}_close"]  ?? null;
        $closed = isset($_POST["{$day}_closed"]) ? 1 : 0;

        // 휴무일이면 open/close null로 저장
        if ($closed || !$open || !$close) {
            $open = null;
            $close = null;
        }

        $stmt = $pdo->prepare("
            INSERT INTO business_hours (start_date, end_date, weekday, open_time, close_time, closed)
            VALUES (:start, :end, :weekday, :open, :close, :closed)
        ");

        $stmt->execute([
            ':start'   => $start_date,
            ':end'     => $end_date,
            ':weekday' => $day,
            ':open'    => $open,
            ':close'   => $close,
            ':closed'  => $closed
        ]);
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
