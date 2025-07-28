<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Twilio\Rest\Client;
use Dotenv\Dotenv;

// Load .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

// Twilio credentials
$sid = $_ENV['TWILIO_ACCOUNT_SID'];
$token = $_ENV['TWILIO_AUTH_TOKEN'];
$verifySid = $_ENV['TWILIO_VERIFY_SID'];

$twilio = new Client($sid, $token);

// Get input
$phone = '+1' . $_POST['phone'];
$code = $_POST['code'] ?? '';

try {
    $verification_check = $twilio->verify->v2->services($verifySid)
        ->verificationChecks
        ->create([
            "to" => $phone,
            "code" => $code
        ]);

    if ($verification_check->status === "approved") {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Incorrect code']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
