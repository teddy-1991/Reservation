<?php
// /api/scoreboard/participants_list.php
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

  $event_id = (int)($_GET['event_id'] ?? 0);
  if ($event_id <= 0) {
    http_response_code(400);
    while (ob_get_level()) ob_end_clean();
    echo json_encode(['ok'=>false,'error'=>'invalid_event_id']);
    exit;
  }

  $sql = "
    SELECT
      er.id        AS registration_id,
      er.customer_id,
      er.full_name_snapshot,
      ci.full_name AS name,          -- 현재 고객 테이블의 이름(있으면)
      ci.phone,
      ci.email,
      er.created_at
    FROM event_registrations er
    LEFT JOIN customers_info ci ON ci.id = er.customer_id
    WHERE er.event_id = :eid
    ORDER BY er.created_at ASC, er.id ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':eid'=>$event_id]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $junk = ob_get_clean();
  if ($junk !== '') { echo json_encode(['ok'=>false,'error'=>'junk_output','detail'=>$junk]); exit; }

  echo json_encode(['ok'=>true,'registrations'=>$rows], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
  while (ob_get_level()) ob_end_clean();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server_error','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
