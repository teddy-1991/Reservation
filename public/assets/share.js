let suppressChange = false; // 파일 상단에 전역으로 있어야 함

function toYMD(date) {
  if (!(date instanceof Date)) {
    date = new Date(date);
  }
  return date.toISOString().slice(0, 10);
}

function add30Minutes(timeStr) {
    const [hour, minute] = timeStr.split(":").map(Number);
    const date = new Date();
    date.setHours(hour, minute + 30, 0);

    const hh = String(date.getHours()).padStart(2, '0');
    const mm = String(date.getMinutes()).padStart(2, '0');
    return `${hh}:${mm}`;
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
        const key = item.GB_id;
        if (!colorMap.has(key)) {
            const colorClass = `bg-resv-${(colorIndex % 5) + 1}`;
            colorMap.set(key, colorClass);
            colorIndex++;
        }

        const colorClass = colorMap.get(key);
        const tooltip = `${item.GB_name ?? ''}\n${item.GB_phone ?? ''}\n${item.GB_email ?? ''}`;
        const displayName = isAdmin ? (item.GB_name ?? '') : '';

        let current = item.start_time.slice(0, 5);
        const end = item.end_time.slice(0, 5);
        let isFirst = true;

        while (current < end) {
            const slot = document.querySelector(`${selector}[data-time='${current}'][data-room='${item.room_no}']`);
            if (slot) {
                slot.classList.add('bg-danger',colorClass);
                slot.dataset.resvId = item.GB_id;
                slot.dataset.groupId = item.Group_id || "";
                slot.innerText = isFirst ? displayName : '';
                if (showTooltip && isAdmin) {
                    slot.setAttribute('title', tooltip);
                }
            }
            current = add30Minutes(current);
            isFirst = false;
        }
    });

    if (isAdmin) {
        setupAdminSlotClick();
    }
}

async function markPastTableSlots(dateStr, selector = ".time-slot", options = {}) {
  const { disableClick = true } = options;
  const todayYmd = toYMD(new Date());
  const now = new Date();
  const nowMin = now.getHours() * 60 + now.getMinutes();

  const bh = await fetchBusinessHours(dateStr);  // ✅ 실제 open/close 시간 불러오기
  if (!bh || !bh.open_time || !bh.close_time) return;

  const [openH, openM] = bh.open_time.split(":").map(Number);
  const [closeH, closeM] = bh.close_time.split(":").map(Number);

  document.querySelectorAll(selector).forEach(td => {
    const time = td.dataset.time;
    const room = td.dataset.room;
    if (!time || !room) return;

    td.classList.remove("past-slot");
    const [hh, mm] = time.split(":").map(Number);
    const slotMin = hh * 60 + mm;
    // ✅ room 4,5번은 open/close에 30분 보정
    const isLateRoom = room === "4" || room === "5";

    const openMinRaw = openH * 60 + openM;
    const closeMinRaw = closeH * 60 + closeM;

    const OPEN_MIN = isLateRoom ? openMinRaw + 30 : openMinRaw;
    const CLOSE_MIN = isLateRoom ? closeMinRaw - 30 : closeMinRaw;
    
    const isPast = (dateStr === todayYmd) && (slotMin <= nowMin);
    const tooEarly = slotMin < OPEN_MIN;
    const tooLate = slotMin + 60 > CLOSE_MIN;

    // ✅ 고객일 경우: 기존 제한 유지
    if (!window.IS_ADMIN) {
      if (isPast) td.classList.add("past-slot");
      if (disableClick && (isPast || tooEarly || tooLate)) {
        td.classList.add("pe-none");
      }
    }

    // ✅ 관리자일 경우: 오직 "마감 30분 전 슬롯"만 막기
    if (window.IS_ADMIN && disableClick) {
      const slotEnd = closeMinRaw - 30;
      if (slotEnd > closeMinRaw) {
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
  const date = document.getElementById('date-picker')?.value;
  const rooms = getCheckedRooms();
  if (suppressChange) return;

  if (!date || rooms.length === 0) {
    rebuildStartOptions([]);
    return;
  }

  const roomParam = rooms.length === 1
    ? `room=${rooms[0]}`
    : `rooms=${rooms.join(',')}`;

  const res = await fetch(`/api/get_reserved_info.php?date=${date}&${roomParam}`);
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

  // ✅ 커스터머일 경우: 비즈니스 시간 가져오기
  const bh = await fetchBusinessHours(date);
  if (!bh || !bh.open_time || !bh.close_time) {
    rebuildStartOptions([]);  // 시간 정보 없으면 선택 불가
    return;
  }

  const [openH, openM] = bh.open_time.split(":").map(Number);
  const [closeH, closeM] = bh.close_time.split(":").map(Number);
  const OPEN_MIN = openH * 60 + openM;
  const CLOSE_MIN = closeH * 60 + closeM;

  const avail = window.ALL_TIMES.filter(t => {
    const [hh, mm] = t.split(":").map(Number);
    const slotStart = hh * 60 + mm;

    const isPast = (date === todayYmd) && (slotStart <= nowMin);
    const overlap = reservedRanges.some(r => slotStart < r.end && (slotStart + 30) > r.start);
    const beforeOpen = slotStart < OPEN_MIN;
    const endTooLate = slotStart + 60 > CLOSE_MIN;

    return !beforeOpen && !overlap && !isPast && !endTooLate;
  });

  rebuildStartOptions(avail);
}

// ✅ 최종 JS 수정안: rebuildEndOptions
async function rebuildEndOptions(startTime, selectedRooms) {
  const startIdx = window.ALL_TIMES.indexOf(startTime);
  const endSelect = document.getElementById("endTime");
  endSelect.innerHTML = "";

  // ✅ 날짜에 해당하는 비즈니스 시간 불러오기
  const date = document.getElementById('date-picker')?.value;
  const bh = await fetchBusinessHours(date);
  if (!bh || !bh.open_time || !bh.close_time) return;

  const [closeH, closeM] = bh.close_time.split(":").map(Number);
  const CLOSE_MIN = closeH * 60 + closeM;

  const isLateRoom = selectedRooms.some(r => r === '4' || r === '5');

  for (let i = startIdx + 2; i < window.ALL_TIMES.length; i++) {
    const [hh, mm] = window.ALL_TIMES[i].split(":").map(Number);
    const endMin = hh * 60 + mm;

    // ✅ 4,5번방은 close-30까지만 허용 (예: 21:30까지)
    if (isLateRoom && endMin > (CLOSE_MIN - 30)) break;

    // ✅ 1~3번방은 close까지 허용 (예: 22:00까지)
    if (!isLateRoom && endMin > CLOSE_MIN) break;

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
      if (td.classList.contains("bg-danger") || td.classList.contains("past-slot") || td.classList.contains("pe-none")) {
        return;
      }

      const selectedTime = td.dataset.time;
      const selectedRoom = td.dataset.room;

      els.startSelect.value = selectedTime;

      els.roomCheckboxes.forEach(cb => {
        cb.checked = cb.value === selectedRoom;
        cb.dispatchEvent(new Event('change'));
      });

      updateStartTimes().then(() => {
        els.startSelect.value = selectedTime;
        els.startSelect.dispatchEvent(new Event('change'));
      });

      const selectedIndex = window.ALL_TIMES.indexOf(selectedTime);
      const defaultEndTime = window.ALL_TIMES[selectedIndex + 2];
      if (defaultEndTime) {
        els.endSelect.value = defaultEndTime;
      }

      const offcanvas = new bootstrap.Offcanvas(els.offcanvasEl);
      offcanvas.show();
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

    // ✅ 관리자 + 수정 모드일 경우 → 이 submit 핸들러 무시
    if (window.IS_ADMIN && window.isEditMode) {
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
      const reservedTimes = await fetch(`/api/get_reserved_info.php?date=${date}&room=${room}`)
        .then(r => r.json());

      if (reservedTimes.includes(startTime)) {
        alert(`Room ${room} is already booked at ${startTime}. Please choose another time.`);
        return;
      }
    }

    fetch('/api/create_reservation.php', { method: 'POST', body: formData })
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
}

function setupEndTimeUpdater(els) {
  els.startSelect?.addEventListener("change", () => {
    const startTime = els.startSelect.value;
    const selectedRooms = getCheckedRooms();
    rebuildEndOptions(startTime, selectedRooms);
  });
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
    const res = await fetch(`/api/get_business_hours.php?date=${dateStr}`);
    const data = await res.json();
    if (data.success) {
      return {
        open_time: data.data.open_time,
        close_time: data.data.close_time,
        closed: data.data.closed === 1
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
  fetch(`/api/get_reserved_info.php?date=${date}&room=${room}`)
    .then(res => res.json())
    .then(data => markReservedTimes(data, ".time-slot"))
    .catch(err => console.error("Fail to fetch the data:", err));
}