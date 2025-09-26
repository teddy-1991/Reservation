<?php
// /public/api/customer_cancel_reservation.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Edmonton');

require_once __DIR__ . '/../../includes/config.php';     // $pdo
require_once __DIR__ . '/../../includes/functions.php';  // validate_edit_token, upsert_edit_token_for_group, sendReservationEmail, get_client_ip

try {
  // 1) 입력
  $token = trim((string)($_POST['token'] ?? ''));
  if ($token === '') {
    http_response_code(400);
    echo json_encode(['success'=>false, 'error'=>'missing_token']);
    exit;
  }

  // 2) 토큰 검증
  $chk = validate_edit_token($pdo, $token);
  if (!($chk['ok'] ?? false)) {
    http_response_code(400);
    echo json_encode(['success'=>false, 'error'=>$chk['code'] ?? 'invalid_token']);
    exit;
  }
  $groupId = (string)$chk['group_id'];

  // 3) 대표 정보(메일용/표시용) 조회
  $st = $pdo->prepare("
    SELECT GB_name AS name, GB_email AS email, GB_phone AS phone,
           GB_date AS date, GB_start_time AS start_time, GB_end_time AS end_time
      FROM GB_Reservation
     WHERE Group_id = :gid
     LIMIT 1
  ");
  $st->execute([':gid'=>$groupId]);
  $head = $st->fetch(PDO::FETCH_ASSOC);
  if (!$head) {
    // 이미 지워졌거나 없음
    http_response_code(404);
    echo json_encode(['success'=>false, 'error'=>'group_not_found']);
    exit;
  }

  // 4) 현재 방 목록(메일 안내용)
  $st2 = $pdo->prepare("
    SELECT GROUP_CONCAT(DISTINCT GB_room_no ORDER BY GB_room_no SEPARATOR ',') AS rooms_csv
      FROM GB_Reservation
     WHERE Group_id = :gid
  ");
  $st2->execute([':gid'=>$groupId]);
  $roomsCsv = (string)($st2->fetchColumn() ?: '');

  // 5) 트랜잭션: 그룹 전체 삭제
  $pdo->beginTransaction();

  $del = $pdo->prepare("DELETE FROM GB_Reservation WHERE Group_id = :gid");
  $del->execute([':gid'=>$groupId]);

  // 6) 토큰 무효화(지금 시각 이전으로 만료시킴)
  $expired = (new DateTimeImmutable('now'))->modify('-1 minute');
  upsert_edit_token_for_group($pdo, $groupId, DateTime::createFromImmutable($expired));

  $pdo->commit();

  // ✅ 커밋 후: 참조 없는 고객행 삭제
  if ($maybeOrphans) {
    $ph = implode(',', array_fill(0, count($maybeOrphans), '?'));
    // still-used 집계
    $chk = $pdo->prepare("SELECT customer_id, COUNT(*) AS c FROM GB_Reservation WHERE customer_id IN ($ph) GROUP BY customer_id");
    $chk->execute($maybeOrphans);
    $still = $chk->fetchAll(PDO::FETCH_KEY_PAIR); // [customer_id => count]

    $toDelete = [];
    foreach ($maybeOrphans as $cid) {
      if (!isset($still[$cid]) || (int)$still[$cid] === 0) $toDelete[] = $cid;
    }
    if ($toDelete) {
      $ph2 = implode(',', array_fill(0, count($toDelete), '?'));
      $pdo->prepare("DELETE FROM customers_info WHERE id IN ($ph2)")->execute($toDelete);
    }
  }

  // 7) 메일 알림(취소 안내) — 기존 공용 메일러 재사용
  $toName  = (string)($head['name']  ?? '');
  $toEmail = (string)($head['email'] ?? '');
  $date    = (string)($head['date']  ?? '');
  $start   = substr((string)($head['start_time'] ?? ''), 0, 5);
  $end     = substr((string)($head['end_time']   ?? ''), 0, 5);

  $subject = 'Your reservation has been canceled';
  $intro   = 'Your reservation was canceled as requested. If this was a mistake, please call us to rebook.';

  $mailOk = true;
  if ($toEmail !== '') {
    // room list는 문자열 CSV 넘김
    $mailOk = sendReservationEmail($toEmail, $toName, $date, $start, $end, $roomsCsv, $subject, $intro, ['group_id'=>$groupId, 'canceled'=>true]);
  }

  echo json_encode(['success'=>true, 'mail'=>$mailOk, 'group_id'=>$groupId]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['success'=>false, 'error'=>'server', 'message'=>$e->getMessage()]);
}
