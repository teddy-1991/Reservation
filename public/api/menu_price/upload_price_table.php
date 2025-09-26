<?php
require_once __DIR__ . '/../../includes/config.php'; // DB 연결 불필요하지만, 공통 경로/보안 설정 있으면 그대로 둠

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (!isset($_FILES['priceTableImage']) || $_FILES['priceTableImage']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'msg' => 'no file']);
    exit;
}

$img = $_FILES['priceTableImage'];

// 저장할 절대 경로: /public/images/price_table.png
$targetDir = dirname(__DIR__) . '/../../images';          // (__DIR__ 는 /public/includes)
$target    = $targetDir . '/price_table.png';       // 파일명 소문자 고정

if (!is_dir($targetDir)) {
    echo json_encode(['success' => false, 'msg' => 'images dir missing']);
    exit;
}

if (!move_uploaded_file($img['tmp_name'], $target)) {
    echo json_encode(['success' => false, 'msg' => 'move failed']);
    exit;
}

// 권한 보정(공유호스팅에서 가끔 필요)
@chmod($target, 0644);

echo json_encode(['success' => true]);