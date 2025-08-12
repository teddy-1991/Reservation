<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php'; // $pdo

$start = $_GET['start'] ?? null;
$end   = $_GET['end'] ?? null;

if (!$start || !$end) {
    echo json_encode([]);
    exit;
}

$sql = "
    SELECT date, start_time, COUNT(*) as cnt
    FROM reservations
    WHERE date BETWEEN :start AND :end
    GROUP BY date, start_time
";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':start' => $start,
    ':end'   => $end
]);

$out = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $ymd = $row['date'];
    $time = substr($row['start_time'], 0, 5);
    $out[$ymd][$time] = (int)$row['cnt'];
}

echo json_encode($out);