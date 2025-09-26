<?php
// /api/get_weekly_reservations.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php'; // provides $pdo

// -------- helpers --------
function to_minutes($hhmm) {
    // 'HH:MM' -> minutes; with safety
    if (!$hhmm) return null;
    $hhmm = substr($hhmm, 0, 5);
    [$h, $m] = array_map('intval', explode(':', $hhmm));
    return $h * 60 + $m;
}
function close_to_end_minutes($hhmm) {
    // '00:00' or '24:00' => 1440 (자정=다음날 끝)
    if (!$hhmm) return null;
    $hhmm = substr($hhmm, 0, 5);
    if ($hhmm === '24:00') return 1440;
    $m = to_minutes($hhmm);
    return ($m === 0 && $hhmm === '00:00') ? 1440 : $m;
}
function clamp($v, $min, $max) {
    return max($min, min($max, $v));
}

// -------- input --------
$start = $_GET['start'] ?? null;
$end   = $_GET['end'] ?? null;
if (!$start || !$end) {
    echo json_encode(["error" => "start/end required"]);
    exit;
}
// basic sanity
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    echo json_encode(["error" => "invalid date format"]);
    exit;
}

// -------- query reservations --------
// TODO: replace table/column names to your schema
// Assumptions:
//   - table: reservations
//   - columns: date(YYYY-MM-DD), start_time(HH:MM:SS), end_time(HH:MM:SS)
//   - room identifier: room_no (int)  OR  a comma-separated list in room_list
// If your schema differs, adjust SELECT and the parsing in 'collect_rooms' below.
$sql = "
    SELECT
        r.GB_date        AS ymd,
        r.GB_start_time  AS start_time,
        r.GB_end_time    AS end_time,
        r.GB_room_no     AS room_no     
    FROM GB_Reservation r
    WHERE r.GB_date BETWEEN :start AND :end
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':start' => $start, ':end' => $end]);

// -------- collect per-day reservations --------
$rowsByDate = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $ymd = $row['ymd'];
    if (!isset($rowsByDate[$ymd])) $rowsByDate[$ymd] = [];
    $rowsByDate[$ymd][] = $row;
}

// helper to extract room ids from a row
function collect_rooms($row) {
    // prefer explicit room_no if each row is one room
    if (isset($row['room_no']) && strlen((string)$row['room_no'])) {
        return [(string)$row['room_no']];
    }
    // or parse CSV list like "1,2,3"
    if (!empty($row['room_list'])) {
        $parts = array_map('trim', explode(',', $row['room_list']));
        // keep only non-empty numeric-ish
        return array_values(array_filter($parts, fn($v) => $v !== ''));
    }
    // fallback: treat as 1 room unknown
    return ['?']; // will still dedupe per id
}

// -------- build hourly occupancy counts --------
// result: date => "HH:00" => count
$result = [];

foreach ($rowsByDate as $ymd => $list) {
    // prepare a map hourBinMin -> set of room ids occupying that hour window
    $binRooms = []; // e.g.,  660 => set('1','2')  for 11:00–12:00

    foreach ($list as $row) {
        $startMin = to_minutes($row['start_time']);
        $endMin   = close_to_end_minutes($row['end_time']);

        if ($startMin === null || $endMin === null) continue;

        // normalize & clamp to [0, 1440]
        $startMin = clamp($startMin, 0, 1440);
        $endMin   = clamp($endMin,   0, 1440);

        // skip zero/invalid durations
        if ($endMin <= $startMin) continue;

        // which hours (bins of 60) does this reservation overlap?
        // For each hour bin [h, h+60), if overlap > 0 → count it.
        $firstBin = intdiv($startMin, 60) * 60;
        $lastBin  = (intdiv(max($endMin - 1, 0), 60) * 60); // inclusive last bin

        $rooms = collect_rooms($row);

        for ($bin = $firstBin; $bin <= $lastBin; $bin += 60) {
            $binStart = $bin;
            $binEnd   = $bin + 60;

            // overlap check: [startMin, endMin) ∩ [binStart, binEnd) > 0
            $ovStart = max($startMin, $binStart);
            $ovEnd   = min($endMin,   $binEnd);
            if ($ovEnd <= $ovStart) continue; // no overlap

            if (!isset($binRooms[$bin])) $binRooms[$bin] = [];
            // add rooms into set
            foreach ($rooms as $rid) {
                $binRooms[$bin][$rid] = true; // set-like
            }
        }
    }

    // to result: convert bin minutes to "HH:00" with counts
    if (!isset($result[$ymd])) $result[$ymd] = [];
    foreach ($binRooms as $bin => $roomSet) {
        $hh = str_pad((string)intdiv($bin, 60), 2, '0', STR_PAD_LEFT);
        $result[$ymd]["{$hh}:00"] = count($roomSet);
    }
}

echo json_encode($result);