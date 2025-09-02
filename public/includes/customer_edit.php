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

// 3) 그룹 기반 예약 요약(간단 쿼리; 기존 API가 있다면 그걸 호출/공유해도 됨)
$summary = null;
if ($ok && $gid) {
  // 대표 정보 1건
  $st = $pdo->prepare("
    SELECT GB_name AS name, GB_email AS email, GB_date AS date,
           GB_start_time AS start_time, GB_end_time AS end_time
      FROM GB_Reservation
     WHERE Group_id = :gid
     LIMIT 1
  ");
  $st->execute([':gid' => $gid]);
  $head = $st->fetch(PDO::FETCH_ASSOC);

  if ($head) {
    // 방 목록
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
      'date'       => (string)$head['date'],
      'start_time' => $hm($head['start_time']),
      'end_time'   => $hm($head['end_time']),
      'rooms_csv'  => $roomsCsv
    ];
  }
}

// 4) 화면 출력
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>Reservation Self-Service</title>
    <style>
      body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 24px; line-height: 1.5; }
      .card { max-width: 720px; border: 1px solid #ddd; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.04); }
      .muted { color: #666; }
      .error { color: #b00020; font-weight: 600; }
      .ok { color: #0b6; font-weight: 600; }
      .row { display: flex; gap: 16px; flex-wrap: wrap; align-items: center; }
      .row > div { flex: 1 1 220px; }
      .btn { display:inline-block; padding:10px 14px; border-radius:8px; border:1px solid #ccc; background:#f7f7f7; text-decoration:none; color:#222; }
      .btn.primary { background:#0b6; border-color:#0a5; color:#fff; }
      .btn.danger  { background:#b00020; border-color:#9a001c; color:#fff; }
      .btn:disabled { opacity: .5; pointer-events: none; }
      hr { border: none; border-top: 1px solid #eee; margin: 20px 0; }
      label { display:block; font-size: 14px; margin-bottom: 6px; color:#333; }
      input, select { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ccc; }
      .notice { background: #fff8e1; border:1px solid #ffe0a3; padding: 12px; border-radius: 8px; }
    </style>
  </head>
  <body>
    <div class="card">
      <h2>Reservation Self-Service</h2>

<?php if (!$ok): ?>
      <p class="error">This link cannot be used.</p>
      <ul class="muted">
        <?php if ($code === 'invalid'): ?><li>Invalid token.</li><?php endif; ?>
        <?php if ($code === 'not_found'): ?><li>Token not found.</li><?php endif; ?>
        <?php if ($code === 'expired'): ?><li>The token has expired (within 24 hours of the start time).</li><?php endif; ?>
        <?php if ($code === 'no_group'): ?><li>Reservation group not found.</li><?php endif; ?>
      </ul>
      <p>Please call <a href="tel:403-455-4952">403-455-4952</a> for assistance.</p>

<?php else: ?>
      <?php if (!$summary): ?>
        <p class="error">Reservation not found for this token.</p>
        <p>Please call <a href="tel:403-455-4952">403-455-4952</a>.</p>

      <?php else: ?>
        <p class="ok">Your token is valid.</p>
        <?php if (!empty($exp)): ?>
          <p class="muted">You can make changes until: <strong><?=htmlspecialchars($exp, ENT_QUOTES, 'UTF-8')?></strong></p>
        <?php endif; ?>

        <h3>Current Reservation</h3>
        <div class="row">
          <div><label>Date</label><div><?=htmlspecialchars($summary['date'])?></div></div>
          <div><label>Time</label><div><?=htmlspecialchars($summary['start_time'])?> ~ <?=htmlspecialchars($summary['end_time'])?></div></div>
          <div><label>Rooms</label><div><?=htmlspecialchars($summary['rooms_csv'])?></div></div>
        </div>
        <div class="row">
          <div><label>Name</label><div><?=htmlspecialchars($summary['name'])?></div></div>
          <div><label>Email</label><div><?=htmlspecialchars($summary['email'])?></div></div>
        </div>

        <hr>

        <div class="notice">
          <strong>Note:</strong> Online changes are allowed until 24 hours before the start time.
          Within 24 hours, please call <a href="tel:403-455-4952">403-455-4952</a>.
        </div>

        <hr>

        <!-- 다음 단계에서 실제 API를 만들 예정 (지금은 버튼만 보여주기) -->
        <div class="row">
          <form method="post" action="/api/customer_update_reservation.php" style="display:flex; gap:10px; flex-wrap:wrap;">
            <input type="hidden" name="token" value="<?=htmlspecialchars($token, ENT_QUOTES, 'UTF-8')?>">
            <!-- 최소 스켈레톤: 나중에 select/date/time/rooms 컨트롤을 채우자 -->
            <div>
              <label for="date">New Date</label>
              <input type="date" id="date" name="date" required>
            </div>
            <div>
              <label for="start">New Start</label>
              <input type="time" id="start" name="start_time" required>
            </div>
            <div>
              <label for="end">New End</label>
              <input type="time" id="end" name="end_time" required>
            </div>
            <div>
              <label for="rooms">Rooms (CSV)</label>
              <input type="text" id="rooms" name="rooms_csv" placeholder="e.g., 1,2" required>
            </div>
            <div style="align-self:flex-end;">
              <button class="btn primary" type="submit">Update reservation</button>
            </div>
          </form>

          <form method="post" action="/api/customer_cancel_reservation.php" onsubmit="return confirm('Cancel this reservation?');">
            <input type="hidden" name="token" value="<?=htmlspecialchars($token, ENT_QUOTES, 'UTF-8')?>">
            <button class="btn danger" type="submit">Cancel reservation</button>
          </form>
        </div>
      <?php endif; ?>
<?php endif; ?>

    </div>

<script>
(function () {
  const $ = (s) => document.querySelector(s);

  function lock(f, on=true){
    f.querySelectorAll('button, input, select, textarea').forEach(el => el.disabled = on);
  }

  // UPDATE form
  const updateForm = document.querySelector('form[action$="customer_update_reservation.php"]');
  if (updateForm) {
    updateForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      lock(updateForm, true);

      const token = updateForm.querySelector('input[name="token"]').value.trim();
      const date  = $('#date')?.value || '';
      const start = $('#start')?.value || '';
      const end   = $('#end')?.value || '';
      const roomsCsv = $('#rooms')?.value || '';

      if (!date || !start || !end || !roomsCsv) {
        alert('Please fill in all fields.');
        lock(updateForm, false);
        return;
      }

      const fd = new FormData();
      fd.append('token', token);
      fd.append('date', date);
      fd.append('start_time', start);
      fd.append('end_time', end);
      fd.append('rooms_csv', roomsCsv);

      try {
        const res = await fetch('/public/api/customer_update_reservation.php', { method: 'POST', body: fd });
        const js  = await res.json();
        if (!res.ok || !js.success) {
          alert('Update failed: ' + (js.error || res.status));
          lock(updateForm, false);
          return;
        }
        alert('Your reservation has been updated. A confirmation email has been sent.');
        setTimeout(()=>location.reload(), 1000);
      } catch (err) {
        alert('Network error occurred.');
        lock(updateForm, false);
      }
    });
  }

  // CANCEL form
  const cancelForm = document.querySelector('form[action$="customer_cancel_reservation.php"]');
  if (cancelForm) {
    cancelForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!confirm('Are you sure you want to cancel this reservation?')) return;

      lock(cancelForm, true);

      const token = cancelForm.querySelector('input[name="token"]').value.trim();
      const fd = new FormData(); fd.append('token', token);

      try {
        const res = await fetch('/public/api/customer_cancel_reservation.php', { method: 'POST', body: fd });
        const js  = await res.json();
        if (!res.ok || !js.success) {
          alert('Cancel failed: ' + (js.error || res.status));
          lock(cancelForm, false);
          return;
        }
        alert('Your reservation has been canceled. A confirmation email has been sent.');
        setTimeout(()=>location.href='/', 1000);
      } catch (err) {
        alert('Network error occurred.');
        lock(cancelForm, false);
      }
    });
  }
})();
</script>

  </body>
</html>
