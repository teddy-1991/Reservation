// ===== Sportech share.js (Virtual Tee Up 스타일로 정렬, 경로/영업시간 API는 기존 유지) =====
let suppressChange = false; // 파일 상단에 전역으로 있어야 함
const ROOT = '/bookingtest/public';          // ⚠️ 스포텍 유지
const API_BASE = `${ROOT}/api`;
let REBUILD_END_SEQ = 0;

function toYMD(date) {
  if (date instanceof Date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }
  if (typeof date === 'string') {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(date);
    if (m) return `${m[1]}-${m[2]}-${m[3]}`;
  }
  const d = new Date(date);
  const y = d.getFullYear();
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${y}-${mm}-${dd}`;
}

function add30Minutes(timeStr) {
  const [hour, minute] = timeStr.split(":").map(Number);
  const date = new Date(0);
  date.setHours(hour, minute, 0);
  date.setMinutes(date.getMinutes() + 30);
  const hh = String(date.getHours()).padStart(2, '0');
  const mm = String(date.getMinutes()).padStart(2, '0');
  return `${hh}:${mm}`;
}

// HH:MM -> 분
function toMin(hhmm) {
  const [h, m] = String(hhmm).slice(0,5).split(":").map(Number);
  return h * 60 + m;
}

// ⬇️ 버츄어 로직 반영: 오픈 기준 자정 보정 + 00:00=24:00 취급
function closeToMin(hhmm, isClosedFlag, openHHMMOrMin = null) {
  const [h, m] = String(hhmm).slice(0,5).split(":").map(Number);
  let close = h * 60 + m;

  if (!isClosedFlag) {
    if (h === 0 && m === 0) return 24 * 60; // 자정까지 영업
    const open = (openHHMMOrMin == null)
      ? null
      : (typeof openHHMMOrMin === 'number'
          ? openHHMMOrMin
          : toMin(String(openHHMMOrMin).slice(0,5)));
    if (open != null && close <= open) close += 1440; // 익일로 넘어감
  }
  return close;
}

// 빠른/안전 날짜 동기화
function updateDateInputs(input, flatpickrInstance = null) {
  const ymd = toYMD(input);

  const dpVal  = document.getElementById('date-picker')?.value;
  const admVal = document.getElementById('adm_date')?.value;
  const gbVal  = document.getElementById('GB_date')?.value;
  if (dpVal === ymd && admVal === ymd && gbVal === ymd) return;

  suppressChange = true;

  const dp = document.getElementById('date-picker');
  if (dp) dp.value = ymd;

  const adm = document.getElementById('adm_date');
  if (adm) adm.value = ymd;

  const hiddenInput = document.getElementById('GB_date');
  if (hiddenInput) hiddenInput.value = ymd;

  const formDateDisplay = document.getElementById('form-selected-date');
  if (formDateDisplay) formDateDisplay.textContent = ymd;

  const newDate = document.getElementById('new_date');
  if (newDate) newDate.value = ymd;

  if (flatpickrInstance && typeof flatpickrInstance.setDate === 'function') {
    flatpickrInstance.setDate(ymd, true);
  }

  suppressChange = false;
}

// 모든 셀 초기화
function clearAllTimeSlots() {
  document.querySelectorAll(".time-slot").forEach(slot => {
    slot.classList.remove("bg-danger", "text-white", "past-slot", "pe-none");
    for (let i = 1; i <= 5; i++) slot.classList.remove(`bg-resv-${i}`);
    slot.innerText = '';
    slot.removeAttribute("title");
  });
  if (typeof colorMap !== "undefined") colorMap.clear();
  if (typeof colorIndex !== "undefined") colorIndex = 0;
}

function prevDate(currentDateStr, options = {}, handlers = {}) {
  const { minDate = null } = options;
  const { updateDateInputs, clearAllTimeSlots, loadAllRoomReservations, markPastTableSlots } = handlers;
  const [year, month, day] = currentDateStr.split('-').map(Number);
  const current = new Date(year, month - 1, day);
  current.setHours(0, 0, 0, 0);

  const previous = new Date(current);
  previous.setDate(current.getDate() - 1);
  previous.setHours(0, 0, 0, 0);

  if (minDate && toYMD(previous) < toYMD(minDate)) {
    alert("You cannot go to a past date.");
    return;
  }

  const formatted = toYMD(previous);
  updateDateInputs(previous);
  clearAllTimeSlots();
  loadAllRoomReservations(formatted);
  setTimeout(() => markPastTableSlots(toYMD(previous)), 50);
}

function nextDate(currentDateStr, options = {}, handlers = {}) {
  const { maxDate = null } = options;
  const { updateDateInputs, clearAllTimeSlots, loadAllRoomReservations, markPastTableSlots } = handlers;
  const [year, month, day] = currentDateStr.split('-').map(Number);
  const current = new Date(year, month - 1, day);
  const next = new Date(current);
  next.setDate(current.getDate() + 1);

  if (maxDate && next > maxDate) {
    alert("You can only book within 4 weeks from today.");
    return;
  }

  const formatted = toYMD(next);
  updateDateInputs(next);
  clearAllTimeSlots();
  loadAllRoomReservations(formatted);
  setTimeout(() => markPastTableSlots(toYMD(next)), 50);
}

let colorIndex = 0;
const colorMap = new Map();

// ⬇️ 버츄어 스타일: openMin 축으로 보정해서 칠하기
function markReservedTimes(reservedTimes, selector = ".time-slot", options = {}) {
  const isAdmin = window.IS_ADMIN === true || window.IS_ADMIN === "true";
  const { showTooltip = true, openMin = 0 } = options;

  reservedTimes.forEach(item => {
    const key = item.Group_id;
    if (!colorMap.has(key)) {
      const colorClass = `bg-resv-${(colorIndex % 5) + 1}`;
      colorMap.set(key, colorClass);
      colorIndex++;
    }
    const colorClass = colorMap.get(key);

    const tooltip = `${item.GB_name ?? ''}\n${item.GB_phone ?? ''}\n${item.GB_email ?? ''}`;
    const displayName = isAdmin ? (item.GB_name ?? '') : '';

    const roomStr = String(item.GB_room_no ?? item.room_no ?? item.room ?? '').trim();
    const startStr = String(item.GB_start_time ?? item.start_time ?? '').slice(0, 5);
    const endStr   = String(item.GB_end_time   ?? item.end_time   ?? '').slice(0, 5);
    if (!roomStr || !startStr || !endStr) return;

    let s = toMin(startStr);
    let e = toMin(endStr);
    if (s < openMin) s += 1440;
    if (e < openMin) e += 1440;
    if (e <= s) e += 1440;

    let isFirst = true;
    for (let m = s; m < e; m += 30) {
      const mm = m % 1440;
      const hh = String(Math.floor(mm / 60)).padStart(2, '0');
      const mi = (mm % 60) ? '30' : '00';
      const t  = `${hh}:${mi}`;

      const slot = document.querySelector(`${selector}[data-time='${t}'][data-room='${roomStr}']`);
      if (!slot) continue;

      slot.classList.add('bg-danger', colorClass);
      slot.dataset.resvId  = item.GB_id;
      slot.dataset.groupId = item.Group_id || "";
      slot.dataset.start   = startStr;
      slot.dataset.end     = endStr;
      slot.dataset.room    = roomStr;

      if (isFirst) slot.innerText = displayName;
      if (showTooltip && isAdmin) slot.setAttribute('title', tooltip);
      isFirst = false;
    }
  });
}

async function markPastTableSlots(dateStr, selector = ".time-slot", options = {}) {
  if (!dateStr) return;
  const { disableClick = true } = options;

  const todayYmd = toYMD(new Date());
  const now = new Date();
  const nowMin = now.getHours() * 60 + now.getMinutes();

  // ⚠️ 스포텍 영업시간 API 경로 유지
  const bh = await fetchBusinessHours(dateStr);
  if (!bh || !bh.open_time || !bh.close_time) return;

  const OPEN_MIN  = toMin(bh.open_time);
  const CLOSE_MIN = closeToMin(
    bh.close_time,
    bh.closed === 1 || bh.closed === true,
    OPEN_MIN
  );

  const BLOCK_SLOT_1 = OPEN_MIN + 30;
  const BLOCK_SLOT_2 = CLOSE_MIN - 30;

  document.querySelectorAll(selector).forEach(td => {
    const time = td.dataset.time;
    const room = td.dataset.room;
    if (!time || !room) return;

    td.classList.remove("past-slot", "pe-none");

    const [hh, mm] = time.split(":").map(Number);
    const slotMin = hh * 60 + mm;
    const slotAdj = (slotMin < OPEN_MIN) ? (slotMin + 1440) : slotMin;

    const isPast   = (dateStr === todayYmd) && (slotAdj <= nowMin);
    const tooEarly = slotAdj < OPEN_MIN;
    const tooLate  = (slotAdj + 60) > CLOSE_MIN;
    const isBlockedSlot = (slotAdj === BLOCK_SLOT_1) || (slotAdj === BLOCK_SLOT_2);

    if (!window.IS_ADMIN) {
      if (isPast) td.classList.add("past-slot");
      if (disableClick && (isPast || tooEarly || tooLate || isBlockedSlot)) {
        td.classList.add("pe-none");
      }
    }
  });
}

function setupDatePicker(onDateChange, options = {}) {
  return flatpickr('#date-picker', {
    dateFormat: 'Y-m-d',
    disableMobile: true,
    closeOnSelect: true,
    minDate: options.minDate ?? null,
    maxDate: options.maxDate ?? null,
    defaultDate: 'today',
    onValueUpdate: function (selectedDates, dateStr) {
      if (suppressChange) return;
      if (!dateStr) return;
      const [year, month, day] = dateStr.split('-').map(Number);
      const selectedDate = new Date(year, month - 1, day);
      if (typeof onDateChange === 'function') onDateChange(selectedDate);
    }
  });
}

function getCheckedRooms() {
  const checkboxes = document.querySelectorAll('input[name="GB_room_no[]"]');
  return [...checkboxes].filter(cb => cb.checked).map(cb => cb.value);
}

function rebuildStartOptions(times) {
  const startSelect = document.getElementById("startTime");
  const endSelect = document.getElementById("endTime");
  startSelect.innerHTML = '<option value="" disabled selected>Select a start time</option>';
  times.forEach(t => {
    const opt = document.createElement('option');
    opt.value = t;
    opt.textContent = t;
    startSelect.appendChild(opt);
  });
  endSelect.innerHTML = '<option disabled selected>Select a start time first</option>';
}

// ⬇️ 스포텍 경로 유지 + 버츄어 로직 반영 + "표시는 00시, 값은 그대로" 라벨 보정
async function updateStartTimes() {
  // --- 표시용 포매터(24:00, 25:30 → 00:00, 01:30)
  function displayHHMM(hhmm) {
    if (!hhmm) return hhmm;
    const s = String(hhmm).slice(0, 5);
    const [hStr, mStr = '00'] = s.split(':');
    let h = Number(hStr);
    if (!Number.isFinite(h)) return s;
    h = ((h % 24) + 24) % 24;
    return String(h).padStart(2, '0') + ':' + mStr;
  }
    // --- start 셀렉트 옵션의 "라벨만" 00시 기준으로 교체
  function relabelStartOptions() {
    const sel = document.getElementById('startTime');
    if (!sel) return;
    for (const opt of sel.options) {
      // ✅ HH(:)MM 형태가 아니면(placeholder 등) 건너뜀
      if (!/^\d{1,2}:\d{2}$/.test(String(opt.value))) continue;

      // 값은 그대로 두고, 라벨만 00시 기준으로 표시
      const s = String(opt.value).slice(0, 5);
      const [hStr, mStr = '00'] = s.split(':');
      let h = Number(hStr);
      if (!Number.isFinite(h)) continue;
      h = ((h % 24) + 24) % 24;
      opt.textContent = String(h).padStart(2, '0') + ':' + mStr;
    }
  }

  const date = getSelectedYMD();
  if (!date || suppressChange) return;

  const rooms   = getCheckedRooms();
  const formEl  = document.getElementById('bookingForm');
  const editing = !!(formEl && formEl.dataset && formEl.dataset.mode === 'edit');
  const isAdmin = (window.IS_ADMIN === true || window.IS_ADMIN === "true");

  const mySeq = (window.__UST_seq = (window.__UST_seq || 0) + 1);
  const apply = (times) => {
    if (mySeq !== window.__UST_seq) return false;
    rebuildStartOptions(times);   // 값(value)은 그대로 유지
    relabelStartOptions();        // 화면 라벨만 00시 기준으로 보정
    return true;
  };

  // 방 미선택: 고객은 영업시간 기반 후보, 관리자는 전체(편집 아닐 때)
  if (rooms.length === 0) {
    const bh = await fetchBusinessHours(date);
    if (!bh || !bh.open_time || !bh.close_time || bh.closed === 1 || bh.closed === true) {
      apply([]); return;
    }
    const OPEN_MIN  = toMin(bh.open_time);
    const CLOSE_MIN = (typeof closeToMin === 'function')
      ? closeToMin(bh.close_time, bh.closed === 1 || bh.closed === true, OPEN_MIN)
      : (function () { // 안전 보정
          let cm = toMin(String(bh.close_time).slice(0,5));
          if (cm <= (OPEN_MIN ?? 0)) cm += 1440;
          return cm;
        })();

    const baseTimes = [];
    for (let m = OPEN_MIN; m + 60 <= CLOSE_MIN; m += 30) {
      const hh = String(Math.floor(m / 60)).padStart(2, '0');
      const mm = String(m % 60).padStart(2, '0');
      baseTimes.push(`${hh}:${mm}`); // 값은 그대로(24/25 가능)
    }
    apply(isAdmin && !editing ? window.ALL_TIMES : baseTimes);
    return;
  }

  // 예약 현황(스포텍 경로 유지)
  const url = `${API_BASE}/admin_reservation/get_reserved_info.php`
            + `?date=${encodeURIComponent(date)}`
            + `&room=${encodeURIComponent(rooms[0] || '')}`
            + `&rooms=${encodeURIComponent(rooms.join(','))}`
            + `&_t=${Date.now()}`;
  const res  = await fetch(url, { cache: 'no-store' });
  const data = await res.json();

  // 영업시간 정규화
  const bh = await fetchBusinessHours(date);
  if (!bh || !bh.open_time || !bh.close_time || bh.closed === 1 || bh.closed === true) {
    apply([]); return;
  }
  const OPEN_MIN  = toMin(bh.open_time);
  const CLOSE_MIN = (typeof closeToMin === 'function')
    ? closeToMin(bh.close_time, bh.closed === 1 || bh.closed === true, OPEN_MIN)
    : (function () {
        let cm = toMin(String(bh.close_time).slice(0,5));
        if (cm <= (OPEN_MIN ?? 0)) cm += 1440;
        return cm;
      })();

  const reservedRanges = (Array.isArray(data) ? data : []).map(r => {
    const sh = toMin(String(r.start_time).slice(0, 5));
    const eh = toMin(String(r.end_time).slice(0, 5));
    let s = sh, e = eh;
    if (s < OPEN_MIN) s += 1440;
    if (e < OPEN_MIN) e += 1440;
    if (e <= s) e += 1440;
    return { start: s, end: e };
  });

  const todayYmd = toYMD(new Date());
  const now      = new Date();
  const nowMin   = now.getHours() * 60 + now.getMinutes();

  if (isAdmin && !editing) { apply(window.ALL_TIMES); return; }

  const baseTimes = [];
  for (let m = OPEN_MIN; m + 60 <= CLOSE_MIN; m += 30) {
    const hh = String(Math.floor(m / 60)).padStart(2, '0');
    const mm = String(m % 60).padStart(2, '0');
    baseTimes.push(`${hh}:${mm}`); // 값 유지
  }

  const BLOCK_SLOT_1 = OPEN_MIN + 30;
  const BLOCK_SLOT_2 = CLOSE_MIN - 30;

  const avail = baseTimes.filter(t => {
    const [hh, mm] = t.split(':').map(Number);
    const slotStart = hh * 60 + mm;                 // 값 기준(24/25 가능)
    const slotAdj   = (slotStart < OPEN_MIN) ? (slotStart + 1440) : slotStart;

    const isPast     = (date === todayYmd) && (slotAdj <= nowMin);
    const overlap    = reservedRanges.some(r => slotAdj < r.end && (slotAdj + 30) > r.start);
    const beforeOpen = slotAdj < OPEN_MIN;
    const endTooLate = (slotAdj + 60) > CLOSE_MIN;
    const blocked    = (slotAdj === BLOCK_SLOT_1 || slotAdj === BLOCK_SLOT_2);
    return !beforeOpen && !overlap && !isPast && !endTooLate && !blocked;
  });

  apply(avail);

  // 프리필 처리
  const prefill = formEl?.dataset?.prefillStart;
  if (prefill) {
    const startSelect = document.getElementById('startTime');
    const endSelect   = document.getElementById('endTime');
    if (!startSelect.querySelector(`option[value="${prefill}"]`)) {
      const opt = document.createElement('option');
      opt.value = prefill;
      opt.textContent = displayHHMM(prefill); // 라벨은 00시로
      startSelect.appendChild(opt);
    }
    suppressChange = true;
    startSelect.value = prefill;
    await rebuildEndOptions(prefill); // 내부 로직은 값(24/25 포함) 기준으로 동작
    suppressChange = false;
    formEl.dataset.prefillStart = '';
    // end 옵션도 rebuildEndOptions에서 만들어졌으니, 필요하면 거기서도 라벨 보정 동일 패턴 적용 권장
  }
}


// ⬇️ 버츄어 스타일: 최신 호출 보존 + 24:00(00:00) 옵션 처리
async function rebuildEndOptions(startTime) {
  const seq = ++REBUILD_END_SEQ;
  const endSelect = document.getElementById("endTime");
  if (!endSelect) return;
  endSelect.innerHTML = "";
  if (!startTime) return;

  const date = getSelectedYMD();
  const bh = await fetchBusinessHours(date);
  if (!bh || !bh.open_time || !bh.close_time) return;

  const OPEN_MIN  = toMin(String(bh.open_time).slice(0,5));
  const CLOSE_MIN = closeToMin(
    String(bh.close_time).slice(0,5),
    bh.closed === 1 || bh.closed === true,
    OPEN_MIN
  );

  const startMinRaw = toMin(String(startTime).slice(0,5));
  const startMin    = (startMinRaw < OPEN_MIN) ? (startMinRaw + 1440) : startMinRaw;

  const isAdmin = (window.IS_ADMIN === true || window.IS_ADMIN === 'true');
  const MIN_GAP = isAdmin ? 30 : 60;
  const STEP    = 30;

  if (seq !== REBUILD_END_SEQ) return;

  for (let t = startMin + MIN_GAP; t <= CLOSE_MIN; t += STEP) {
    const m  = t % 1440;
    const hh = String(Math.floor(m / 60)).padStart(2, '0');
    const mm = String(m % 60).padStart(2, '0');
    const val = `${hh}:${mm}`;
    const opt = document.createElement("option");
    opt.value = val;
    opt.textContent = val;
    endSelect.appendChild(opt);
  }

  // close=24:00일 때 "00:00" 추가(최소 간격 충족 시)
  if (CLOSE_MIN === 1440) {
    const minEndNeeded = startMin + MIN_GAP;
    if (minEndNeeded <= 1440 && ![...endSelect.options].some(o => o.value === "00:00")) {
      const opt = document.createElement("option");
      opt.value = "00:00";
      opt.textContent = "00:00";
      endSelect.appendChild(opt);
    }
  }

  if (endSelect.options.length) endSelect.value = endSelect.options[0].value;
}

function setupGlobalDateListeners(els) {
  window.addEventListener("DOMContentLoaded", () => {
    const ymd = els.datePicker?.value;
    if (!ymd) return;
    loadAllRoomReservations(ymd);
    setTimeout(() => markPastTableSlots(ymd), 50);
    updateStartTimes();
  });

  els.datePicker?.addEventListener("change", (e) => {
    const selectedDate = e.target.value;
    clearAllTimeSlots();
    loadAllRoomReservations(selectedDate);
    setTimeout(() => markPastTableSlots(selectedDate), 50);
  });
}

function setupStartTimeUpdater(els) {
  els.datePicker?.addEventListener("change", updateStartTimes);
}

function setupSlotClickHandler(els) {
  document.querySelectorAll(".time-slot").forEach(td => {
    td.addEventListener("click", () => {
      if (td.classList.contains("bg-danger") ||
          td.classList.contains("past-slot") ||
          td.classList.contains("pe-none")) return;

      const selectedTime = td.dataset.time;
      const selectedRoom = td.dataset.room;

      els.startSelect.value = selectedTime;

      window.suppressChange = true;
      els.roomCheckboxes.forEach(cb => { cb.checked = cb.value === selectedRoom; });
      window.suppressChange = false;
      els.roomCheckboxes.forEach(cb => { if (cb.checked) cb.dispatchEvent(new Event("change")); });

      setTimeout(() => {
        const offcanvas = new bootstrap.Offcanvas(els.offcanvasEl);
        offcanvas.show();

        const formEl = els.form || document.getElementById('bookingForm');
        if (formEl) formEl.dataset.prefillStart = selectedTime;
        updateStartTimes().then(async () => {
          els.startSelect.value = selectedTime;
          els.startSelect.dispatchEvent(new Event('change', { bubbles: true }));
          const idx = window.ALL_TIMES.indexOf(selectedTime);
          const defaultEnd = window.ALL_TIMES[idx + 2];
          if (defaultEnd) {
            setTimeout(() => { els.endSelect.value = defaultEnd; }, 30);
          }
        });
      }, 0);
    });
  });
}

function resetBookingForm(els, options = {}) {
  const keepDate = options.keepDate !== false;
  const currentYmd =
    els?.datePicker?.value ||
    document.getElementById('GB_date')?.value ||
    toYMD(new Date());

  els.form.reset();

  const targetYmd = keepDate ? currentYmd : toYMD(new Date());
  updateDateInputs(targetYmd);

  els.roomCheckboxes.forEach(cb => cb.checked = false);
  els.endSelect.innerHTML = '<option disabled selected>Select a start time first</option>';

  els.form.querySelectorAll(".is-invalid, .is-valid").forEach(el => {
    el.classList.remove("is-invalid", "is-valid");
  });

  if (options.resetOTP !== false) {
    const verifiedInput = document.getElementById('isVerified');
    if (verifiedInput) verifiedInput.value = '';
    const otpSection = document.getElementById('otpSection');
    if (otpSection) otpSection.classList.add('d-none');
  }
}

function handleReservationSubmit(els, options = {}) {
  const form = els.form;
  if (!form) return;

  form.addEventListener("submit", async function (e) {
    e.preventDefault();

    if (window.isEditMode) { e.stopImmediatePropagation(); return; }
    if (!validDateForm()) return;

    if (options.requireOTP !== false) {
      const isVerified = document.getElementById('isVerified')?.value;
      if (isVerified !== '1') {
        alert("Please verify your phone number before booking.");
        return;
      }
    }

    const formData = new FormData(form);
    const rooms = getCheckedRooms();
    formData.delete("GB_room_no[]");
    rooms.forEach(room => formData.append("GB_room_no[]", room));

    const gbDate    = formData.get("GB_date");
    const startTime = formData.get("GB_start_time");

    // 사전 겹침 체크(자정 보정)
    try {
      const bh       = await fetchBusinessHours(gbDate);
      const OPEN_MIN = toMin(String(bh?.open_time || "").slice(0, 5));
      const slotHHMM   = String(startTime).slice(0, 5);
      const slotMinRaw = toMin(slotHHMM);
      const slotMin    = (slotMinRaw < OPEN_MIN) ? (slotMinRaw + 1440) : slotMinRaw;
      const slotEnd    = slotMin + 60;

      for (const room of rooms) {
        const url = `${API_BASE}/admin_reservation/get_reserved_info.php?date=${gbDate}&room=${room}`;
        const resv = await fetch(url).then(r => r.json()).catch(() => []);
        let conflict = false;
        if (Array.isArray(resv) && resv.length > 0) {
          if (typeof resv[0] === 'string') {
            conflict = resv
              .map(s => String(s).slice(0, 5))
              .some(t => {
                let m = toMin(t);
                if (m < OPEN_MIN) m += 1440;
                return m < slotEnd && (m + 30) > slotMin;
              });
          } else if (resv[0] && typeof resv[0] === 'object') {
            conflict = resv.some(r => {
              const sRaw = toMin(String(r.start_time).slice(0, 5));
              const eRaw = toMin(String(r.end_time).slice(0, 5));
              let s = (sRaw < OPEN_MIN) ? (sRaw + 1440) : sRaw;
              let e = (eRaw < OPEN_MIN) ? (eRaw + 1440) : eRaw;
              if (e <= s) e += 1440;
              return slotMin < e && slotEnd > s;
            });
          }
        }
        if (conflict) {
          alert(`⚠️ Room ${room} is already booked in that time range.`);
          return;
        }
      }
    } catch (_) { /* 서버가 최종 방어하므로 계속 진행 */ }

    // 생성 요청 (스포텍 경로 유지)
    fetch(`${API_BASE}/admin_reservation/create_reservation.php`, { method: 'POST', body: formData })
      .then(async (res) => {
        const text = await res.text();
        let j = null; try { j = JSON.parse(text); } catch {}
        if (res.status === 409) {
          const msg = (j && (j.message || j.error)) || 'Time conflict. Please pick another slot.';
          alert("⚠️ " + msg);
          loadAllRoomReservations(els.datePicker.value);
          rebuildStartOptions([]); updateStartTimes();
          throw new Error('conflict');
        }
        if (res.status === 429) {
          const msg = (j && (j.message || j.error)) || 'Too many reservations from the same IP.';
          alert(msg);
          throw new Error('ratelimited');
        }
        if (!res.ok) {
          const msg = (j && (j.message || j.error)) || 'Reservation failed. Please try again.';
          alert(msg);
          throw new Error('server');
        }
        const logicalFail = j && (j.ok === false || j.success === false || j.status === 'error' || j.error);
        if (logicalFail) {
          alert((j.message || j.error || 'Reservation failed. Please try again.'));
          throw new Error('logical-fail');
        }
        return j || {};
      })
      .then(() => {
        alert("Reservation complete!");
        try { bootstrap.Offcanvas.getInstance(els.offcanvasEl)?.hide(); } catch {}
        loadAllRoomReservations(els.datePicker.value);
        resetBookingForm(els, { resetOTP: options.requireOTP !== false });
      })
      .catch(err => {
        if (!['conflict','ratelimited','server','logical-fail'].includes(err?.message)) {
          alert("Reservation failed. Please try again.");
        }
      });
  });
}

function setupEndTimeUpdater(els) {
  if (els.startSelect && !els.startSelect.__endUpdaterBound) {
    els.startSelect.__endUpdaterBound = true;
    els.startSelect.addEventListener("change", async () => {
      const startTime = els.startSelect.value;
      await rebuildEndOptions(startTime);
    });
  }
}

function setupOffcanvasDateSync(els) {
  els.offcanvasEl?.addEventListener("show.bs.offcanvas", () => {
    const selectedDate = els.datePicker?.value;
    els.bookingDateInput.value = selectedDate;
    updateDateInputs(selectedDate);
  });
}

function setupOffcanvasBackdropCleanup(els) {
  els.offcanvasEl?.addEventListener("hidden.bs.offcanvas", () => {
    document.querySelectorAll(".offcanvas-backdrop").forEach(el => el.remove());
    resetBookingForm(els, { keepDate: true });
  });
}

function setupOffcanvasCloseFix(els) {
  els.offcanvasEl.addEventListener("hidden.bs.offcanvas", function () {
    document.body.classList.remove("offcanvas-backdrop");
    document.body.style.overflow = "";
    const backdrop = document.querySelector(".offcanvas-backdrop");
    if (backdrop) backdrop.remove();
  });
}

// ⚠️ 스포텍: 영업시간 API 경로 유지
async function fetchBusinessHours(dateStr) {
  try {
    const res = await fetch(`${API_BASE}/business_hour/get_business_hours.php?date=${dateStr}`);
    const data = await res.json();
    if (data.open_time && data.close_time) {
      return { open_time: data.open_time, close_time: data.close_time, closed: data.closed === 1 };
    } else {
      return null;
    }
  } catch (err) {
    console.error("Failed to fetch business hours:", err);
    return null;
  }
}

// ⚠️ 스포텍: 예약정보 API 경로 유지 + openMin 전달
async function fetchReservedTimes(date, room) {
  try {
    const [bh, data] = await Promise.all([
      fetchBusinessHours(date),
      fetch(`${API_BASE}/admin_reservation/get_reserved_info.php?date=${date}&room=${room}`).then(res => res.json())
    ]);
    const openMin = bh?.open_time ? toMin(String(bh.open_time).slice(0,5)) : 0;
    markReservedTimes(data, ".time-slot", { openMin });
  } catch (err) {
    console.error("Fail to fetch the data:", err);
  }
}

// 선택된 날짜 읽기
function getSelectedYMD() {
  return (
    document.getElementById('GB_date')?.value ||
    document.getElementById('adm_date')?.value ||
    document.getElementById('date-picker')?.value ||
    ''
  ).trim();
}

// 여러 방 일괄 로드(스포텍: rooms 파라미터 지원 시 활용, 아니면 반복 호출)
async function fetchReservedTimesBulk(date, rooms = []) {
  // rooms 파라미터를 서버가 지원한다면 한 번에:
  const bh = await fetchBusinessHours(date);
  const openMin = bh?.open_time ? toMin(String(bh.open_time).slice(0,5)) : 0;

  const roomsParam = rooms && rooms.length
    ? `rooms=${encodeURIComponent(rooms.join(','))}`
    : '';

  try {
    const url = `${API_BASE}/admin_reservation/get_reserved_info.php?date=${date}${roomsParam ? '&'+roomsParam : ''}`;
    const data = await fetch(url, { cache: 'no-store' }).then(res => res.json()).catch(() => []);
    if (Array.isArray(data)) {
      markReservedTimes(data, ".time-slot", { openMin });
      return;
    }
  } catch (_) {}

  // 서버가 bulk 미지원이면 per-room fallback
  for (const r of rooms) await fetchReservedTimes(date, r);
}

// 로더
async function loadReservations(date, {
  rooms = (window.allRoomNumbers || [1,2,3,4,5]),
  isAdmin = false
} = {}) {
  if (!date) return;

  await fetchReservedTimesBulk(date, rooms);
  await markPastTableSlots(date, ".time-slot", { disableClick: true });

  if (isAdmin && typeof window.setupAdminSlotClick === "function") {
    window.setupAdminSlotClick();
  }
}

// 전역 노출
window.loadReservations = loadReservations;
window.allRoomNumbers = [1, 2, 3, 4, 5];

async function fetchMenuFixed3() {
  const res = await fetch(`${API_BASE}/menu_price/get_menu_fixed3.php?t=${Date.now()}`, { cache: 'no-store' });
  return await res.json();
}
window.fetchMenuFixed3 = fetchMenuFixed3;

// 24:00, 25:30 같은 문자열을 00:00, 01:30으로 '표시/제출'용으로 바꿔준다.
function displayHHMM(hhmm) {
  if (!hhmm) return hhmm;
  const s = String(hhmm).slice(0,5);
  const [hStr, mStr = '00'] = s.split(':');
  let h = Number(hStr);
  if (!Number.isFinite(h)) return s;
  h = ((h % 24) + 24) % 24; // 음수 방지
  return String(h).padStart(2,'0') + ':' + mStr;
}
