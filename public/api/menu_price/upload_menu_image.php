<?php
// /api/upload_menu_image.php  (또는 /api/menu_price/upload_menu_image.php)
// 업로드: /public/images/menu/menu_{slot}.{ext}
// 응답: {"success":true,"url":"/<public>/images/menu/menu_{slot}.{ext>?t=...}

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
// 개발 중 임시 디버그가 필요하면 주석 해제
// error_reporting(E_ALL); ini_set('display_errors', '1');

require_once __DIR__ . '/../../includes/config.php'; // 공통 설정/보안

try {
    // 1) 기본 검증
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $slot = $_POST['slot'] ?? null;
    if (!in_array($slot, ['1','2','3'], true)) {
        throw new Exception('Invalid slot');
    }
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded');
    }

    $tmpFile = $_FILES['file']['tmp_name'];

    // 2) 확장자/타입 검증
    $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? finfo_file($finfo, $tmpFile) : null;
    if ($finfo) finfo_close($finfo);

    $ext = $allowedMime[$mime] ?? strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION) ?: '');
    if ($ext === 'jpeg') $ext = 'jpg';
    if (!in_array($ext, ['jpg','png','webp'], true)) {
        throw new Exception('Invalid file type');
    }

    // 3) 물리 경로 계산: /public/images/menu
    $publicDir = realpath(__DIR__ . '/../../'); // .../public
    if ($publicDir === false) {
        throw new Exception('Failed to resolve public dir');
    }
    $uploadDir = $publicDir . '/images/menu';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new Exception('Failed to create images/menu directory');
    }
    if (!is_writable($uploadDir)) {
        throw new Exception('images/menu not writable');
    }

    // 4) 기존 파일 정리 및 저장
    foreach (['jpg','jpeg','png','webp'] as $e) {
        $old = $uploadDir . "/menu_{$slot}.{$e}";
        if (is_file($old)) @unlink($old);
    }
    $targetFile = $uploadDir . "/menu_{$slot}.{$ext}";
    if (!move_uploaded_file($tmpFile, $targetFile)) {
        throw new Exception('Upload failed');
    }
    @chmod($targetFile, 0644);

    // 5) 공개 URL 계산: /<public>/images/menu/...
    // (파일이 /api 또는 /api/하위 폴더 어디에 있어도 /public 기준으로 계산)
    $publicBase = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');     // e.g. /bookingtest/public/api/menu_price
    $publicBase = preg_replace('#/api(?:/.*)?$#', '', $publicBase); // -> /bookingtest/public
    $url = $publicBase . '/images/menu/' . basename($targetFile) . '?t=' . time();

    echo json_encode(['success' => true, 'url' => $url]);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
