<?php
// /api/get_menu_fixed3.php
header('Content-Type: application/json');

$uploadDir = __DIR__ . '/../../images/menu/';
// 교체 (서브경로 안전)/
$root = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');  // e.g. /bookingtest/public/api
$root = preg_replace('#/api$#', '', $root);           // -> /bookingtest/public
$baseUrl = $root . '/images/menu/';
$allowedExt = ['jpg','jpeg','png','webp'];

$result = [];

for ($i=1; $i<=3; $i++) {
    foreach ($allowedExt as $ext) {
        $file = $uploadDir . "menu_{$i}.{$ext}";
        if (file_exists($file)) {
            $result[] = [
                'slot' => $i,
                'url'  => $baseUrl . "menu_{$i}.{$ext}?t=" . filemtime($file)
            ];
            break; // 확장자 찾으면 다음 슬롯으로
        }
    }
}

echo json_encode($result);