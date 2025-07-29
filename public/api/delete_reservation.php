<?php
// api/delete_reservation.php

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php'; // $pdo 사용

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Only DELETE allowed']);
    exit;
}

// DELETE 방식에서는 php://input 사용
parse_str(file_get_contents("php://input"), $data);
$id = $data['id'] ?? null;

if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing reservation ID']);
    exit;
}

$stmt = $pdo->prepare("DELETE FROM gb_reservation WHERE GB_id = ?");
$success = $stmt->execute([$id]);

echo json_encode(['success' => $success]);