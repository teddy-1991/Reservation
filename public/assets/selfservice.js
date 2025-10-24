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

      // 1) FormData 먼저 구성 (disabled 전에)
      const fd = new FormData(updateForm);

      // 2) 토큰 강제 주입
      const tokenVal = updateForm.querySelector('input[name="token"]')?.value || '';
      if (!tokenVal) {
        alert('Token is missing. Please reopen the link from your email.');
        return;
      }
      fd.set('token', tokenVal);

      // 3) 날짜/시간/연락처 주입
      const dateVal  = document.getElementById('new_date')?.value || '';
      const startVal = document.getElementById('startTime')?.value || '';
      const endVal   = document.getElementById('endTime')?.value || '';
      const phoneVal = document.getElementById('GB_phone')?.value?.trim() || '';

      fd.set('date', dateVal);
      fd.set('start_time', startVal);
      fd.set('end_time', endVal);
      fd.set('GB_phone', phoneVal);

      // 4) rooms_csv도 함께
      const rooms = Array.from(document.querySelectorAll('input[name="GB_room_no[]"]:checked'))
        .map(el => el.value).join(',');
      fd.set('rooms_csv', rooms);

      if (!dateVal || !startVal || !endVal) {
        alert('Please select date, start, and end time.');
        return;
      }

      // 5) 버튼만 잠그기
      const disable = (on=true) => updateForm.querySelectorAll('button').forEach(b => b.disabled = on);
      disable(true);

      try {
        const endpoint = updateForm.getAttribute('action') || '../api/customer_update_reservation.php';
        const res  = await fetch(endpoint, { method: 'POST', body: fd });

        const text = await res.text();
        let js = null; try { js = JSON.parse(text); } catch {}

        // 409(conflict)
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

        // 성공
        alert('Your reservation has been updated. A confirmation email has been sent.');
        setTimeout(() => {
          // 닫기 Best-effort
          try { window.close(); } catch {}
          try { window.open('', '_self'); window.close(); } catch {}
          // 실패 시 홈으로 이동
          setTimeout(() => {
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
        const text = await res.text();
        let js = null; try { js = JSON.parse(text); } catch {}

        if (!res.ok || !js?.success) {
          alert('Cancel failed: ' + (js?.error || res.status));
          lock(cancelForm, false);
          return;
        }

        alert('Your reservation has been canceled. A confirmation email has been sent.');
        setTimeout(() => {
          try { window.close(); } catch {}
          try { window.open('', '_self'); window.close(); } catch {}
          setTimeout(() => { location.href = `{BASE_URL}`; }, 150);
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

  // /api/get_business_hours.php (스포텍 경로 유지)
  async function fetchBusinessHours(ymd) {
    const url = `../api/get_business_hours.php?date=${encodeURIComponent(ymd)}`;
    const res = await fetch(url, { credentials: 'same-origin' });
    const js  = await res.json().catch(() => ({}));

    const open  = (js.open || js.open_time || js?.business_hours?.open || '').slice(0,5);
    const close = (js.close || js.close_time || js?.business_hours?.close || '').slice(0,5);
    if (!open || !close) return null;
    return { open, close };
  }

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

  // HH:MM -> 분
  const toMin = (t) => (+t.slice(0,2))*60 + (+t.slice(3,5));
  // End 비교용 키: 00:00 은 1440(=24:00)으로 처리
  const endKey = (t) => (t === '00:00' ? 1440 : toMin(t));

  let currentBH = null; // {open, close}

  // 날짜 선택 → 비즈니스 시간 반영하여 Start 제한
  async function onDatePicked() {
    const ymd = hiddenDate.value;
    if (!ymd) return;

    try {
      currentBH = await fetchBusinessHours(ymd);
    } catch { currentBH = null; }

    // 영업시간 범위 안의 시작 후보만 노출 (없으면 전체)
    const list = currentBH
      ? window.ALL_TIMES.filter(t => toMin(t) >= toMin(currentBH.open) && endKey(t) < endKey(currentBH.close))
      : window.ALL_TIMES.slice();

    setOptions(startSel, list, 'Select start time');
    setOptions(endSel,   [],   'Select a start time first');
  }

  // Start 선택 → 그 이후 ~ 영업종료(=00:00이면 24:00)까지만 End 노출
  function onStartChanged() {
    const s = startSel.value;
    if (!s) return;

    let list = window.ALL_TIMES.filter(t => toMin(t) > toMin(s));
    if (currentBH?.close) {
      list = list.filter(t => endKey(t) <= endKey(currentBH.close));
    }

    // 만약 close가 00:00(=24:00)이고, 최소 1시간 이상 여유가 있으면 "00:00" 옵션이 없을 수 있어 보강
    const sMin = toMin(s);
    const cKey = currentBH?.close ? endKey(currentBH.close) : null;
    const needMidnight = (cKey === 1440) && (sMin + 60 <= 1440) && !list.includes('00:00');
    if (needMidnight) list.push('00:00');

    setOptions(endSel, list, 'Select end time');
  }

  dateInput.addEventListener('change', onDatePicked); // flatpickr가 값 바꾸면 change 발생
  startSel.addEventListener('change', onStartChanged);

  // 초기 1회 실행 (현재 예약일 기준)
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
