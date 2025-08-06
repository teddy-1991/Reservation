<?php
// 🔹 Step 1: .env 로드 함수
function loadEnv($path = __DIR__ . '/../../.env') {
    if (!file_exists($path)) return;

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

// 🔹 Step 2: 필수 환경변수 가져오기
function getEnvOrFail($key) {
    if (!isset($_ENV[$key]) || $_ENV[$key] === '') {
        throw new RuntimeException("Missing required environment variable: $key");
    }
    return $_ENV[$key];
}

// 🔹 Step 3: .env 파일 로드
loadEnv();

// 🔹 Step 4: DB 연결
$host    = getEnvOrFail('DB_HOST');
$db      = getEnvOrFail('DB_NAME');
$user    = getEnvOrFail('DB_USER');
$pass    = getEnvOrFail('DB_PASS');
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
