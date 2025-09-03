let suppressChange = false; // 파일 상단에 전역으로 있어야 함
// at top of share.js (and reuse in other JS)
const ROOT = '/bookingtest/public';
const API_BASE = `${ROOT}/api`;
let REBUILD_END_SEQ = 0;

function toYMD(date) {
   // 1) Date 객체면 그대로 로컬 기준으로 포맷
   if (date instanceof Date) {
     const y = date.getFullYear();
     const m = String(date.getMonth() + 1).padStart(2, '0');
     const d = String(date.getDate()).padStart(2, '0');
     return `${y}-${m}-${d}`;
   }
   // 2) 'YYYY-MM-DD' 문자열이면 절대 new Date('...')로 파싱하지 말고 직접 분해 (로컬 보존)
   if (typeof date === 'string') {
     const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(date);
     if (m) return `${m[1]}-${m[2]}-${m[3]}`;
   }
   // 3) 그 외 입력만 fallback
   const d = new Date(date);
   const y = d.getFullYear();
   const mm = String(d.getMonth() + 1).padStart(2, '0');
   const dd = String(d.getDate()).padStart(2, '0');
  
   return `${y}-${mm}-${dd}`;
  }

function add30Minutes(timeStr) {
  const [hour, minute] = timeStr.split(":").map(Number);
  const date = new Date(0);  // ✅ 고정된 기준일 사용
  date.setHours(hour, minute, 0);

  date.setMinutes(date.getMinutes() + 30); // ✅ 정확한 30분 증가

  const hh = String(date.getHours()).padStart(2, '0');
  const mm = String(date.getMinutes()).padStart(2, '0');
  return `${hh}:${mm}`;
}

// HH:MM -> 분
function toMin(hhmm) {
  const [h, m] = hhmm.slice(0,5).split(":").map(Number);
  return h * 60 + m;
}

// 닫는 시간 전용: 00:00 이면서 'closed'가 아니면 24:00(=1440분)로 해석
function closeToMin(hhmm, isClosedFlag) {
  const [h, m] = hhmm.slice(0,5).split(":").map(Number);
  if (!isClosedFlag && h === 0 && m === 0) return 24 * 60; // 자정까지 영업
  return h * 60 + m; // 일반 케이스
}

// 날짜 및 form 요소들에 날짜 반영
function updateDateInputs(date, flatpickrInstance = null) {
  const ymd = toYMD(date);


  suppressChange = true;
  document.getElementById('date-picker').value = ymd;
  if (flatpickrInstance) flatpickrInstance.setDate(ymd, true);  // optional
  suppressChange = false;

  const formDateDisplay = document.getElementById('form-selected-date');
  const hiddenInput = document.getElementById('GB_date');
  if (formDateDisplay) formDateDisplay.textContent = ymd;
  if (hiddenInput) hiddenInput.value = ymd;
}

// 모든 셀에서 예약 관련 표시 제거
function clearAllTimeSlots() {
  document.querySelectorAll(".time-slot").forEach(slot => {
    slot.classList.remove("bg-danger", "text-white", "past-slot", "pe-none");

    // bg-resv-1 ~ bg-resv-5 클래스 제거
    for (let i = 1; i <= 5; i++) {
      slot.classList.remove(`bg-resv-${i}`);
    }

    slot.innerText = '';
    slot.removeAttribute("title");
  });

  // 컬러맵 초기화 (중요!)
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
    previous.setHours(0, 0, 0, 0);   // ✅ 이 줄 추가

    //  제한 있는 경우
    if (minDate && toYMD(previous) < toYMD(minDate)) {
        alert("You cannot go to a past date.");
        return;
    }

    const formatted = toYMD(previous);
    updateDateInputs(previous);
    clearAllTimeSlots();
    loadAllRoomReservations(formatted);
    setTimeout(() => markPastTableSlots(toYMD(previous)), 50);  // ✅ 수정
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
    setTimeout(() => markPastTableSlots(toYMD(next)), 50);  // ✅ 수정
}

let colorIndex = 0;
const colorMap = new Map(); 

function markReservedTimes(reservedTimes, selector = ".time-slot", options = {}) {
  const isAdmin = window.IS_ADMIN === true || window.IS_ADMIN === "true";
  const { showTooltip = true } = options;

  reservedTimes.forEach(item => {
    // 그룹 색상 유지
    const key = item.Group_id;
    if (!colorMap.has(key)) {
      const colorClass = `bg-resv-${(colorIndex % 5) + 1}`;
      colorMap.set(key, colorClass);
      colorIndex++;
    }
    const colorClass = colorMap.get(key);

    // 표시용 텍스트/툴팁
    const tooltip = `${item.GB_name ?? ''}\n${item.GB_phone ?? ''}\n${item.GB_email ?? ''}`;
    const displayName = isAdmin ? (item.GB_name ?? '') : '';

    // 데이터 원본(키 혼용 대비)
    const roomStr = String(item.GB_room_no ?? item.room_no ?? item.room ?? '').trim();
    const startStr = String(item.GB_start_time ?? item.start_time ?? '').slice(0, 5);
    const endStr   = String(item.GB_end_time   ?? item.end_time   ?? '').slice(0, 5);

    // 시간 숫자화 (00:00 -> 1440 처리)
    const sMin = toMin(startStr);
    const eMin = closeToMin(endStr, false);
    if (!roomStr || sMin == null || eMin == null || eMin <= sMin) return;

    let isFirst = true;
    for (let m = sMin; m < eMin; m += 30) {
      const hh = String(Math.floor(m / 60)).padStart(2, '0');
      const mm = (m % 60) ? '30' : '00';
      const t  = `${hh}:${mm}`;

      const slot = document.querySelector(`${selector}[data-time='${t}'][data-room='${roomStr}']`);
      if (!slot) continue;

      slot.classList.add('bg-danger', colorClass);
      slot.dataset.resvId  = item.GB_id;
      slot.dataset.groupId = item.Group_id || "";
      slot.dataset.start   = startStr;
      slot.dataset.end     = endStr;       // ✅ 23:00~00:00 도 '00:00' 그대로 저장 (표시는 24:00까지 루프)
      slot.dataset.room    = roomStr;

      if (isFirst) slot.innerText = displayName;
      if (showTooltip && isAdmin) slot.setAttribute('title', tooltip);

      isFirst = false;
    }
  });

  if (isAdmin) setupAdminSlotClick();
}

async function markPastTableSlots(dateStr, selector = ".time-slot", options = {}) {
  if (!dateStr) return; // ✅ 안전 가드

  const { disableClick = true } = options;
  const todayYmd = toYMD(new Date());
  const now = new Date();
  const nowMin = now.getHours() * 60 + now.getMinutes();

  const bh = await fetchBusinessHours(dateStr); 
  if (!bh || !bh.open_time || !bh.close_time) return;

  const OPEN_MIN  = toMin(bh.open_time);
  const CLOSE_MIN = closeToMin(bh.close_time, bh.closed === 1 || bh.closed === true);

  document.querySelectorAll(selector).forEach(td => {
    const time = td.dataset.time;
    const room = td.dataset.room;
    if (!time || !room) return;

    td.classList.remove("past-slot");
    const [hh, mm] = time.split(":").map(Number);
    const slotMin = hh * 60 + mm;

    const BLOCK_SLOT_1 = OPEN_MIN + 30;
    const BLOCK_SLOT_2 = CLOSE_MIN - 30;
    const isBlockedSlot = slotMin === BLOCK_SLOT_1 || slotMin === BLOCK_SLOT_2;

    const isPast = (dateStr === todayYmd) && (slotMin <= nowMin);
    const tooEarly = slotMin < OPEN_MIN;
    const tooLate = slotMin + 60 > CLOSE_MIN;

    if (!window.IS_ADMIN) {
      if (isPast) td.classList.add("past-slot");

      // ✅ 여기에 blocked 슬롯 포함
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
    defaultDate: 'today', // ✅ 오늘 날짜를 기본값으로 지정
    onValueUpdate: function (selectedDates, dateStr) {
        if (suppressChange) return; // ✅ 무한 루프 방지
        if (!dateStr) return;

      const [year, month, day] = dateStr.split('-').map(Number);
      const selectedDate = new Date(year, month - 1, day);

      if (typeof onDateChange === 'function') {
        onDateChange(selectedDate);
      }
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

  startSelect.innerHTML = '<option disabled selected>Select a start time</option>';
  times.forEach(t => {
    const opt = document.createElement('option');
    opt.value = t;
    opt.textContent = t;
    startSelect.appendChild(opt);
  });

  endSelect.innerHTML = '<option disabled selected>Select a start time first</option>';
  
}

async function updateStartTimes() {
  
  const date = getSelectedYMD();   // ✅ span 없어도, 모달/상단/hidden 중 하나에서 읽음
  if (!date) return;
  
  const rooms = getCheckedRooms();

  if (suppressChange) return;

  if (!date || rooms.length === 0) {
    rebuildStartOptions([]);
    return;
  }

  const roomParam = rooms.length === 1
    ? `room=${rooms[0]}`
    : `rooms=${rooms.join(',')}`;

  const res = await fetch(`${API_BASE}/get_reserved_info.php?date=${date}&${roomParam}`);
  const data = await res.json();

  const reservedRanges = data.map(r => {
    const [sh, sm] = r.start_time.slice(0, 5).split(":").map(Number);
    const [eh, em] = r.end_time.slice(0, 5).split(":").map(Number);
    return { start: sh * 60 + sm, end: eh * 60 + em };
  });

  const todayYmd = toYMD(new Date());
  const now = new Date();
  const nowMin = now.getHours() * 60 + now.getMinutes();

  const isAdmin = window.IS_ADMIN === true || window.IS_ADMIN === "true";

  if (isAdmin) {
    rebuildStartOptions(window.ALL_TIMES);
    return;
  }

  const bh = await fetchBusinessHours(date);
  if (!bh || !bh.open_time || !bh.close_time) {
    rebuildStartOptions([]);
    return;
  }

  const OPEN_MIN  = toMin(bh.open_time);
  const CLOSE_MIN = closeToMin(bh.close_time, bh.closed === 1 || bh.closed === true);

  const BLOCK_SLOT_1 = OPEN_MIN + 30;
  const BLOCK_SLOT_2 = CLOSE_MIN - 30;


  const avail = window.ALL_TIMES.filter(t => {
    const [hh, mm] = t.split(":").map(Number);
    const slotStart = hh * 60 + mm;

    const isPast = (date === todayYmd) && (slotStart <= nowMin) && !isAdmin;
    const overlap = reservedRanges.some(r => slotStart < r.end && (slotStart + 30) > r.start);
    const beforeOpen = slotStart < OPEN_MIN;
    const endTooLate = slotStart + 60 > CLOSE_MIN;
    const isBlocked = slotStart === BLOCK_SLOT_1 || slotStart === BLOCK_SLOT_2;

    return !beforeOpen && !overlap && !isPast && !endTooLate && !isBlocked;
  });

  rebuildStartOptions(avail);
}

// ✅ 최종 JS 수정안: rebuildEndOptions
async function rebuildEndOptions(startTime, selectedRooms) {

  const mySeq = ++REBUILD_END_SEQ;
  const startIdx = window.ALL_TIMES.indexOf(startTime);
  const endSelect = document.getElementById("endTime");


  // ✅ 날짜에 해당하는 비즈니스 시간 불러오기
  const date = document.getElementById('date-picker')?.value;
  const bh = await fetchBusinessHours(date);
  if (!bh || !bh.open_time || !bh.close_time) return;

  const CLOSE_MIN = closeToMin(bh.close_time, bh.closed === 1 || bh.closed === true);


  const isAdmin = window.IS_ADMIN === true || window.IS_ADMIN === "true";

  const minGap = isAdmin ? 1 : 2; // ✅ 관리자면 30분 이상만 가능, 아니면 1시간

  // 👇 여전히 '최신 호출'인지 확인 (이전 호출이 나중에 끝났으면 버림)
  if (mySeq !== REBUILD_END_SEQ) return;
  // ✅ 최신 호출만 옵션을 지우고 렌더
  endSelect.innerHTML = "";

  for (let i = startIdx + minGap; i < window.ALL_TIMES.length; i++) {
    const [hh, mm] = window.ALL_TIMES[i].split(":").map(Number);
    const endMin = hh * 60 + mm;



    if (endMin > CLOSE_MIN) break;

    const option = document.createElement("option");
    option.value = window.ALL_TIMES[i];
    option.textContent = window.ALL_TIMES[i];
    endSelect.appendChild(option);
  }
}


function setupGlobalDateListeners(els) {
  
  window.addEventListener("DOMContentLoaded", () => {
    const ymd = els.datePicker?.value;
    if (!ymd) return;
    loadAllRoomReservations(ymd);
  setTimeout(() => markPastTableSlots(ymd), 50); // ✅ 지연 호출
    updateStartTimes();
  });

  els.datePicker?.addEventListener("change", (e) => {
    const selectedDate = e.target.value;
    clearAllTimeSlots();
    loadAllRoomReservations(selectedDate);
    setTimeout(() => markPastTableSlots(selectedDate), 50); // ✅ 지연 호출
  });
}

function setupStartTimeUpdater(els) {
  els.datePicker?.addEventListener("change", updateStartTimes);
}

function setupSlotClickHandler(els) {
  document.querySelectorAll(".time-slot").forEach(td => {
    td.addEventListener("click", () => {
      if (
        td.classList.contains("bg-danger") ||
        td.classList.contains("past-slot") ||
        td.classList.contains("pe-none")
      ) return;

      const selectedTime = td.dataset.time;
      const selectedRoom = td.dataset.room;

      // 1) 시작시간만 반영 (여기서는 change 날리지 않음)
      els.startSelect.value = selectedTime;

      // 2) 체크박스 변경은 suppressChange로 묶어서 한 번만 갱신되게
      window.suppressChange = true;
      els.roomCheckboxes.forEach(cb => {
        cb.checked = cb.value === selectedRoom;
      });
      window.suppressChange = false;
      els.roomCheckboxes.forEach(cb => {
        if (cb.checked) cb.dispatchEvent(new Event("change"));
      });

      // 3) UI를 즉시 보여주고, 데이터 갱신은 백그라운드에서
      setTimeout(() => {
        const offcanvas = new bootstrap.Offcanvas(els.offcanvasEl);
        offcanvas.show();

        // 백그라운드 갱신 후 선택 재고정
        updateStartTimes().then(async () => {
          // 재빌드 후 다시 시작시간 고정 + change는 여기서 '한 번만'
          els.startSelect.value = selectedTime;
          els.startSelect.dispatchEvent(new Event('change', { bubbles: true }));

          // 기본 끝시간 = +1시간 (30분 간격 기준 +2)
          const idx = window.ALL_TIMES.indexOf(selectedTime);
          const defaultEnd = window.ALL_TIMES[idx + 2];
          if (defaultEnd) {
            // 👉 옵션 생성은 rebuildEndOptions가 이미 처리하므로,
            // 여기서는 단순히 값만 세팅
            setTimeout(() => {
              els.endSelect.value = defaultEnd;
            }, 30);
          }
        });
      }, 0);
    });
  });
}



function resetBookingForm(els, options = {}) {
  els.form.reset();

  const todayStr = toYMD(new Date());
  els.bookingDateInput.value = todayStr;
  els.formDateDisplay.textContent = todayStr;

  els.roomCheckboxes.forEach(cb => cb.checked = false);
  els.endSelect.innerHTML = '<option disabled selected>Select a start time first</option>';

  els.form.querySelectorAll(".is-invalid, .is-valid").forEach(el => {
    el.classList.remove("is-invalid", "is-valid");
  });

  // ✅ 옵션 처리
  if (options.resetOTP !== false) {
    const verifiedInput = document.getElementById('isVerified');
    if (verifiedInput) verifiedInput.value = '';

    const otpSection = document.getElementById('otpSection');
    if (otpSection) otpSection.classList.add('d-none');
  }
}

function handleReservationSubmit(els, options = {}) {
  const form = els.form;
  if (!form) {
    console.error("form not found!");
    return;
  }

  form.addEventListener("submit", async function (e) {
    e.preventDefault();

  // ✅ 편집 모드일 땐 생성 경로 완전히 차단
    if (window.isEditMode) {
      e.stopImmediatePropagation();
      return;
    }

    if (!validDateForm()) return;


    if (options.requireOTP !== false) {
      const isVerified = document.getElementById('isVerified')?.value;
      if (isVerified !== '1') {
        alert("Please verify your phone number before booking.");
        return;
      }
    }

    const formData = new FormData(form);

    getCheckedRooms().forEach(room => {
      formData.append("GB_room_no[]", room);
    });

    const date = formData.get("GB_date");
    const startTime = formData.get("GB_start_time");

    for (const room of getCheckedRooms()) {
      const reservedTimes = await fetch(`${API_BASE}/get_reserved_info.php?date=${date}&room=${room}`)
        .then(r => r.json());

      if (reservedTimes.includes(startTime)) {
        alert(`Room ${room} is already booked at ${startTime}. Please choose another time.`);
        return;
      }
    }

    fetch(`${API_BASE}/create_reservation.php`, { method: 'POST', body: formData })
      .then(res => {
        if (res.status === 409) return res.json().then(j => {
          alert("⚠️ " + j.message);
          loadAllRoomReservations(els.datePicker.value);
          rebuildStartOptions([]);
          updateStartTimes();
          throw new Error('conflict');
        });
        if (!res.ok) throw new Error('server');
        return res.json();
      })
      .then(() => {
        alert("Reservation complete!");
        bootstrap.Offcanvas.getInstance(els.offcanvasEl)?.hide();
        loadAllRoomReservations(els.datePicker.value);
        resetBookingForm(els, { resetOTP: options.requireOTP !== false });
      })
      .catch(err => {
        if (err.message !== 'conflict')
          alert("Reservation failed. Please try again.");
      });
  });
}function handleReservationSubmit(els, options = {}) {
  const form = els.form;
  if (!form) {
    console.error("form not found!");
    return;
  }

  form.addEventListener("submit", async function (e) {
    e.preventDefault();

    // ✅ 편집 모드일 땐 생성 경로 완전히 차단
    if (window.isEditMode) {
      e.stopImmediatePropagation();
      return;
    }

    if (!validDateForm()) return;

    if (options.requireOTP !== false) {
      const isVerified = document.getElementById('isVerified')?.value;
      if (isVerified !== '1') {
        alert("Please verify your phone number before booking.");
        return;
      }
    }

    const formData = new FormData(form);

    getCheckedRooms().forEach(room => {
      formData.append("GB_room_no[]", room);
    });

    const date = formData.get("GB_date");
    const startTime = formData.get("GB_start_time");

    for (const room of getCheckedRooms()) {
      const reservedTimes = await fetch(`${API_BASE}/get_reserved_info.php?date=${date}&room=${room}`)
        .then(r => r.json());

      if (reservedTimes.includes(startTime)) {
        alert(`Room ${room} is already booked at ${startTime}. Please choose another time.`);
        return;
      }
    }

    fetch(`${API_BASE}/create_reservation.php`, { method: 'POST', body: formData })
      .then(res => {
        // 409: 서버에서 겹침 감지
        if (res.status === 409) {
          return res.json().then(j => {
            alert("⚠️ " + j.message);
            loadAllRoomReservations(els.datePicker.value);
            rebuildStartOptions([]);
            updateStartTimes();
            throw new Error('conflict');
          });
        }

        // 429: IP 레이트리밋 — 서버 메시지(전화/이메일 안내) 그대로 노출
        if (res.status === 429) {
          return res.text().then(t => {
            let j; try { j = JSON.parse(t); } catch {}
            alert((j && (j.message || j.error)) ||
                  'Too many reservations from the same IP within 5 minutes. Please call 403-455-4951 or email booking@sportechindoorgolf.com.');
            throw new Error('ratelimited');
          });
        }

        // 기타 에러: 서버가 보낸 본문을 최대한 표시
        if (!res.ok) {
          return res.text().then(t => {
            let j; try { j = JSON.parse(t); } catch {}
            alert((j && (j.message || j.error)) || 'Reservation failed. Please try again.');
            throw new Error('server');
          });
        }

        // 정상
        return res.json();
      })
      .then(() => {
        alert("Reservation complete!");
        bootstrap.Offcanvas.getInstance(els.offcanvasEl)?.hide();
        loadAllRoomReservations(els.datePicker.value);
        resetBookingForm(els, { resetOTP: options.requireOTP !== false });
      })
      .catch(err => {
        // 위에서 이미 안내창을 띄운 경우는 중복 방지
        if (err.message !== 'conflict' && err.message !== 'ratelimited') {
          alert("Reservation failed. Please try again.");
        }
      });
  });
}


function setupEndTimeUpdater(els) {
  if (els.startSelect && !els.startSelect.__endUpdaterBound) {
    els.startSelect.__endUpdaterBound = true;
    els.startSelect.addEventListener("change", () => {
      const startTime = els.startSelect.value;
      const selectedRooms = getCheckedRooms();
      rebuildEndOptions(startTime, selectedRooms);
    });
  }
}

function setupOffcanvasDateSync(els) {
  els.offcanvasEl?.addEventListener("show.bs.offcanvas", () => {
    const selectedDate = els.datePicker?.value;
    els.bookingDateInput.value = selectedDate;
    els.formDateDisplay.textContent = selectedDate;
  });
}

function setupOffcanvasBackdropCleanup(els) {
  els.offcanvasEl?.addEventListener("hidden.bs.offcanvas", () => {
    document.querySelectorAll(".offcanvas-backdrop").forEach(el => el.remove());
    resetBookingForm(els);  
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

async function fetchBusinessHours(dateStr) {
  try {
    // ✅ 캐시 방지를 위해 timestamp 추가
    const res = await fetch(`${API_BASE}/get_business_hours.php?date=${dateStr}`);
    const data = await res.json();

    if (data.open_time && data.close_time) {
      return {
        open_time: data.open_time,
        close_time: data.close_time,
        closed: data.closed === 1
      };
    } else {
      return null;
    }
  } catch (err) {
    console.error("Failed to fetch business hours:", err);
    return null;
  }
}

function fetchReservedTimes(date, room) {
  fetch(`${API_BASE}/get_reserved_info.php?date=${date}&room=${room}`)
    .then(res => res.json())
    .then(data => markReservedTimes(data, ".time-slot"))
    .catch(err => console.error("Fail to fetch the data:", err));
}

// 현재 선택된 날짜를 "어디서든" 안정적으로 읽기
function getSelectedYMD() {
  return (
    document.getElementById('GB_date')?.value ||      // 제출용 hidden (우선)
    document.getElementById('adm_date')?.value ||     // 관리자 모달 달력
    document.getElementById('date-picker')?.value ||  // 상단 달력
    ''
  ).trim();
}

// 날짜 세터: hidden/상단 달력/표시 텍스트를 한 번에 동기화
function updateDateInputs(ymd) {
  const hidden = document.getElementById('GB_date');
  if (hidden) hidden.value = ymd || '';

  const dp = document.getElementById('date-picker');
  if (dp) dp.value = ymd || '';

}