<?php
// /api/scoreboard/participants_save.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

ob_start();
set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
register_shutdown_function(function(){
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) {
    http_response_code(500);
    $junk = ob_get_contents();
    while (ob_get_level()) ob_end_clean();
    echo json_encode(['ok'=>false,'error'=>'fatal','detail'=>$e['message'],'junk'=>$junk], JSON_UNESCAPED_UNICODE);
  }
});

try {
  require_once __DIR__ . '/../../includes/config.php'; // $pdo
  session_start();
  if (!($_SESSION['is_admin'] ?? false)) {
    http_response_code(401);
    while (ob_get_level()) ob_end_clean();
    echo json_encode(['ok'=>false,'error'=>'unauthorized']);
    exit;
  }

  $raw = file_get_contents('php://input') ?: '';
  $in  = json_decode($raw, true);
  if (!is_array($in)) throw new RuntimeException('invalid_json');

  $event_id = (int)($in['event_id'] ?? 0);
  if ($event_id <= 0) throw new RuntimeException('invalid_event_id');

  $roster = $in['roster'] ?? [];
  if (!is_array($roster)) $roster = [];

  // 분류: 기존 고객 only / 신규는 다음 스텝에서 처리
  $existing = [];
  $need_new = [];
  foreach ($roster as $r) {
    if (!empty($r['customer_id'])) $existing[] = (int)$r['customer_id'];
    elseif (!empty($r['new_customer'])) $need_new[] = $r['new_customer'];
  }

  // 준비된 쿼리
  $pdo->beginTransaction();

  // 고객 이름 스냅샷
  $qName   = $pdo->prepare('SELECT full_name FROM customers_info WHERE id = :id');
  $qExist  = $pdo->prepare('SELECT id FROM event_registrations WHERE event_id = :e AND customer_id = :c LIMIT 1');
  $qInsert = $pdo->prepare('INSERT INTO event_registrations (event_id, customer_id, full_name_snapshot) VALUES (:e, :c, :name)');

  $added = 0; $skipped = 0;
  foreach ($existing as $cid) {
    // 이미 등록되어 있으면 skip
    $qExist->execute([':e'=>$event_id, ':c'=>$cid]);
    if ($qExist->fetchColumn()) { $skipped++; continue; }

    // 스냅샷 이름
    $qName->execute([':id'=>$cid]);
    $name = (string)($qName->fetchColumn() ?: '');

    $qInsert->execute([':e'=>$event_id, ':c'=>$cid, ':name'=>$name]);
    $added++;
  }

  $pdo->commit();

  // 출력 (신규 고객은 다음 스텝에서 처리 예정)
  $junk = ob_get_clean();
  if ($junk !== '') { echo json_encode(['ok'=>false,'error'=>'junk_output','detail'=>$junk]); exit; }

  echo json_encode([
    'ok'      => true,
    'event_id'=> $event_id,
    'added'   => $added,
    'skipped' => $skipped,
    'need_new'=> $need_new, // ← 다음 스텝에서 이 목록을 생성 처리할 예정
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
  while (ob_get_level()) ob_end_clean();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server_error','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
