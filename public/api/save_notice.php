<?php
// /api/save_notice.php
session_start();

if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$html = $_POST['html'] ?? '';

if (!$html) {
    http_response_code(400);
    echo "Error: Empty content";
    exit;
}

$path = __DIR__ . '/../data/notice.html';

if (!file_exists(dirname($path))) {
    mkdir(dirname($path), 0777, true);
}

if (file_put_contents($path, $html)) {
    echo "saved";
} else {
    http_response_code(500);
    echo "failed to save";
}