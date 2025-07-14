<?php
/* Database connection — shared by every PHP file
 * require_once __DIR__.'/config.php'; 로 불러 쓰면 됩니다.
 */
$host     = 'localhost';
$db       = 'golf_booking';
$user     = 'root';
$pass     = '8888';
$charset  = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    // production 환경이면 로그만 남기고 사용자에겐 일반 오류 메시지를 보여주는 편이 좋습니다
    die("Database connection failed: " . $e->getMessage());
}