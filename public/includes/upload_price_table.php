<?php
// api/upload_price_table.php
header('Content-Type: application/json');

$response = ['success' => false];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['priceTableImage'])) {
    $uploadDir = __DIR__ . '/../images/';
    $filename = 'price_table.jpg';  // 항상 같은 이름으로 덮어쓰기
    $targetPath = $uploadDir . $filename;

    // 이미지 유효성 검사 (선택사항)
    $allowedTypes = ['image/jpeg', 'image/png'];
    if (!in_array($_FILES['priceTableImage']['type'], $allowedTypes)) {
        $response['message'] = 'Only JPG or PNG images are allowed.';
        echo json_encode($response);
        exit;
    }

    if (move_uploaded_file($_FILES['priceTableImage']['tmp_name'], $targetPath)) {
        $response['success'] = true;
        $response['newPath'] = '/images/' . $filename;
    } else {
        $response['message'] = 'File upload failed.';
    }
} else {
    $response['message'] = 'Invalid request.';
}

echo json_encode($response);
