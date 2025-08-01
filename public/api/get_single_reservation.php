<?php
// api/get_single_reservation.php

header('Content-Type: application/json');
require_once __DIR__.'/../includes/config.php'; // $pdo

$id = $_GET['id'] ?? null;

if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing reservation ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM gb_reservation WHERE GB_id = ?");
    $stmt->execute([$id]);
    $data = $stmt->fetch();

    if ($data) {
        echo json_encode($data);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Reservation not found']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server', 'details' => $e->getMessage()]);
}
