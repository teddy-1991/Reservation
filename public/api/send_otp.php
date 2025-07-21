<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Twilio\Rest\Client;
use Dotenv\Dotenv;

header('Content-Type: application/json');  // ✅ JSON 헤더 추가

// .env 로드
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

// Twilio 계정 정보
$sid = $_ENV['TWILIO_ACCOUNT_SID'];
$token = $_ENV['TWILIO_AUTH_TOKEN'];
$verifySid = $_ENV['TWILIO_VERIFY_SID'];

$phone = '+1' . $_POST['phone'];  // 예: +14035551234

try {
    $twilio = new Client($sid, $token);
    $twilio->verify->v2->services($verifySid)
        ->verifications
        ->create($phone, "sms");

    echo json_encode([
        'success' => true,
        'message' => '인증코드 전송 완료!'
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '전송 실패: ' . $e->getMessage()
    ]);
}
?>
