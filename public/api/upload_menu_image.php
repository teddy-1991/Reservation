<?php
// /api/upload_menu_image.php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php'; // DB 연결 불필요하지만, 공통 경로/보안 설정 있으면 그대로 둠

try {
    if (!isset($_POST['slot']) || !in_array($_POST['slot'], ['1','2','3'])) {
        throw new Exception("Invalid slot");
    }
    if (!isset($_FILES['file'])) {
        throw new Exception("No file uploaded");
    }

    $slot = $_POST['slot'];
    $uploadDir = __DIR__ . '/../images/menu/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    // --- 파일 검증 ---
    $allowedExt = ['jpg','jpeg','png','webp'];
    $fileInfo = pathinfo($_FILES['file']['name']);
    $ext = strtolower($fileInfo['extension'] ?? '');
    if (!in_array($ext, $allowedExt)) {
        throw new Exception("Invalid file type");
    }

    $targetFile = $uploadDir . "menu_{$slot}.{$ext}";

    // 기존 확장자 다른 파일은 삭제
    foreach ($allowedExt as $e) {
        $old = $uploadDir . "menu_{$slot}.{$e}";
        if (file_exists($old) && $old !== $targetFile) unlink($old);
    }

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetFile)) {
        throw new Exception("Upload failed");
    }

    // 교체 (서브경로 안전)
    $root = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');  // e.g. /bookingtest/public/api
    $root = preg_replace('#/api$#', '', $root);           // -> /bookingtest/public
    $url  = $root . "/images/menu/menu_{$slot}.{$ext}?t=" . time();
    echo json_encode(['success'=>true,'url'=>$url]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}