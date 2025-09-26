<?php
// /api/debug_snapshot.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
session_start();

// 관리자만 접근 (간단 보호막)
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();

    $out = [
        'database' => $dbName,
        'counts'   => [],
        'sample'   => []
    ];

    // 예약 테이블
    $out['counts']['GB_Reservation'] =
        (int)$pdo->query("SELECT COUNT(*) FROM GB_Reservation")->fetchColumn();

    $out['sample']['GB_Reservation'] =
        $pdo->query("
            SELECT GB_id, GB_date, GB_room_no, GB_start_time, GB_end_time,
                   GB_name, GB_email, GB_phone, Group_id,
                   customer_id, GB_created_at
            FROM GB_Reservation
            ORDER BY GB_id DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

    // 고객 테이블
    $out['counts']['customers_info'] =
        (int)$pdo->query("SELECT COUNT(*) FROM customers_info")->fetchColumn();

    $out['sample']['customers_info'] =
        $pdo->query("
            SELECT id, full_name, email, phone, created_at, updated_at
            FROM customers_info
            ORDER BY id DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
