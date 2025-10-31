<?php
// /api/get_weekly_reservations.php — 운영일 귀속(스필만 24+), 당일 00~03시는 당일로 유지
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php'; // $pdo

function hhmm5(?string $t): ?string { return $t ? substr($t,0,5) : null; }
function to_minutes(?string $hhmm): ?int {
  if (!$hhmm) return null;
  $p = hhmm5($hhmm);
  if (!$p || !preg_match('/^\d{2}:\d{2}$/',$p)) return null;
  [$h,$m] = array_map('intval', explode(':',$p));
  return $h*60 + $m;
}
function label_hh(int $min): string { return sprintf('%02d:00', intdiv($min,60)); }
function add_rooms_to_bin(array &$map, string $ymd, int $binMin, array $rooms): void {
  $map[$ymd][$binMin] ??= [];
  foreach ($rooms as $rid) $map[$ymd][$binMin][$rid] = true;
}

$start = $_GET['start'] ?? null;
$end   = $_GET['end']   ?? null;
if (!$start || !$end) { echo json_encode(['error'=>'start/end required']); exit; }

$sql = "
  SELECT r.GB_date AS ymd, r.GB_start_time AS start_time, r.GB_end_time AS end_time, r.GB_room_no AS room_no
  FROM GB_Reservation r
  WHERE r.GB_date BETWEEN :start AND :end
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':start'=>$start, ':end'=>$end]);

$rowsByDate = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $rowsByDate[$row['ymd']][] = $row;

$binRoomsByDate = [];

foreach ($rowsByDate as $ymd => $list) {
  foreach ($list as $row) {
    $s = to_minutes($row['start_time']);
    $e = to_minutes($row['end_time']);
    if ($s === null || $e === null) continue;

    // ▶ 자정 넘김 예약만 스필 처리
    $spill = false;
    if ($e <= $s) { $e += 1440; $spill = true; }

    $rooms = [(string)$row['room_no']];

    // 1) 당일 구간: s ~ min(e,1440)  (예: 00~03시로 시작한 ‘당일’ 예약 포함)
    $endToday = min($e, 1440);
    for ($bin = intdiv($s,60)*60; $bin < $endToday; $bin += 60) {
      add_rooms_to_bin($binRoomsByDate, $ymd, $bin, $rooms);  // ← 당일 00~03시는 당일에 남김
    }

    // 2) 스필 구간: 1440~e  (전날에서 넘친 부분만 24:00+로 ‘같은 날짜(전날)’에 기록)
    if ($spill && $e > 1440) {
      for ($bin = 1440; $bin < $e; $bin += 60) {
        add_rooms_to_bin($binRoomsByDate, $ymd, $bin, $rooms); // ← 24:00,25:00…을 ‘해당 ymd’에 붙임
      }
    }
  }
}

$result = [];
foreach ($binRoomsByDate as $d => $bins) {
  foreach ($bins as $binMin => $set) {
    $result[$d][ label_hh($binMin) ] = count($set);
  }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
