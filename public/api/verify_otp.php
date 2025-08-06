<?php
require_once __DIR__ . '/../includes/config.php'; // .env 로드 포함
require_once __DIR__ . '/../../vendor/autoload.php'; // Twilio SDK

use Twilio\Rest\Client;

header('Content-Type: application/json');

$phone = $_POST['phone'] ?? null;
$code  = $_POST['code'] ?? null;

if (!$phone || !$code) {
    http_response_code(400);
    echo json_encode(['error' => 'Phone number and code are required.']);
    exit;
}

// 🔹 Twilio 정보
$sid       = $_ENV['TWILIO_ACCOUNT_SID'];
$token     = $_ENV['TWILIO_AUTH_TOKEN'];
$verifySid = $_ENV['TWILIO_VERIFY_SID'];

try {
    $twilio = new Client($sid, $token);

    $result = $twilio->verify->v2->services($verifySid)
        ->verificationChecks
        ->create([
            'to'   => '+1' . $phone,
            'code' => $code
        ]);

    if ($result->status === 'approved') {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Incorrect or expired code']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'details' => $e->getMessage()]);
}
