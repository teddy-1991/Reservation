<?php
// /bookingtest/public/api/competition_get.php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../includes/config.php'; // $pdo
session_start();

// 관리자만 허용 (네 패턴 통일)
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
  exit;
}

// 입력: ?event_id=123  또는  ?month=YYYY-MM
$event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
$month    = isset($_GET['month']) ? trim($_GET['month']) : '';

try {
  if ($event_id > 0) {
    $sql = "
      SELECT e.id, e.title, e.event_date, e.event_par, e.course_name,
             ep.p1,ep.p2,ep.p3,ep.p4,ep.p5,ep.p6,ep.p7,ep.p8,ep.p9,
             ep.p10,ep.p11,ep.p12,ep.p13,ep.p14,ep.p15,ep.p16,ep.p17,ep.p18
      FROM events e
      LEFT JOIN event_pars ep ON ep.event_id = e.id
      WHERE e.id = ?
      LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$event_id]);
  } elseif ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
    // 같은 달의 레코드 중 최신 1건
    $sql = "
      SELECT e.id, e.title, e.event_date, e.event_par, e.course_name,
             ep.p1,ep.p2,ep.p3,ep.p4,ep.p5,ep.p6,ep.p7,ep.p8,ep.p9,
             ep.p10,ep.p11,ep.p12,ep.p13,ep.p14,ep.p15,ep.p16,ep.p17,ep.p18
      FROM events e
      LEFT JOIN event_pars ep ON ep.event_id = e.id
      WHERE DATE_FORMAT(e.event_date, '%Y-%m') = ?
      ORDER BY e.id DESC
      LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$month]);
  } else {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please provide event_id or month (YYYY-MM).']);
    exit;
  }

  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Not found']);
    exit;
  }

  // pars 배열 구성
  $pars = [];
  for ($i = 1; $i <= 18; $i++) {
    $key = 'p'.$i;
    $pars[] = isset($row[$key]) ? (int)$row[$key] : null;
    unset($row[$key]);
  }

  echo json_encode([
    'ok' => true,
    'event' => [
      'id'          => (int)$row['id'],
      'title'       => $row['title'],
      'event_date'  => $row['event_date'],
      'event_par'   => (int)$row['event_par'],
      'course_name' => $row['course_name'],
      'pars'        => $pars
    ]
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'DB error: '.$e->getMessage()]);
}
