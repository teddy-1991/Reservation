<?php
// /api/get_weekly_reservations.php — 운영일 귀속 (00~03시는 24~27시) + 범위 클램프
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

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
    // 1440 → "24:00", 1500 → "25:00", 1560 → "26:00" ...
    $h = intdiv($min, 60);
    return sprintf('%02d:00', $h);
}
function add_rooms_to_bin(array &$map, string $ymd, int $binMin, array $rooms): void {
    if (!isset($map[$ymd])) $map[$ymd] = [];
    if (!isset($map[$ymd][$binMin])) $map[$ymd][$binMin] = [];
    foreach ($rooms as $rid) {
        $map[$ymd][$binMin][$rid] = true; // set-like
    }
}
function ymdPrev(string $ymd): string {
    $d = new DateTime($ymd);
    $d->modify('-1 day');
    return $d->format('Y-m-d');
}

// ---------------- input ----------------
$start = $_GET['start'] ?? null;
$end   = $_GET['end']   ?? null;

if (!$start || !$end) {
    echo json_encode(['error' => 'start/end required']);
    exit;
}

// ---------------- query ----------------
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
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $rowsByDate[$row['ymd']][] = $row;
}

$binRoomsByDate = [];

foreach ($rowsByDate as $ymd => $list) {
    foreach ($list as $row) {
        $s = to_minutes($row['start_time']);
        $e = to_minutes($row['end_time']);
        if ($s === null || $e === null) continue;

        // 1) 종료가 시작과 같거나 더 이르면 익일로 간주(스필)
        if ($e <= $s) $e += 1440;

        // 2) 한 시간 단위 bin
        $rooms = [(string)$row['room_no']];

        for ($bin = intdiv($s, 60) * 60; $bin < $e; $bin += 60) {
            $targetYmd = $ymd;
            $binMin    = $bin;

            // 3) 새벽(00~03)은 기본적으로 전날 24~27시로 귀속
            if ($bin < 240) { // 0, 60, 120, 180
                $prev = ymdPrev($ymd);

                if ($prev >= $start) {
                    // 전날이 요청 범위 안이면 전날로 귀속
                    $targetYmd = $prev;
                    $binMin   += 1440; // 00→24, 01→25, 02→26, 03→27
                } else {
                    // ✅ 전날이 범위 밖(weekStart-1 등)이라면,
                    //    응답에 범위 밖 날짜가 생기지 않도록 현재 날짜에 24~27시로 붙인다.
                    $targetYmd = $ymd;
                    $binMin   += 1440; // 00→24, ...
                }
            }

            add_rooms_to_bin($binRoomsByDate, $targetYmd, $binMin, $rooms);
        }
    }
}

// ---------------- result ----------------
$result = [];
foreach ($binRoomsByDate as $d => $bins) {
    foreach ($bins as $binMin => $set) {
        $hh = label_hh($binMin); // "09:00", "24:00", "25:00" ...
        if (!isset($result[$d])) $result[$d] = [];
        $result[$d][$hh] = count($set);
    }
}

// (선택) 날짜 정렬/키 정렬 원하면 여기서 ksort
// foreach ($result as &$arr) { ksort($arr, SORT_NATURAL); } unset($arr);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
