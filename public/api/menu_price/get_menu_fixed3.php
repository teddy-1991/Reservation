<?php
// /api/menu_price/get_menu_fixed3.php
header('Content-Type: application/json; charset=utf-8');

// 물리 경로: .../public, .../public/images/menu
$publicDir = realpath(__DIR__ . '/../../');            // -> .../public
$uploadDir = $publicDir . '/images/menu';

// 공개 URL 베이스: .../public
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');      // e.g. /bookingtest/public/api/menu_price
$baseUrl = preg_replace('#/api(?:/.*)?$#', '', $baseUrl);     // -> /bookingtest/public

$exts = ['png','jpg','webp'];   // 업로드 쪽이 jpeg->jpg로 정규화되었으니 이 3종이면 충분
$out  = [];

for ($i = 1; $i <= 3; $i++) {
    foreach ($exts as $ext) {
        $file = $uploadDir . "/menu_{$i}.{$ext}";
        if (is_file($file)) {
            $out[] = [
                'slot' => (string)$i,
                'url'  => $baseUrl . "/images/menu/menu_{$i}.{$ext}?t=" . filemtime($file)
            ];
            break; // 첫 매칭만 사용
        }
    }
}

echo json_encode($out);
