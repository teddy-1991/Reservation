<?php
// api/delete_reservation.php
session_start();

if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php'; // $pdo 사용

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Only DELETE method is allowed']);
    exit;
}

parse_str(file_get_contents("php://input"), $data);
$id = $_GET['id'] ?? null;
$groupId = $data['Group_id'] ?? null;

if (!$id && !$groupId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing reservation ID or group_id']);
    exit;
}


try {
    if ($groupId) {
        $stmt = $pdo->prepare("DELETE FROM GB_Reservation WHERE Group_id = ?");
        $stmt->execute([$groupId]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM GB_Reservation WHERE GB_id = ?");
        $stmt->execute([$id]);
    }

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Reservation not found']);
    } else {
        echo json_encode(['success' => true]);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server', 'details' => $e->getMessage()]);
}