<?php
require_once __DIR__ . '/../includes/config.php'; // .env 로드 포함
require_once __DIR__ . '/../../vendor/autoload.php'; // Twilio SDK

use Twilio\Rest\Client;

header('Content-Type: application/json');

// 🔹 입력값
$phone = $_POST['phone'] ?? null;
if (!$phone) {
    http_response_code(400);
    echo json_encode(['error' => 'Phone number is required.']);
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
        ->create('+1' . $phone, 'sms');

    echo json_encode(['success' => true, 'message' => 'OTP sent']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send OTP', 'details' => $e->getMessage()]);
}
