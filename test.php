<?php
error_reporting(E_ALL); ini_set('display_errors',1);

require_once __DIR__.'/../includes/config.php';     // ← ../ 필수
require_once __DIR__.'/../includes/functions.php';  // ← ../ 필수

try {
    echo '✅ DB 연결 성공, 결과 = '.$pdo->query('SELECT 1')->fetchColumn();
} catch (Throwable $e) {
    echo '❌ DB 연결 실패<br>'.$e->getMessage();
}
