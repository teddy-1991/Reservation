<?php
require_once __DIR__ . '/../includes/config.php';

$stmt = $pdo->query("SELECT weekday, open_time, close_time, is_closed FROM business_hours_weekly");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($rows);