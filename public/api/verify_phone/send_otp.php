<?php
require_once __DIR__ . '/../../includes/config.php'; // .env 로드 포함
require_once __DIR__ . '/../../../vendor/autoload.php'; // Twilio SDK

use Twilio\Rest\Client;

header('Content-Type: application/json; charset=utf-8');

// 🔹 캐나다 지역번호 화이트리스트 (배열을 변수로 정의)
$CA_AREA_CODES = [
    // Alberta
    "403","587","780","825","368",
    // British Columbia
    "236","250","257","604","672","778",
    // Manitoba
    "204","431","584",
    // New Brunswick
    "506","428",
    // Newfoundland and Labrador
    "709","879",
    // Nova Scotia & Prince Edward Island
    "902","782",
    // Ontario
    "226","249","289","343","365","382","416","437","519","548","613","647","683","705","742","753","807","905","942",
    // Québec
    "263","354","367","418","438","450","468","514","579","581","819","873",
    // Saskatchewan
    "306","474","639",
    // Yukon / Northwest Territories / Nunavut
    "867"
];

// 🔹 입력값
$phone = $_POST['phone'] ?? '';
$digits = preg_replace('/\D/', '', $phone);

if (!preg_match('/^\d{10}$/', $digits)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Phone must be 10 digits (numbers only).']);
    exit;
}

// 🔹 지역번호 화이트리스트 체크
$npa = substr($digits, 0, 3);
if (!in_array($npa, $CA_AREA_CODES, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => "Unsupported area code: {$npa}. Canada-only numbers are allowed."]);
    exit;
}

// 🔹 Twilio 정보
$sid       = $_ENV['TWILIO_ACCOUNT_SID'];
$token     = $_ENV['TWILIO_AUTH_TOKEN'];
$verifySid = $_ENV['TWILIO_VERIFY_SID'];

try {
    $twilio = new Client($sid, $token);

    $twilio->verify->v2->services($verifySid)
        ->verifications
        ->create('+1' . $digits, 'sms');

    echo json_encode(['success' => true, 'message' => 'OTP sent']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to send OTP', 'details' => $e->getMessage()]);
}
