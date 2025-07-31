<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Twilio\Rest\Client;
use Dotenv\Dotenv;

header('Content-Type: application/json');

// .env 로드
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

// 필수 입력값 검증
$phone = $_POST['phone'] ?? null;
$code  = $_POST['code'] ?? null;

if (!$phone || !$code) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing phone number or code']);
    exit;
}

// Twilio credentials
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

    if ($result->status === "approved") {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Incorrect code']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server', 'details' => $e->getMessage()]);
}
