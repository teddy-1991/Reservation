<?php
// api/update_group_reservation.php

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

$groupId        = $_POST['Group_id'] ?? null;
$date           = $_POST['GB_date'] ?? null;
$startTime      = $_POST['GB_start_time'] ?? null;
$endTime        = $_POST['GB_end_time'] ?? null;
$name           = $_POST['GB_name'] ?? null;
$email          = $_POST['GB_email'] ?? null;
$phone          = $_POST['GB_phone'] ?? null;

if (!$groupId || !$date || !$startTime || !$endTime || !$name || !$phone) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        UPDATE GB_Reservation
        SET GB_date = ?, GB_start_time = ?, GB_end_time = ?, GB_name = ?, GB_email = ?, GB_phone = ?
        WHERE Group_id = ?
    ");
    $stmt->execute([$date, $startTime, $endTime, $name, $email, $phone, $groupId]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Update failed']);
}