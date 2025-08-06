<?php
// /api/search_customer.php

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

// 🔹 입력값 수집
$name = $_GET['name'] ?? '';
$phone = $_GET['phone'] ?? '';
$email = $_GET['email'] ?? '';

if (!$name && !$phone && !$email) {
    http_response_code(400);
    echo json_encode(['error' => 'At least one search field is required.']);
    exit;
}

// 🔹 예약 테이블에서 검색
$sql = "SELECT DISTINCT GB_name, GB_phone, GB_email
        FROM gb_reservation
        WHERE 1=1";
$params = [];

if ($name) {
    $sql .= " AND GB_name LIKE :name";
    $params[':name'] = '%' . $name . '%';
}
if ($phone) {
    $sql .= " AND GB_phone LIKE :phone";
    $params[':phone'] = '%' . $phone . '%';
}
if ($email) {
    $sql .= " AND GB_email LIKE :email";
    $params[':email'] = '%' . $email . '%';
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

$final = [];

foreach ($results as $row) {
    $name = $row['GB_name'];
    $phone = $row['GB_phone'];
    $email = $row['GB_email'];

    // 🔹 방문 횟수 계산
    $stmt2 = $pdo->prepare("
        SELECT COUNT(*) FROM gb_reservation
        WHERE GB_name = :name AND GB_phone = :phone AND GB_email = :email
    ");
    $stmt2->execute([
        ':name' => $name,
        ':phone' => $phone,
        ':email' => $email
    ]);
    $visitCount = (int) $stmt2->fetchColumn();

    $final[] = [
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
        'visit_count' => $visitCount,
    ];
}

echo json_encode($final);
