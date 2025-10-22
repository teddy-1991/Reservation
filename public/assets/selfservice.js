// /selfservice.js

(() => {
  // === Debug helpers (LOG ONLY) ===
  const __SS_DEBUG = true;
  const dlog  = (...a) => __SS_DEBUG && console.log('[SELF]', ...a);
  const dwarn = (...a) => __SS_DEBUG && console.warn('[SELF]', ...a);
  const derr  = (...a) => __SS_DEBUG && console.error('[SELF]', ...a);
  function dumpOptions(sel, tag){
    try {
      if (!__SS_DEBUG || !sel) return;
      const arr = Array.from(sel.options || []).map(o => o.textContent.trim());
      console.log(`[SELF] ${tag} (${arr.length})`, arr);
    } catch(e){ derr('dumpOptions error', e); }
  }

  const $ = (s) => document.querySelector(s);
  function lock(f, on = true) {
    if (!f) return;
    f.querySelectorAll('button, input, select, textarea').forEach(el => (el.disabled = on));
  }

  // ===== Date picker (share.js 재사용) =====
  const pickerInput = $('#date-picker');
  const hiddenDate  = $('#new_date');
  dlog('Datepicker init', { hasPicker: !!pickerInput, hasHidden: !!hiddenDate, hasSetup: typeof setupDatePicker === 'function', hasToYMD: typeof toYMD === 'function' });

  if (pickerInput && hiddenDate && typeof setupDatePicker === 'function' && typeof toYMD === 'function') {
    const today = new Date(); today.setHours(0,0,0,0);
    const max   = new Date(today); max.setDate(max.getDate() + 28);

    const defStr  = hiddenDate.value || '';
    const defDate = defStr ? new Date(defStr) : today;

    dlog('Datepicker pre-setup', { today, max: max.toISOString?.() || max, defStr, defDate });

    const fp = setupDatePicker((d) => {
      dlog('DatePicker onChange', d);
      if (d) hiddenDate.value = toYMD(d);
      dlog('DatePicker wrote hiddenDate', hiddenDate.value);
    }, {
      minDate: 'today',
      maxDate: toYMD(max)
    });

    fp.setDate(defDate, true);
    if (!hiddenDate.value) hiddenDate.value = toYMD(defDate);
    dlog('DatePicker initial set', { hiddenDate: hiddenDate.value });
  }

  // ===== UPDATE submit =====
  const updateForm = document.querySelector('form[action$="customer_update_reservation.php"]');
  dlog('Update form found?', !!updateForm);

  if (updateForm) {
    updateForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      dlog('SUBMIT begin');

      const fd = new FormData(updateForm);

      const tokenVal = updateForm.querySelector('input[name="token"]')?.value || '';
      dlog('SUBMIT token?', !!tokenVal);
      if (!tokenVal) {
        alert('Token is missing. Please reopen the link from your email.');
        return;
      }
      fd.set('token', tokenVal);

      const dateVal  = document.getElementById('new_date')?.value || '';
      const startVal = document.getElementById('startTime')?.value || '';
      const endVal   = document.getElementById('endTime')?.value || '';
      const phoneVal = document.getElementById('GB_phone')?.value?.trim() || '';
      const rooms    = Array.from(document.querySelectorAll('input[name="GB_room_no[]"]:checked')).map(el => el.value).join(',');

      fd.set('date', dateVal);
      fd.set('start_time', startVal);
      fd.set('end_time', endVal);
      fd.set('GB_phone', phoneVal);
      fd.set('rooms_csv', rooms);

      dlog('SUBMIT payload', { date: dateVal, start: startVal, end: endVal, rooms, token: tokenVal });
      try { dlog('SUBMIT entries', Object.fromEntries(fd)); } catch {}

      if (!dateVal || !startVal || !endVal) {
        dwarn('SUBMIT missing required fields', { dateVal, startVal, endVal });
        alert('Please select date, start, and end time.');
        return;
      }

      const disableBtns = (on=true) => updateForm.querySelectorAll('button').forEach(b => b.disabled = on);
      disableBtns(true);

      try {
        const endpoint = updateForm.getAttribute('action') || `${API_BASE}/customer_reservation/customer_update_reservation.php`;
        dlog('FETCH →', endpoint);

        const res  = await fetch(endpoint, { method: 'POST', body: fd, headers: { 'X-Debug': '1' } });
        const text = await res.text();
        let js = null; try { js = JSON.parse(text); } catch {}

        dlog('RESP status', res.status);
        dlog('RESP text', text);
        dlog('RESP json', js);

        if (res.status === 409) {
          const msg = (js?.error === 'conflict' && js?.room)
            ? `⛔ Time conflict on Room ${js.room}. Please choose another time/room.`
            : (js?.message || js?.error || 'Time conflict. Please choose another slot.');
          alert(msg);
          disableBtns(false);
          return;
        }

        if (!res.ok || !(js && js.success)) {
          alert(js?.error || js?.message || text || `HTTP ${res.status}`);
          disableBtns(false);
          return;
        }

        alert('Your reservation has been updated. A confirmation email has been sent.');
        setTimeout(() => {
          try { window.close(); } catch {}
          try { window.open('', '_self'); window.close(); } catch {}
          setTimeout(() => { location.href = `{BASE_URL}`; }, 150);
        }, 300);

      } catch (err) {
        derr('FETCH error', err);
        alert('Network error occurred.');
        disableBtns(false);
      }
    });
  }

  // ===== CANCEL submit =====
  const cancelForm = document.querySelector('form[action$="customer_cancel_reservation.php"]');
  dlog('Cancel form found?', !!cancelForm);

  if (cancelForm) {
    cancelForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!confirm('Are you sure you want to cancel this reservation?')) return;

      lock(cancelForm, true);

      const token = cancelForm.querySelector('input[name="token"]').value.trim();
      const fd = new FormData(); fd.append('token', token);
      dlog('CANCEL payload', { token });

      try {
        const url = `${API_BASE}/customer_reservation/customer_cancel_reservation.php`;
        dlog('FETCH cancel →', url);
        const res = await fetch(url, { method: 'POST', body: fd, headers: { 'X-Debug': '1' } });
        const text = await res.text();
        let js = null; try { js = JSON.parse(text); } catch {}
        dlog('CANCEL resp', res.status, js || text);

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

      } catch (e2) {
        derr('CANCEL fetch error', e2);
        alert('Network error occurred.');
        lock(cancelForm, false);
      }
    });
  }
})();

// === 비즈니스 시간 기반 시간 드롭다운 ===
(() => {
  // === Debug helpers (LOG ONLY) ===
  const __SS_DEBUG = true;
  const dlog  = (...a) => __SS_DEBUG && console.log('[SELF]', ...a);
  const dwarn = (...a) => __SS_DEBUG && console.warn('[SELF]', ...a);
  const derr  = (...a) => __SS_DEBUG && console.error('[SELF]', ...a);
  const dumpOptions = (sel, tag) => {
    try {
      if (!__SS_DEBUG || !sel) return;
      const arr = Array.from(sel.options || []).map(o => o.textContent.trim());
      console.log(`[SELF] ${tag} (${arr.length})`, arr);
    } catch(e){ derr('dumpOptions error', e); }
  };

  const startSel   = document.getElementById('startTime');
  const endSel     = document.getElementById('endTime');
  const dateInput  = document.getElementById('date-picker');
  const hiddenDate = document.getElementById('new_date');

  dlog('Dropdown init', {
    hasStart: !!startSel, hasEnd: !!endSel,
    hasDate: !!dateInput, hasHidden: !!hiddenDate,
    hasAllTimes: Array.isArray(window.ALL_TIMES)
  });

  if (!startSel || !endSel || !dateInput || !hiddenDate || !Array.isArray(window.ALL_TIMES)) {
    dwarn('Early return — missing elements or ALL_TIMES');
    return;
  }

  // 1) 비즈니스 시간 가져오기
  async function fetchBusinessHours(ymd) {
    const url = `${API_BASE}/business_hour/get_business_hours.php?date=${encodeURIComponent(ymd)}`;
    dlog('BH fetch →', url);
    const res = await fetch(url, { credentials: 'same-origin' });
    dlog('BH status', res.status);
    let js = null; try { js = await res.json(); } catch(e){ derr('BH json parse error', e); }
    dlog('BH json', js);

    const open  = (js?.open || js?.open_time || js?.business_hours?.open || '').slice(0,5);
    const close = (js?.close || js?.close_time || js?.business_hours?.close || '').slice(0,5);
    dlog('BH parsed', { open, close, hasSuccess: !!js?.success });

    if (!open || !close) {
      dwarn('BH invalid → null', { open, close, success: js?.success });
      return null;
    }
    return { open, close };
  }

  // 2) 셀렉트 옵션 유틸
  function setOptions(sel, items, placeholder) {
    dlog('setOptions()', { target: sel?.id, placeholder, count: items?.length });
    sel.innerHTML = '';
    const ph = document.createElement('option');
    ph.disabled = true; ph.selected = true; ph.textContent = placeholder;
    sel.appendChild(ph);
    for (const t of items) {
      const op = document.createElement('option');
      op.value = t; op.textContent = t;
      sel.appendChild(op);
    }
    dumpOptions(sel, `OPTS(${sel.id})`);
  }

  let currentBH = null; // {open, close}

  // 3) 날짜를 고르면: 영업시간 불러와서 Start 제한
  async function onDatePicked() {
    const ymd = hiddenDate.value;
    dlog('onDatePicked()', { ymd });
    if (!ymd) return;

    try {
      currentBH = await fetchBusinessHours(ymd);
    } catch (e) {
      derr('BH fetch error', e);
      currentBH = null;
    }
    dlog('BH applied', currentBH);

    const list = currentBH
      ? window.ALL_TIMES.filter(t => t >= currentBH.open && t < currentBH.close)
      : window.ALL_TIMES.slice();

    dlog('Start candidates', { size: list.length, first: list[0], last: list[list.length-1] });
    setOptions(startSel, list, 'Select start time');

    setOptions(endSel,   [],   'Select a start time first');
  }

  // 4) Start를 고르면: 그 이후~영업종료까지만 End 노출
  function onStartChanged() {
    const s = startSel.value;
    dlog('onStartChanged()', { start: s, BH: currentBH });

    if (!s) return;

    const toMin = t => +t.slice(0,2)*60 + +t.slice(3,5);
    const endKey = t => (t === '00:00' ? 1440 : toMin(t)); // 00:00 = 24:00
    let list = window.ALL_TIMES.filter(t => toMin(t) > toMin(s));
    if (currentBH?.close) list = list.filter(t => endKey(t) <= endKey(currentBH.close));

    dlog('End candidates', { size: list.length, first: list[0], last: list[list.length-1] });
    setOptions(endSel, list, 'Select end time');
  }

  // 5) 이벤트 바인딩
  dateInput.addEventListener('change', onDatePicked);
  startSel.addEventListener('change', onStartChanged);
  dlog('Bound events: date.change, start.change');

  // 6) 초기 1회 실행
  onDatePicked();
  dlog('Init onDatePicked() called once');
})();

// share.js의 필터/옵션 생성기와 연결 (LOG ONLY)
window.addEventListener('DOMContentLoaded', () => {
  const __SS_DEBUG = true;
  const dlog  = (...a) => __SS_DEBUG && console.log('[SELF]', ...a);
  const dumpOptions = (sel, tag) => {
    try {
      if (!__SS_DEBUG || !sel) return;
      const arr = Array.from(sel.options || []).map(o => o.textContent.trim());
      console.log(`[SELF] ${tag} (${arr.length})`, arr);
    } catch {}
  };

  const dateEl   = document.getElementById('date-picker');
  const startSel = document.getElementById('startTime');
  const endSel   = document.getElementById('endTime');

  if (typeof updateStartTimes === 'function') {
    dlog('share.js: updateStartTimes detected → run once');
    updateStartTimes();
    dumpOptions(startSel, 'START(after updateStartTimes 1st)');
    dumpOptions(endSel,   'END(after updateStartTimes 1st)');

    dateEl?.addEventListener('change', () => {
      dlog('share.js: updateStartTimes via date change');
      updateStartTimes();
      dumpOptions(startSel, 'START(after updateStartTimes date)');
      dumpOptions(endSel,   'END(after updateStartTimes date)');
    });

    document.querySelectorAll('input[name="GB_room_no[]"]')
      .forEach(cb => cb.addEventListener('change', () => {
        dlog('share.js: updateStartTimes via room change');
        updateStartTimes();
        dumpOptions(startSel, 'START(after updateStartTimes room)');
        dumpOptions(endSel,   'END(after updateStartTimes room)');
      }));
  } else {
    dlog('share.js: updateStartTimes not found');
  }

  if (typeof rebuildEndOptions === 'function' && typeof getCheckedRooms === 'function') {
    dlog('share.js: rebuildEndOptions/getCheckedRooms detected → bind start change');
    startSel?.addEventListener('change', () => {
      const v = startSel.value;
      dlog('share.js: rebuildEndOptions trigger (listener)', v);
      const rooms = getCheckedRooms?.() || [];
      dlog('share.js: rooms for rebuildEndOptions', rooms);
      rebuildEndOptions(v, rooms);
      try {
        const endSel = document.getElementById('endTime');
        const bh = window.__CURRENT_BH || null;           // selfservice가 세팅해둔 값이 있으면 사용
        const toMin = t => +t.slice(0,2)*60 + +t.slice(3,5);
        const endKey = t => (t === '00:00' ? 1440 : toMin(t));

        const sVal = startSel.value;
        const sMin = sVal ? toMin(sVal) : NaN;

        // close 시간을 share.js 전역이 따로 있다면 거기서도 가져와도 됨.
        const closeHHMM = bh?.close || null;              // 없으면 null
        const cMin = closeHHMM ? endKey(closeHHMM) : NaN;

        const needsMidnight = !isNaN(sMin) && !isNaN(cMin) && cMin === 1440 && sMin < 1440;
        const hasMidnight   = Array.from(endSel?.options || []).some(o => o.value === '00:00');

        if (endSel && needsMidnight && !hasMidnight) {
          endSel.add(new Option('00:00', '00:00'));
          console.log('[SELF] appended 00:00 after share.js rebuildEndOptions');
        }
      } catch (e) { console.warn('[SELF] midnight-append skipped', e); }
      dumpOptions(endSel, 'END(after rebuildEndOptions listener)');
    });
  } else {
    dlog('share.js: rebuildEndOptions/getCheckedRooms not found');
  }
});
