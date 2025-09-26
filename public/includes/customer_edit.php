<?php
// /public/reservation/edit.php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('America/Edmonton');

require_once __DIR__ . '/../includes/config.php';     // $pdo
require_once __DIR__ . '/../includes/functions.php';  // validate_edit_token(...)

// 1) 토큰 수집
$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
if ($token === '') {
  http_response_code(400);
  echo '<h2>Invalid request</h2><p>Missing token.</p>';
  exit;
}

// 2) 토큰 검증 (유효성 + group_id만)
$check = validate_edit_token($pdo, $token);
$ok    = $check['ok'] ?? false;
$code  = $check['code'] ?? 'invalid';
$gid   = $check['group_id'] ?? null;
$exp   = $check['expires_at'] ?? null;

// 3) 그룹 기반 예약 요약
$summary = null;
if ($ok && $gid) {
  $st = $pdo->prepare("
    SELECT GB_name AS name, GB_email AS email, GB_date AS date, GB_phone AS phone,
           GB_start_time AS start_time, GB_end_time AS end_time
      FROM GB_Reservation
     WHERE Group_id = :gid
     LIMIT 1
  ");
  $st->execute([':gid' => $gid]);
  $head = $st->fetch(PDO::FETCH_ASSOC);

  if ($head) {
    $st2 = $pdo->prepare("
      SELECT GROUP_CONCAT(DISTINCT GB_room_no ORDER BY GB_room_no SEPARATOR ',') AS rooms_csv
        FROM GB_Reservation
       WHERE Group_id = :gid
    ");
    $st2->execute([':gid' => $gid]);
    $roomsCsv = (string)($st2->fetchColumn() ?: '');

    $hm = fn($t) => substr(trim((string)$t), 0, 5);
    $summary = [
      'name'       => (string)$head['name'],
      'email'      => (string)$head['email'],
      'phone'      => (string)($head['phone'] ?? ''),
      'date'       => (string)$head['date'],
      'start_time' => $hm($head['start_time']),
      'end_time'   => $hm($head['end_time']),
      'rooms_csv'  => $roomsCsv
    ];
  }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>Reservation Self-Service</title>
    <style>
      :root{
        --bg:#f6f8fb; --paper:#fff; --ink:#0f172a; --muted:#6b7280;
        --accent:#2563eb; --border:#e5e7eb;
      }
      *{box-sizing:border-box}
      body{
        margin:0;background:var(--bg);color:var(--ink);
        font:16px/1.6 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
      }
      a{color:var(--accent);text-decoration:none}
      a:hover{text-decoration:underline}

      /* 상단 브랜드 바 */
      .ss-brand{
        background:linear-gradient(90deg,#0ea5e9,#38bdf8);
        color:#fff;border-bottom:1px solid rgba(0,0,0,.06);
      }
      .ss-brand .ss-title{
        max-width:1100px;margin:0 auto;padding:14px 24px;text-align:center;
        font-weight:700; letter-spacing:.2px;
      }

      /* 가운데 정렬 컨테이너 */
      .ss-wrap{ max-width:1100px; margin:24px auto; padding:0 24px; }

      /* 메인 보드(유일 카드) */
      .ss-panel{
        background:var(--paper);
        border:1px solid var(--border);
        border-radius:16px;
        box-shadow:0 10px 20px rgba(0,0,0,.04);
        padding:28px;
      }
      .ss-panel h1{ margin:0 0 8px; font-size:22px; }
      .ss-panel .ss-sub{ margin:4px 0 18px; color:var(--muted); font-size:14px; }

      /* 본문 그리드/폼 */
      .row{ display:flex; gap:16px; flex-wrap:wrap; align-items:center; }
      .row > div{ flex:1 1 220px; }
      label{ display:block; font-size:14px; margin:6px 0; color:#333; }
      input, select{
        width:100%; padding:10px; border-radius:8px; border:1px solid #ccc;
      }

      .muted{ color:#666; }
      .error{ color:#b00020; font-weight:600; }
      .ok{ color:#0b6; font-weight:600; }
      .notice{ background:#fff8e1; border:1px solid #ffe0a3; padding:12px; border-radius:8px; }

      .btn{ display:inline-block; padding:10px 14px; border-radius:8px;
            border:1px solid #ccc; background:#f7f7f7; color:#222; cursor:pointer; }
      .btn.primary{ background:#0b6; border-color:#0a5; color:#fff; }
      .btn.danger{ background:#b00020; border-color:#9a001c; color:#fff; }
      .btn:disabled{ opacity:.5; pointer-events:none; }
      .btnbar{ display:flex; justify-content:center; align-items:center; gap:12px; flex-wrap:wrap; margin-top:12px; }

      hr{ border:none; border-top:1px solid var(--border); margin:20px 0; }

      /* Rooms pill buttons */
      .room-pills{display:flex;gap:8px;flex-wrap:wrap}
      .room-pill{display:inline-flex;align-items:center;position:relative}
      .room-pill input{ position:absolute; opacity:0; width:0; height:0; }
      .room-pill span{
        display:inline-block;padding:6px 12px;border:1px solid #ddd;
        border-radius:999px;background:#fff;cursor:pointer;user-select:none;line-height:1
      }
      .room-pill input:checked + span{ background:#0d6efd;color:#fff;border-color:#0d6efd }
      .room-pill input:focus + span{ outline:2px solid #80bdff; outline-offset:2px }

      /* 풋터 */
      .ss-footer{
        text-align:center; color:#64748b; font-size:13px;
        margin:28px 0 40px;
      }
      .under-form { width:100%; margin-top: 12px; }

    </style>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  </head>
  <body>
    <!-- 브랜드 바 -->
    <div class="ss-brand">
      <div class="ss-title">SPORTECH INDOOR GOLF</div>
    </div>

    <!-- 가운데 컨테이너 -->
    <div class="ss-wrap">
      <main class="ss-panel">
        <h1>Reservation Self-Service</h1>

<?php if (!$ok): ?>
        <p class="error">This link cannot be used.</p>
        <ul class="muted">
          <?php if ($code === 'invalid'): ?><li>Invalid token.</li><?php endif; ?>
          <?php if ($code === 'not_found'): ?><li>Token not found.</li><?php endif; ?>
          <?php if ($code === 'expired'): ?><li>The token has expired (within 24 hours of the start time).</li><?php endif; ?>
          <?php if ($code === 'no_group'): ?><li>Reservation group not found.</li><?php endif; ?>
        </ul>
        <p>Please call <a href="tel:403-455-4951">403-455-4951</a> for assistance.</p>

<?php else: ?>
  <?php if (!$summary): ?>
        <p class="error">Reservation not found for this token.</p>
        <p>Please call <a href="tel:403-455-4951">403-455-4951</a>.</p>

  <?php else: ?>
        <p class="ok">Your token is valid.</p>
        <?php if (!empty($exp)): ?>
          <p class="muted">You can make changes until: <strong><?=htmlspecialchars($exp, ENT_QUOTES, 'UTF-8')?></strong></p>
        <?php endif; ?>

        <div class="mb-3">
          <h4 class="mb-2">Current Reservation</h4>

          <!-- 이름/연락처 -->
          <div class="row g-3">
            <div class="col-md-4">
              <div class="text-bold small"><strong>Name</strong></div>
              <div class="fw-semibold"><?= htmlspecialchars($summary['name'] ?? '') ?></div>
            </div>
            <div class="col-md-4">
              <div class="text-bold small"><strong>Phone</strong></div>
              <div class="fw-semibold"><?= htmlspecialchars($summary['phone'] ?? '') ?></div>
            </div>
            <div class="col-md-4">
              <div class="text-bold small"><strong>Email</strong></div>
              <div class="fw-semibold"><?= htmlspecialchars($summary['email'] ?? '') ?></div>
            </div>
          </div>

          <hr>

          <!-- 날짜/시간/방 -->
          <div class="row g-3">
            <div class="col-md-4">
              <div class="text-bold small"><strong>Rooms</strong></div>
              <div class="fw-semibold"><?= htmlspecialchars($summary['rooms_csv'] ?? '') ?></div>
            </div>
            <div class="col-md-4">
              <div class="text-bold small"><strong>Date</strong></div>
              <div class="fw-semibold"><?= htmlspecialchars($summary['date'] ?? '') ?></div>
            </div>
            <div class="col-md-4">
              <div class="text-bold small"><strong>Time</strong></div>
              <div class="fw-semibold">
                <?= htmlspecialchars(($summary['start_time'] ?? '') . ' ~ ' . ($summary['end_time'] ?? '')) ?>
              </div>
            </div>
          </div>
        </div>

        <hr>

        <!-- 업데이트/취소 폼 -->
        <!-- 폼 전용 래퍼 (row 아님) -->
        <div>
          <form method="post" action="../api/customer_reservation/customer_update_reservation.php" id="updateForm" style="margin:0;">
            <div class="row form-two-col">
              <input type="hidden" name="token" value="<?= htmlspecialchars($token ?? '', ENT_QUOTES, 'UTF-8') ?>">
              <!-- New Date -->
              <div class="field-full" style="min-width:240px">
                <label for="date-picker">New Date</label>
                <input type="text" id="date-picker" placeholder="Select date" readonly>
                <input type="hidden" id="new_date" name="date" value="<?= htmlspecialchars($summary['date'] ?? '') ?>">
              </div>

              <!-- Rooms -->
              <?php
                $roomsCsv = $summary['rooms_csv'] ?? '';
                $selected = array_filter(array_map('intval', array_map('trim', explode(',', $roomsCsv))));
                $ALL_ROOMS = range(1, 5);
              ?>
              <div class="row g-2" style="margin:8px 0;">
                <div class="col" style="min-width:260px">
                  <label>Rooms</label>
                  <div class="room-pills">
                    <?php foreach ($ALL_ROOMS as $r): ?>
                      <label class="room-pill">
                        <input type="checkbox" name="GB_room_no[]" value="<?= $r ?>" <?= in_array($r, $selected, true) ? 'checked' : '' ?>>
                        <span>Room <?= $r ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>

            <!-- 시간 선택 -->
            <div class="row g-2" style="margin:8px 0;">
              <div class="col" style="min-width:160px">
                <label for="startTime">New Start Time</label>
                <select id="startTime" name="start_time" required>
                  <option disabled selected>Select a date first</option>
                </select>
              </div>
              <div class="col" style="min-width:160px">
                <label for="endTime">New End Time</label>
                <select id="endTime" name="end_time" required>
                  <option disabled selected>Select a start time first</option>
                </select>
              </div>
            </div>
          </form>

          <!-- 취소 폼은 숨김으로 유지 -->
          <form method="post" action="../api/customer_reservation/customer_cancel_reservation.php"
                id="cancelForm" onsubmit="return confirm('Cancel this reservation?');" style="margin:0;">
            <input type="hidden" name="token" value="<?=htmlspecialchars($token, ENT_QUOTES, 'UTF-8')?>">
          </form>
        </div>

        <!-- ✅ 버튼 바: 폼 ‘아래’로 분리 -->
        <div class="btnbar center-buttons under-form">
          <button type="submit" class="btn primary px-4" form="updateForm" id="btnUpdate">Update reservation</button>
          <button type="submit" class="btn danger px-4" form="cancelForm" id="btnCancel">Cancel reservation</button>
        </div>

  <?php endif; ?>
<?php endif; ?>

      </main>

      <!-- 풋터 -->
      <div class="ss-footer">
        SPORTECH INDOOR GOLF (SIMULATOR) · #120 1642 10th Avenue SW, Calgary, AB T3C0J5
      </div>
    </div>

    <script>
      window.ALL_TIMES = <?= json_encode(generate_time_slots('00:00', '23:59')); ?>;
    </script>
    <script src="../assets/share.js"></script>
    <script src="../assets/selfservice.js"></script>
  </body>
</html>
