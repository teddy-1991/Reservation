<?php
// api/check_verified_phone.php
header('Content-Type: application/json');

require_once __DIR__.'/../../includes/config.php'; // $pdo

$phone = $_GET['phone'] ?? '';
if ($phone === '') {
  echo json_encode(['verified' => false]);
  exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 1 
        FROM GB_Reservation 
        WHERE GB_phone = ? 
        LIMIT 1
    ");
    $stmt->execute([$phone]);
    $exists = (bool)$stmt->fetchColumn();

    echo json_encode(['verified' => $exists]);
} catch (Throwable $e) {
    echo json_encode(['verified' => false, 'error' => 'db_error']);
}