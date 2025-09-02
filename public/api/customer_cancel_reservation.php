<?php
// /public/api/customer_cancel_reservation.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Edmonton');

require_once __DIR__ . '/../includes/config.php';     // $pdo
require_once __DIR__ . '/../includes/functions.php';  // validate_edit_token, sendReservationEmail, upsert_edit_token_for_target (optional)

try {
    // 1) 입력
    $token = $_POST['token'] ?? '';
    $token = trim((string)$token);
    if ($token === '') {
        http_response_code(422);
        echo json_encode(['success'=>false, 'error'=>'missing_token']);
        exit;
    }

    // 2) 토큰 검증 (만료/잘못된 토큰은 거절)
    $chk = validate_edit_token($pdo, $token);
    if (!$chk['ok']) {
        http_response_code(400);
        echo json_encode(['success'=>false, 'error'=>$chk['code'] ?? 'invalid_token']);
        exit;
    }
    $groupId = (string)$chk['group_id'];

    // 3) 메일 발송에 필요한 대표 정보/방 목록 조회
    $headStmt = $pdo->prepare("
        SELECT GB_name AS name, GB_email AS email, GB_date AS date,
               GB_start_time AS start_time, GB_end_time AS end_time
          FROM GB_Reservation
         WHERE Group_id = :gid
         LIMIT 1
    ");
    $headStmt->execute([':gid'=>$groupId]);
    $head = $headStmt->fetch(PDO::FETCH_ASSOC);
    if (!$head) {
        http_response_code(404);
        echo json_encode(['success'=>false, 'error'=>'group_not_found']);
        exit;
    }
    $roomsStmt = $pdo->prepare("
        SELECT GROUP_CONCAT(DISTINCT GB_room_no ORDER BY GB_room_no SEPARATOR ',') AS rooms_csv
          FROM GB_Reservation
         WHERE Group_id = :gid
    ");
    $roomsStmt->execute([':gid'=>$groupId]);
    $roomsCsv = (string)($roomsStmt->fetchColumn() ?: '');

    // 4) 트랜잭션: 예약 삭제 + 토큰 즉시 무효화
    $pdo->beginTransaction();

    // 4-1) 그룹 전체 예약 삭제
    $del = $pdo->prepare("DELETE FROM GB_Reservation WHERE Group_id = :gid");
    $del->execute([':gid'=>$groupId]);

    // 4-2) 토큰 즉시 만료 처리(재사용 방지)
    $expire = $pdo->prepare("UPDATE reservation_tokens SET expires_at = NOW(), used_at = NOW() WHERE token = :t LIMIT 1");
    $expire->execute([':t'=>$token]);

    $pdo->commit();

    // 5) 취소 메일 발송 (링크 안 붙이려면 tokenTarget 인자 전달하지 않음)
    $toName  = (string)$head['name'];
    $toEmail = (string)$head['email'];
    $date    = (string)$head['date'];
    $start   = substr((string)$head['start_time'], 0, 5);
    $end     = substr((string)$head['end_time'],   0, 5);

    $subject  = 'Your reservation has been canceled';
    $intro    = "Your reservation has been canceled as requested.\nWe hope to see you again soon.";

    // sendReservationEmail은 마지막 인자(tokenTarget)를 주지 않으면 self-service 블록이 붙지 않음
    $ok = sendReservationEmail(
        $toEmail,
        $toName,
        $date,
        $start,
        $end,
        $roomsCsv,
        $subject,
        $intro
        // ← tokenTarget 없음 (취소 메일엔 링크 불필요)
    );

    echo json_encode(['success'=>true, 'mail'=>$ok, 'group_id'=>$groupId]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success'=>false, 'error'=>'server', 'message'=>$e->getMessage()]);
}
