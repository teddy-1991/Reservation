<?php
// /bookingtest/public/api/competition_get.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../includes/config.php'; // $pdo
session_start();

/* ────────────────────────────────────────────────────────────
 * Access control
 * ──────────────────────────────────────────────────────────── */
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
  exit;
}

/* ────────────────────────────────────────────────────────────
 * Config
 * ──────────────────────────────────────────────────────────── */
const PLAYERS_TABLE = 'players'; // 참가자 테이블 사용 시 유지. 미사용이면 fetchRoster()가 빈 배열 반환.

/* ────────────────────────────────────────────────────────────
 * Helpers
 * ──────────────────────────────────────────────────────────── */
function out(array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

/** month=YYYY-MM → [start, end) (America/Edmonton) */
function monthRange(string $yyyy_mm): array {
  $tz = new DateTimeZone('America/Edmonton');
  if (!preg_match('/^\d{4}-\d{2}$/', $yyyy_mm)) {
    $now = new DateTime('now', $tz);
    $yyyy_mm = $now->format('Y-m');
  }
  $start = DateTime::createFromFormat('Y-m-d H:i:s', $yyyy_mm . '-01 00:00:00', $tz);
  $end   = (clone $start)->modify('first day of next month');
  return [$start, $end];
}

function fetchEventById(PDO $pdo, int $eventId): ?array {
  $sql = "
    SELECT e.id, e.title, e.event_date, e.event_par, e.course_name,
           ep.par_total,
           ep.p1,ep.p2,ep.p3,ep.p4,ep.p5,ep.p6,ep.p7,ep.p8,ep.p9,
           ep.p10,ep.p11,ep.p12,ep.p13,ep.p14,ep.p15,ep.p16,ep.p17,ep.p18
    FROM events e
    LEFT JOIN event_pars ep ON ep.event_id = e.id
    WHERE e.id = ?
    LIMIT 1
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$eventId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function fetchLatestEventOfMonth(PDO $pdo, string $yyyy_mm): ?array {
  [$start, $end] = monthRange($yyyy_mm);
  $sql = "
    SELECT e.id, e.title, e.event_date, e.event_par, e.course_name,
           ep.par_total,
           ep.p1,ep.p2,ep.p3,ep.p4,ep.p5,ep.p6,ep.p7,ep.p8,ep.p9,
           ep.p10,ep.p11,ep.p12,ep.p13,ep.p14,ep.p15,ep.p16,ep.p17,ep.p18
    FROM events e
    LEFT JOIN event_pars ep ON ep.event_id = e.id
    WHERE e.event_date >= :start AND e.event_date < :end
    ORDER BY e.event_date DESC, e.id DESC
    LIMIT 1
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':start' => $start->format('Y-m-d H:i:s'),
    ':end'   => $end->format('Y-m-d H:i:s'),
  ]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function fetchLatestEvent(PDO $pdo): ?array {
  $sql = "
    SELECT e.id, e.title, e.event_date, e.event_par, e.course_name,
           ep.par_total,
           ep.p1,ep.p2,ep.p3,ep.p4,ep.p5,ep.p6,ep.p7,ep.p8,ep.p9,
           ep.p10,ep.p11,ep.p12,ep.p13,ep.p14,ep.p15,ep.p16,ep.p17,ep.p18
    FROM events e
    LEFT JOIN event_pars ep ON ep.event_id = e.id
    ORDER BY e.event_date DESC, e.id DESC
    LIMIT 1
  ";
  $stmt = $pdo->query($sql);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

/** row → pars[18] 추출 */
function buildPars(array &$row): array {
  $pars = [];
  for ($i = 1; $i <= 18; $i++) {
    $k = 'p' . $i;
    $pars[] = array_key_exists($k, $row) && $row[$k] !== null ? (int)$row[$k] : null;
    unset($row[$k]); // 메모리 정리
  }
  return $pars;
}

/** event_pars.par_total 우선, 없으면 event_par, 둘 다 없으면 배열 합(누락 있으면 null) */
function resolveParTotal(?int $par_total, ?int $event_par, array $pars): ?int {
  if ($par_total !== null) return (int)$par_total;
  if ($event_par !== null) return (int)$event_par;
  // 전부 채워져 있으면 합산
  foreach ($pars as $v) { if ($v === null) return null; }
  return array_sum($pars);
}

/** 참가자(옵션) — 사용 안 하면 빈 배열 */
function fetchRoster(PDO $pdo, int $eventId): array {
  // 테이블 없으면 try/catch로 빈 배열
  try {
    $sql = "SELECT id, display_name AS name, phone, email
            FROM " . PLAYERS_TABLE . "
            WHERE event_id = ?
            ORDER BY id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$eventId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $rows ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

/* ────────────────────────────────────────────────────────────
 * Main
 * ──────────────────────────────────────────────────────────── */
try {
  $event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
  $month    = isset($_GET['month']) ? trim((string)$_GET['month']) : '';

  if ($month === '') { // 기본: 현재 달
    $tz = new DateTimeZone('America/Edmonton');
    $month = (new DateTime('now', $tz))->format('Y-m');
  }

  // 1) 우선순위: event_id > month > 최신 1건
  $row = null;
  if ($event_id > 0) {
    $row = fetchEventById($pdo, $event_id);
  } else {
    $row = fetchLatestEventOfMonth($pdo, $month) ?: fetchLatestEvent($pdo);
  }

  if (!$row) out(['ok' => true, 'event' => null]); // 프론트 빈 상태 처리

  // 2) 파/총합
  $pars = buildPars($row);
  $eventId   = (int)$row['id'];
  $eventPar  = isset($row['event_par']) ? (int)$row['event_par'] : null;
  $parTotal  = resolveParTotal(isset($row['par_total']) ? (int)$row['par_total'] : null, $eventPar, $pars);

  // 3) 응답(참가자는 그대로 유지하되, 지금은 안 써도 됨)
  $payload = [
    'ok'    => true,
    'event' => [
      'id'          => $eventId,
      'title'       => (string)$row['title'],
      'event_date'  => (string)$row['event_date'],   // YYYY-MM-DD
      'course_name' => (string)$row['course_name'],
      'event_par'   => $eventPar,
      'par_total'   => $parTotal,
      'pars'        => $pars
    ],
    'roster' => fetchRoster($pdo, $eventId),
  ];

  out($payload);
} catch (Throwable $e) {
  out(['ok' => false, 'error' => 'DB error: ' . $e->getMessage()], 500);
}
