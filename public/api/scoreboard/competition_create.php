<?php
// /api/competition_create.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php'; // $pdo (PDO)
session_start();

// 관리자 체크 (네 기존 패턴)
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
  exit;
}

// 입력 파싱
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

$title       = trim($data['title'] ?? '');
$course_name = trim($data['course_name'] ?? '');
$month_raw   = trim($data['month'] ?? '');      // "YYYY-MM"
$event_date  = trim($data['event_date'] ?? ''); // 있으면 우선 사용
$pars        = $data['pars'] ?? [];

// "YYYY-MM" → "YYYY-MM-01"
if ($event_date === '' && $month_raw !== '') {
  if (preg_match('/^(\d{4})[-\/\.](\d{1,2})$/', $month_raw, $m)) {
    $y  = $m[1];
    $mm = str_pad($m[2], 2, '0', STR_PAD_LEFT);
    $event_date = "{$y}-{$mm}-01";
  }
}

// 최소 유효성
if ($title === '' || $event_date === '' || !is_array($pars) || count($pars) !== 18) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
  exit;
}

// 파 값 검증(스키마의 CHECK 제약과 일치: 2~6)
$clean = [];
foreach ($pars as $i => $v) {
  $n = (int)$v;
  if ($n < 2 || $n > 6) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => "Invalid par at hole ".($i+1)]);
    exit;
  }
  $clean[] = $n;
}

try {
  $pdo->beginTransaction();

  // 합계 (events.event_par 칼럼에 저장)
  $event_par_total = array_sum($clean);

  // events INSERT
  $stmt = $pdo->prepare("
    INSERT INTO events (title, event_date, event_par, course_name)
    VALUES (?, ?, ?, ?)
  ");
  $stmt->execute([
    $title,
    $event_date,
    $event_par_total,
    ($course_name !== '' ? $course_name : null)
  ]);
  $event_id = (int)$pdo->lastInsertId();

  // event_pars INSERT (p1~p18 한 행)
  $sql = "
    INSERT INTO event_pars (
      event_id,
      p1,p2,p3,p4,p5,p6,p7,p8,p9,p10,p11,p12,p13,p14,p15,p16,p17,p18
    ) VALUES (
      ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?
    )
  ";
  $ins = $pdo->prepare($sql);
  $ins->execute(array_merge([$event_id], $clean));

  $pdo->commit();

  echo json_encode([
    'ok'         => true,
    'event_id'   => $event_id,
    'event_date' => $event_date,
    'event_par'  => $event_par_total
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'DB error: '.$e->getMessage()]);
}
