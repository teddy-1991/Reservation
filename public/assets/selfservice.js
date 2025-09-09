// /selfservice.js
(() => {
  const $ = (s) => document.querySelector(s);
  function lock(f, on = true) {
    if (!f) return;
    f.querySelectorAll('button, input, select, textarea').forEach(el => (el.disabled = on));
  }

  // ===== Date picker (share.js 재사용) =====
  const pickerInput = $('#date-picker');
  const hiddenDate  = $('#new_date');
  if (pickerInput && hiddenDate && typeof setupDatePicker === 'function' && typeof toYMD === 'function') {
    const today = new Date(); today.setHours(0,0,0,0);
    const max   = new Date(today); max.setDate(max.getDate() + 28);

    // 기본값: hidden value(있으면 예약일, 없으면 오늘)
    const defStr  = hiddenDate.value || '';
    const defDate = defStr ? new Date(defStr) : today;

    const fp = setupDatePicker((d) => {
      if (d) hiddenDate.value = toYMD(d);
    }, {
      minDate: 'today',
      maxDate: toYMD(max)
    });

    // 초기값 세팅
    fp.setDate(defDate, true);
    if (!hiddenDate.value) hiddenDate.value = toYMD(defDate);
  }

  // ===== UPDATE submit =====
const updateForm = document.querySelector('form[action$="customer_update_reservation.php"]');
if (updateForm) {
  updateForm.addEventListener('submit', async (e) => {
    e.preventDefault();

    // ✅ 먼저 FormData를 만든다 (disabled 되기 전에!)
    const fd = new FormData(updateForm);

    // 날짜/시간 확정 세팅
    const dateVal  = document.getElementById('new_date')?.value || '';
    const startVal = document.getElementById('startTime')?.value || '';
    const endVal   = document.getElementById('endTime')?.value || '';
    const phoneVal = document.getElementById('GB_phone')?.value?.trim() || '';

    fd.set('date', dateVal);
    fd.set('start_time', startVal);
    fd.set('end_time', endVal);
    fd.set('GB_phone', phoneVal);


    // rooms_csv도 함께
    const rooms = Array.from(document.querySelectorAll('input[name="GB_room_no[]"]:checked'))
        .map(el => el.value).join(',');
    fd.set('rooms_csv', rooms);

    // (디버그) payload 확인 — 여기서 token 키가 보여야 함
    console.log('update payload =>', Object.fromEntries(fd));

    if (!dateVal || !startVal || !endVal) {
        alert('Please select date, start, and end time.');
        return; // 아직 잠그지 않았으니 그냥 종료
    }

    // ✅ 이제서야 "버튼만" 잠그기
    const disable = (on=true) => updateForm.querySelectorAll('button').forEach(b => b.disabled = on);
    disable(true);

    try {
        const endpoint = updateForm.getAttribute('action') || '../api/customer_update_reservation.php';
        const res  = await fetch(endpoint, { method: 'POST', body: fd });
        // 응답 파싱
        const text = await res.text();
        let js = null; try { js = JSON.parse(text); } catch {}

        // ⛔ 겹침(409) 처리: 방 번호까지 있으면 안내
        if (res.status === 409) {
        const msg = (js?.error === 'conflict' && js?.room)
            ? `⛔ Time conflict on Room ${js.room}. Please choose another time/room.`
            : (js?.message || js?.error || 'Time conflict. Please choose another slot.');
        alert(msg);
        disable(false);
        return;
        }

        // 기타 에러
        if (!res.ok || !(js && js.success)) {
        alert(js?.error || js?.message || text || `HTTP ${res.status}`);
        disable(false);
        return;
        }

        // ✅ 성공
        alert('Your reservation has been updated. A confirmation email has been sent.');

        // 성공 후 창 닫기 시도 (메일에서 새 창으로 열렸다면 대부분 닫힘)
       
        // 항상 닫기 시도 → 실패 시 홈으로 이동
        setTimeout(() => {
        // 닫기 Best-effort
        try { window.close(); } catch {}
        try { window.open('', '_self'); window.close(); } catch {}

        // 150ms 안에 안 닫혔으면 홈으로 이동
        setTimeout(() => {
            // 사이트 홈 경로에 맞춰 조정 (예: /bookingtest/ 또는 / )
            location.href = `{BASE_URL}`;
        }, 150);
        }, 300);

    } catch (err) {
        console.error(err);
        alert('Network error occurred.');
        disable(false);
    }
    });
}

  // ===== CANCEL submit =====
  const cancelForm = document.querySelector('form[action$="customer_cancel_reservation.php"]');
  if (cancelForm) {
    cancelForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!confirm('Are you sure you want to cancel this reservation?')) return;

      lock(cancelForm, true);

      const token = cancelForm.querySelector('input[name="token"]').value.trim();
      const fd = new FormData(); fd.append('token', token);

      try {
        const res = await fetch('../api/customer_cancel_reservation.php', { method: 'POST', body: fd });
        const js  = await res.json();
        if (!res.ok || !js.success) {
          alert('Cancel failed: ' + (js.error || res.status));
          lock(cancelForm, false);
          return;
        }
        alert('Your reservation has been canceled. A confirmation email has been sent.');
        // 항상 닫기 시도 → 실패 시 홈으로 이동
        setTimeout(() => {
          // 닫기 Best-effort
          try { window.close(); } catch {}
          try { window.open('', '_self'); window.close(); } catch {}

          // 150ms 안에 안 닫혔으면 홈으로 이동
          setTimeout(() => {
          // 사이트 홈 경로에 맞춰 조정 (예: /bookingtest/ 또는 / )
            location.href = `{BASE_URL}`;
          }, 150);
        }, 300);
      } catch {
        alert('Network error occurred.');
        lock(cancelForm, false);
      }
    });
  }
})();

// === 비즈니스 시간 기반 시간 드롭다운 ===
(() => {
  const startSel   = document.getElementById('startTime');
  const endSel     = document.getElementById('endTime');
  const dateInput  = document.getElementById('date-picker');   // 표시용
  const hiddenDate = document.getElementById('new_date');      // YYYY-MM-DD (share.js가 채움)

  if (!startSel || !endSel || !dateInput || !hiddenDate || !Array.isArray(window.ALL_TIMES)) return;

  // 1) 비즈니스 시간 가져오기 (booking/admin과 동일 엔드포인트)
  async function fetchBusinessHours(ymd) {
    // booking/admin에서 쓰는 경로와 동일하게: includes/ 기준 => ../api/...
    const url = `../api/get_business_hours.php?date=${encodeURIComponent(ymd)}`;
    const res = await fetch(url, { credentials: 'same-origin' });
    const js  = await res.json();

    // 다양한 스키마를 허용 (open/close 또는 business_hours.open/close 등)
    const open  = js.open || js.open_time || js?.business_hours?.open;
    const close = js.close || js.close_time || js?.business_hours?.close;
    if (!js.success || !open || !close) return null;
    return { open, close };
  }

  // 2) 셀렉트 옵션 유틸
  function setOptions(sel, items, placeholder) {
    sel.innerHTML = '';
    const ph = document.createElement('option');
    ph.disabled = true; ph.selected = true; ph.textContent = placeholder;
    sel.appendChild(ph);
    for (const t of items) {
      const op = document.createElement('option');
      op.value = t; op.textContent = t;
      sel.appendChild(op);
    }
  }

  let currentBH = null; // {open, close}

  // 3) 날짜를 고르면: 영업시간 불러와서 Start 제한
  async function onDatePicked() {
    const ymd = hiddenDate.value;
    if (!ymd) return;

    try {
      currentBH = await fetchBusinessHours(ymd);
    } catch { currentBH = null; }

    // 영업시간 범위 안의 슬롯만 노출 (없으면 전체)
    const list = currentBH
      ? window.ALL_TIMES.filter(t => t >= currentBH.open && t < currentBH.close)
      : window.ALL_TIMES.slice();

    setOptions(startSel, list, 'Select start time');
    setOptions(endSel,   [],   'Select a start time first');
  }

  // 4) Start를 고르면: 그 이후~영업종료까지만 End 노출
  function onStartChanged() {
    const s = startSel.value;
    if (!s) return;

    let list = window.ALL_TIMES.filter(t => t > s);
    if (currentBH?.close) list = list.filter(t => t <= currentBH.close);

    setOptions(endSel, list, 'Select end time');
  }

  // 5) 이벤트 바인딩
  dateInput.addEventListener('change', onDatePicked); // flatpickr가 값 바꾸면 change 발생
  startSel.addEventListener('change', onStartChanged);

  // 6) 초기 1회 실행 (현재 예약일 기준)
  onDatePicked();
})();

// share.js의 필터/옵션 생성기와 연결
window.addEventListener('DOMContentLoaded', () => {
  const dateEl   = document.getElementById('date-picker');
  const startSel = document.getElementById('startTime');

  if (typeof updateStartTimes === 'function') {
    // 1) 처음 로드 시 & 날짜/방 바뀔 때 시작시간 다시 계산
    updateStartTimes(); // 초기 1회
    dateEl?.addEventListener('change', updateStartTimes);
    document.querySelectorAll('input[name="GB_room_no[]"]')
      .forEach(cb => cb.addEventListener('change', updateStartTimes));
  }

  if (typeof rebuildEndOptions === 'function' && typeof getCheckedRooms === 'function') {
    // 2) 시작시간 바뀌면 끝시간 옵션 재계산
    startSel?.addEventListener('change', () => {
      rebuildEndOptions(startSel.value, getCheckedRooms());
    });
  }
});
