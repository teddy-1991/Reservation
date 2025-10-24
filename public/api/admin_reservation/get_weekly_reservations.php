<?php
// /api/get_weekly_reservations.php  — Sportech (Virtual Tee Up 방식으로 통일)
declare(strict_types=1);

// 디버그 모드: ?debug=1 로 호출하면 콘솔 로그 스니펫을 반환
$debug = isset($_GET['debug']) && $_GET['debug'] == '1';
if (!$debug) header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php'; // $pdo

// ---------------- helpers ----------------
function hhmm5(?string $t): ?string {
    if (!$t) return null;
    return substr($t, 0, 5);
}
function to_minutes(?string $hhmm): ?int {
    if (!$hhmm) return null;
    $p = hhmm5($hhmm);
    if (!$p || !preg_match('/^\d{2}:\d{2}$/', $p)) return null;
    [$h,$m] = array_map('intval', explode(':', $p));
    return $h * 60 + $m;
}
function label_hh(int $min): string {
    // 24:00 전용 라인: 1440분은 '24:00'으로 그대로 노출
    if ($min === 1440) return '24:00';
    // 그 외는 0~23시로 포맷
    $h = intdiv($min, 60);
    if ($h >= 24) $h %= 24;
    return sprintf('%02d:00', $h);
}
function add_rooms_to_bin(array &$map, string $ymd, int $binMin, array $rooms): void {
    if (!isset($map[$ymd])) $map[$ymd] = [];
    if (!isset($map[$ymd][$binMin])) $map[$ymd][$binMin] = [];
    foreach ($rooms as $rid) $map[$ymd][$binMin][$rid] = true; // set-like
}

// ---------------- input ----------------
$start = $_GET['start'] ?? null;
$end   = $_GET['end']   ?? null;

if (!$start || !$end) {
    echo json_encode(['error' => 'start/end required']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    echo json_encode(['error' => 'invalid date format']);
    exit;
}

// ---------------- query ----------------
// GB_Reservation 스키마 가정: GB_date(YYYY-MM-DD), GB_start_time(HH:MM), GB_end_time(HH:MM), GB_room_no(int)
$sql = "
    SELECT
        r.GB_date       AS ymd,
        r.GB_start_time AS start_time,
        r.GB_end_time   AS end_time,
        r.GB_room_no    AS room_no
    FROM GB_Reservation r
    WHERE r.GB_date BETWEEN :start AND :end
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':start' => $start, ':end' => $end]);

// ---------------- build ----------------
$rowsByDate = [];
$rawRows = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $rowsByDate[$row['ymd']][] = $row;
    $rawRows[] = $row;
}

$binRoomsByDate = [];
$steps = []; // 디버그용

foreach ($rowsByDate as $ymd => $list) {
    foreach ($list as $row) {
        $s = to_minutes($row['start_time']);
        $e = to_minutes($row['end_time']);
        if ($s === null || $e === null) continue;

        // ✅ 스필 처리: 종료가 시작보다 같거나 이하면 익일로 간주 (예: 23:30→01:00)
        $spill = false;
        if ($e <= $s) { $e += 1440; $spill = true; }

        // 한 행 = 한 개 룸 기준
        $rooms = [(string)$row['room_no']];

        // 당일 구간: s ~ min(e, 1440) 를 60분 bin으로 채움
        $endToday = min($e, 1440);
        $todayBins = [];
        for ($bin = intdiv($s, 60) * 60; $bin < $endToday; $bin += 60) {
            add_rooms_to_bin($binRoomsByDate, $ymd, $bin, $rooms);
            $todayBins[] = $bin;
        }

        // ✅ 스필이 있으면 당일의 "24:00" 마커에 점유 기록 (다음날 시간대를 여기서 쪼개지 않음)
        $spillBins = [];
        if ($e > 1440) {
            add_rooms_to_bin($binRoomsByDate, $ymd, 1440, $rooms);
            $spillBins[] = 1440; // '24:00'
        }

        // 디버그용 스텝 기록
        $steps[] = [
            'ymd'        => $ymd,
            'start'      => hhmm5($row['start_time']),
            'end'        => hhmm5($row['end_time']),
            's_min'      => $s,
            'e_min'      => $e,
            'spill'      => $spill,
            'today_bins' => array_map('label_hh', $todayBins),
            'spill_bins' => array_map('label_hh', $spillBins),
            'room'       => (string)$row['room_no'],
        ];
    }
}

// ---------------- result ----------------
// date => "HH:00" | "24:00" => count(동시간대 점유 룸 수)
$result = [];
foreach ($binRoomsByDate as $d => $bins) {
    foreach ($bins as $binMin => $set) {
        $hh = label_hh($binMin);
        if (!isset($result[$d])) $result[$d] = [];
        $result[$d][$hh] = count($set);
    }
}

// ---------------- output ----------------
if ($debug) {
    echo "<script>console.group('%cWeekly Reservations Debug','color:#0b93f6;font-weight:bold');";
    echo "console.log('Range:', " . json_encode([$start, $end]) . ");";
    echo "console.table(" . json_encode($rawRows, JSON_UNESCAPED_UNICODE) . ");";
    echo "console.table(" . json_encode($steps, JSON_UNESCAPED_UNICODE) . ");";
    echo "console.log('Final result:', " . json_encode($result, JSON_UNESCAPED_UNICODE) . ");";
    echo "console.groupEnd();</script>";
    exit;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
