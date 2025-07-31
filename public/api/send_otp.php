<?php
require_once __DIR__ . '/../../vendor/autoload.php';
use Twilio\Rest\Client;
use Dotenv\Dotenv;

header('Content-Type: application/json');

// .env 로드
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

// 필수 입력 검증
$phone = $_POST['phone'] ?? null;
if (!$phone) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing phone number']);
    exit;
}

// Twilio 계정 정보
$sid = $_ENV['TWILIO_ACCOUNT_SID'];
$token = $_ENV['TWILIO_AUTH_TOKEN'];
$verifySid = $_ENV['TWILIO_VERIFY_SID'];

try {
    $twilio = new Client($sid, $token);
    $twilio->verify->v2->services($verifySid)
        ->verifications
        ->create('+1' . $phone, "sms");

    echo json_encode([
        'success' => true,
        'message' => '인증코드 전송 완료!'
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'server',
        'details' => $e->getMessage()
    ]);
}
