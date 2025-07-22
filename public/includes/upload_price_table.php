<?php
$response = ['success' => false];

if (isset($_FILES['priceTableImage']) && $_FILES['priceTableImage']['error'] === UPLOAD_ERR_OK) {
    $tmpName = $_FILES['priceTableImage']['tmp_name'];
    $destination = __DIR__ . '/../images/price_table.png';

    if (move_uploaded_file($tmpName, $destination)) {
        $response['success'] = true;
    } else {
        $response['error'] = 'move_uploaded_file failed';
    }
} else {
    $response['error'] = 'no file or upload error: ' . $_FILES['priceTableImage']['error'];
}

header('Content-Type: application/json');
echo json_encode($response);
