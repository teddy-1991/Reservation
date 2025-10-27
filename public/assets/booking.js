// DOM 요소 모음
const els = {

    datePicker: document.getElementById('date-picker'),
    bookingDateInput: document.getElementById('GB_date'),
    formDateDisplay: document.getElementById('form-selected-date'),
    notice: document.getElementById('rightHandedNotice'),
    roomCheckboxes: document.querySelectorAll('input[name="GB_room_no[]"]'),
    startSelect: document.getElementById('startTime'),
    endSelect: document.getElementById('endTime'),
    offcanvasEl: document.getElementById('bookingCanvas'),
    form: document.getElementById('bookingForm'),
    roomNote: document.getElementById('roomNote')
}

// booking.js
function loadAllRoomReservations(date) {
  // 공용 로더 사용, 고객 화면이니 isAdmin:false
  return window.loadReservations(date, {
    rooms: allRoomNumbers,
    isAdmin: false
  });
}

const handlers = {
  updateDateInputs: (date) => updateDateInputs(date, flatpickrInstance),
  clearAllTimeSlots,
  loadAllRoomReservations,
  markPastTableSlots
};

// 상수
// const allTimes = window.ALL_TIMES; // PHP가 미리 심어준 전역 배열 사용
const BUFFER_MIN = 60; // 예약 가능 시간 버퍼 (분 단위)

const today = new Date();
today.setHours(0, 0, 0, 0);

const maxDate = new Date(today);
maxDate.setDate(today.getDate() + 28);
maxDate.setHours(0, 0, 0, 0);

const prevBtn = document.getElementById("prevDateBtn");
const nextBtn = document.getElementById("nextDateBtn");

const selectedDate = els.datePicker.value;

let flatpickrInstance;

flatpickrInstance = setupDatePicker(function (selectedDate) {
  const ymd = toYMD(selectedDate);
  window.location.href = `?date=${ymd}`;
  updateDateInputs(selectedDate, flatpickrInstance);
  clearAllTimeSlots();
  loadAllRoomReservations(toYMD(selectedDate));
  markPastTableSlots();
}, {
  minDate: 'today',
  maxDate: toYMD(maxDate)
});


setupGlobalDateListeners(els);
updateDateInputs(selectedDate);

setupSlotClickHandler(els);

setupStartTimeUpdater(els);
setupEndTimeUpdater(els);

setupOffcanvasDateSync(els);
setupOffcanvasBackdropCleanup(els);
setupOffcanvasCloseFix(els);  // ✅ 추가


clearAllTimeSlots();

markPastTableSlots(els.datePicker.value); // default disableClick = true

handleReservationSubmit(els);  // default: requireOTP: true

if (prevBtn) {
  prevBtn.addEventListener("click", () => {
    const [y, m, d] = document.getElementById("date-picker").value.split("-").map(Number);
    const currentDate = new Date(y, m - 1, d);    
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const previous = new Date(currentDate);
    previous.setDate(previous.getDate() - 1);

    if (toYMD(previous) < toYMD(today)) {
      alert("You cannot go to a past date.");
      return;
    }

    const newDateStr = toYMD(previous);
    window.location.href = `index.php?date=${newDateStr}`;
  });
}

if (nextBtn) {
  nextBtn.addEventListener("click", () => {
    const [y, m, d] = document.getElementById("date-picker").value.split("-").map(Number);
    const currentDate = new Date(y, m - 1, d);    
    
    const next = new Date(currentDate);
    next.setDate(next.getDate() + 1);

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const maxDate = new Date(today);
    maxDate.setDate(today.getDate() + 28);

    if (next > maxDate) {
      alert("You can only book within 4 weeks from today.");
      return;
    }

    const newDateStr = toYMD(next);
    window.location.href = `index.php?date=${newDateStr}`;
  });
}

// room 2 notice
els.roomCheckboxes.forEach(checkbox => {
    checkbox.addEventListener('change', () => {
        const isRoom2Selected = Array.from(els.roomCheckboxes)
        .some(cb => cb.checked && cb.value === "2");

        if (isRoom2Selected) {
            els.notice.classList.remove('d-none');
        } else {
            els.notice.classList.add('d-none');
        }

        updateStartTimes(); // 룸 선택 변경 시 시작 시간 옵션 업데이트
    });
});


// Validate Name, Email, Phone
function validDateForm() {
    let isValid = true;

    const nameInput = document.getElementById("name");
    const emailInput = document.getElementById("email");
    const phoneInput = document.getElementById("phone");
    const dateInput = document.getElementById("GB_date");
    const timeDropdown = document.getElementById("startTime");
    const consentCheckbox = document.getElementById("consentCheckbox");

    const nameError = document.getElementById("nameError");
    const emailError = document.getElementById("emailError");
    const phoneError = document.getElementById("phoneError");
    const dateError = document.getElementById("dateError");
    const timeError = document.getElementById("timeError");
    const roomError = document.getElementById("roomError");
    const consentError = document.getElementById("consentError");

    const resetField = (input, errorDiv) => {
        input.classList.remove("is-invalid");
        input.classList.remove("is-valid");
        if (errorDiv) errorDiv.style.display = "none";
    };

    resetField(nameInput, nameError);
    resetField(emailInput, emailError);
    resetField(phoneInput, phoneError);
    resetField(dateInput, dateError);
    timeDropdown.classList.remove("is-invalid", "is-valid");
    if (timeError) timeError.style.display = "none";
    if (roomError) roomError.style.display = "none";
    if (consentError) consentError.style.display = "none";

    const name = nameInput.value.trim();
    const nameRegex = /^[a-zA-Z가-힣\s]+$/;
    if (!name || !nameRegex.test(name)) {
        nameInput.classList.add("is-invalid");
        nameError.style.display = "block";
        isValid = false;
    }

    const email = emailInput.value.trim();
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!email || !emailRegex.test(email)) {
        emailInput.classList.add("is-invalid");
        emailError.style.display = "block";
        isValid = false;
    }

    const date = dateInput.value;
    if (!date) {
        dateInput.classList.add("is-invalid");
        dateError.style.display = "block";
        isValid = false;
    }

    if (timeDropdown.selectedIndex === 0) {
        timeDropdown.classList.add("is-invalid");
        timeError.style.display = "block";
        isValid = false;
    }


   const roomSelected = [...els.roomCheckboxes].some(cb => cb.checked);

   if (!roomSelected) {
        document.getElementById("roomError").style.display = "block";
        isValid = false;
    }

    if (!consentCheckbox.checked) {
        consentError.style.display = "block";
        isValid = false;
    }
    return isValid;  
}

// 인풋형 필드에 대한 실시간 유효성 초기화
document.getElementById("name").addEventListener("input", () => {
const input = document.getElementById("name");
const error = document.getElementById("nameError");
input.classList.remove("is-invalid");
error.style.display = "none";
});

document.getElementById("email").addEventListener("input", () => {
const input = document.getElementById("email");
const error = document.getElementById("emailError");
input.classList.remove("is-invalid");
error.style.display = "none";
});

document.getElementById("phone").addEventListener("input", () => {
const input = document.getElementById("phone");
const error = document.getElementById("phoneError");
input.classList.remove("is-invalid");
error.style.display = "none";

document.getElementById("otpSection")?.classList.add("d-none");
const isVerifiedI = document.getElementById("isVerified");
if (isVerifiedI) isVerifiedI.value = '0';
});

// 셀렉트박스 및 날짜 관련
document.getElementById("startTime").addEventListener("change", () => {
const select = document.getElementById("startTime");
const error = document.getElementById("timeError");
select.classList.remove("is-invalid");
error.style.display = "none";
});


document.getElementById("date-picker").addEventListener("change", () => {
const input = document.getElementById("GB_date");
const error = document.getElementById("dateError");
input.classList.remove("is-invalid");
if (error) {
    error.style.display = "none";
    }
});

// 룸 선택 (라디오 버튼)
document.querySelectorAll('input[name="GB_room_no[]"]').forEach(checkbox => {
    checkbox.addEventListener("change", () => {
        const error = document.getElementById("roomError");
        // 하나라도 체크되었으면 에러 메시지 숨기기
        const anyChecked = [...document.querySelectorAll('input[name="GB_room_no[]"]')].some(cb => cb.checked);
            error.style.display = anyChecked ? "none" : "block";
    });
});

// 개인정보 동의 체크박스
document.getElementById("consentCheckbox").addEventListener("change", () => {
const error = document.getElementById("consentError");
error.style.display = "none";
 });

// === Phone utils (Canadian NPA whitelist) ===
// From index.php injection (Step 1-1). Keep as strings.
const CA_AREA_CODES = (window.CA_AREA_CODES || []).map(String);

// First 3 digits of a 10-digit number
function getAreaCode(digits10) {
  return String(digits10).slice(0, 3);
}

// Check against whitelist
function isAllowedCanadianArea(npa) {
  return CA_AREA_CODES.includes(String(npa));
}

async function sendOTP() {
  const phoneInput  = document.getElementById("phone");
  const phoneError  = document.getElementById("phoneError");
  const otpSection  = document.getElementById("otpSection");
  const isVerifiedI = document.getElementById("isVerified");

  // 숫자만 추출
  const digits = phoneInput.value.trim().replace(/\D/g, '');
  if (!/^\d{10}$/.test(digits)) {
    phoneInput.classList.add("is-invalid");
    if (phoneError) {
      phoneError.textContent = "Please enter a 10-digit phone number (numbers only).";
      phoneError.style.display = "block";
    }
    otpSection?.classList.add('d-none');
    if (isVerifiedI) isVerifiedI.value = '0';
    return;
  }

  // ✅ 캐나다 NPA(지역번호) 화이트리스트 검사
  const npa = getAreaCode(digits);             // 앞 3자리
  if (!isAllowedCanadianArea(npa)) {
    phoneInput.classList.add("is-invalid");
    if (phoneError) {
      phoneError.textContent = `Unsupported area code: ${npa}. Please use a valid Canadian number.
      If you are outside Canada, please contact us by phone (403-455-4951) or email (sportechgolf@gmail.com).`;
      phoneError.style.display = "block";
    }
    otpSection?.classList.add('d-none');
    if (isVerifiedI) isVerifiedI.value = '0';
    return;
  }

  // 입력값 정규화
  phoneInput.value = digits;
  phoneInput.classList.remove("is-invalid");
  if (phoneError) phoneError.style.display = "none";

  try {
    // 기존 예약 번호면 스킵
    const checkRes  = await fetch(`${API_BASE}/verify_phone/check_phone_num.php?phone=${encodeURIComponent(digits)}`, { cache: 'no-store' });
    if (!checkRes.ok) throw new Error(`HTTP ${checkRes.status}`);
    const checkData = await checkRes.json();

    if (checkData.verified === true) {
      if (isVerifiedI) isVerifiedI.value = '1';
      otpSection?.classList.add('d-none');
      alert("This number is already verified. You can proceed without verification.");
      return;
    }

    // 신규 번호 → OTP 발송
    const res  = await fetch(`${API_BASE}/verify_phone/send_otp.php`, {
      method: 'POST',
      headers: { 'Content-Type':'application/x-www-form-urlencoded' },
      body: 'phone=' + encodeURIComponent(digits)
    });
    const raw = await res.text();
      let data;
      try { data = JSON.parse(raw); } catch { data = { success:false, message: raw }; }

      if (!res.ok || !data.success) {
        alert((data.message || `Failed (HTTP ${res.status})`) + (data.details ? `\n${data.details}` : ''));
        otpSection?.classList.add('d-none');
        if (isVerifiedI) isVerifiedI.value = '0';
        return;
      }
      otpSection?.classList.remove('d-none');

  } catch (err) {
    // 네트워크/JSON 실패 등
    alert('Network error while sending/validating OTP. Please try again.');
    console.error(err);
    otpSection?.classList.add('d-none');
    if (isVerifiedI) isVerifiedI.value = '0';
  }
}



function verifyOTP() {
  const code = document.getElementById("otpCode").value.trim();
  const phoneDigits = document.getElementById("phone").value.replace(/\D/g, '').slice(0,10);

  fetch(`${API_BASE}/verify_phone/verify_otp.php`, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `phone=${encodeURIComponent(phoneDigits)}&code=${encodeURIComponent(code)}`
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      alert('Verification success!');
      document.getElementById('otpError').classList.add('d-none');
      document.getElementById('isVerified').value = '1';
      // 번호 입력값도 정규화 반영
      const phoneInput = document.getElementById("phone");
      phoneInput.value = phoneDigits;
    } else {
      document.getElementById('otpError').classList.remove('d-none');
    }
  });
}

// document.querySelectorAll(".time-slot").forEach(td => {
//   td.addEventListener("click", () => {
//     // 이미 예약되었거나 막힌 슬롯은 무시
//     if (td.classList.contains("bg-danger") || td.classList.contains("past-slot") || td.classList.contains("pe-none")) {
//       return;
//     }

//     const selectedTime = td.dataset.time;
//     const selectedRoom = td.dataset.room;

//     // select 박스에서 해당 시간 선택 (startTime)
//     els.startSelect.value = selectedTime;

//     // 룸 체크박스 자동 선택
//     els.roomCheckboxes.forEach(cb => {
//       cb.checked = cb.value === selectedRoom;
//       cb.dispatchEvent(new Event('change'));  // ✅ 문구 트리거용
//     });

//     // 날짜 및 시작시간에 맞는 종료시간 옵션 다시 세팅
//     updateStartTimes().then(() => {
//         els.startSelect.value = selectedTime;
//         els.startSelect.dispatchEvent(new Event('change'));

//     });

//       // ✅ 종료 시간 자동 선택
//     const selectedIndex = allTimes.indexOf(selectedTime);
//     const defaultEndTime = allTimes[selectedIndex + 2]; // 30분 x 2 = 1시간 뒤
//     if (defaultEndTime) {
//         els.endSelect.value = defaultEndTime;
//     }


//     // 예약 폼 열기
//     const offcanvas = new bootstrap.Offcanvas(els.offcanvasEl);
//     offcanvas.show();
//   });
// });

// ==== User Menu Modal (fixed 3 slots, show existing only) ====

async function loadMenuForUser() {
  try {
    const items = await fetchMenuFixed3();
    renderMenuImages(items);
  } catch (err) {
    console.error(err);
    renderMenuImages([]);
  }
}

function renderMenuImages(items) {
  const area = document.getElementById('menuImagesArea');
  if (!area) return;

  if (!Array.isArray(items) || items.length === 0) {
    area.innerHTML = `<div class="text-center text-muted py-5">No menu images yet.</div>`;
    return;
  }

  // Build Bootstrap Carousel dynamically
  let indicators = '';
  let inner = '';
  items.forEach((it, idx) => {
    indicators += `
      <button type="button" data-bs-target="#menuCarousel" data-bs-slide-to="${idx}"
              ${idx === 0 ? 'class="active" aria-current="true"' : ''} aria-label="Slide ${idx + 1}"></button>`;
    inner += `
      <div class="carousel-item ${idx === 0 ? 'active' : ''}">
        <img src="${it.url}" alt="menu_${it.slot}" class="d-block w-100"
             loading="lazy" style="max-height:70vh; object-fit:contain;">
      </div>`;
  });

  area.innerHTML = `
    <div id="menuCarousel" class="carousel slide carousel-dark" data-bs-interval="false">
      <div class="carousel-indicators">
        ${indicators}
      </div>
      <div class="carousel-inner">
        ${inner}
      </div>
      <button class="carousel-control-prev" type="button" data-bs-target="#menuCarousel" data-bs-slide="prev">
        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
        <span class="visually-hidden">Previous</span>
      </button>
      <button class="carousel-control-next" type="button" data-bs-target="#menuCarousel" data-bs-slide="next">
        <span class="carousel-control-next-icon" aria-hidden="true"></span>
        <span class="visually-hidden">Next</span>
      </button>
    </div>`;
}

// Open → fetch & render
document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('menuViewModal');
  if (modalEl) {
    modalEl.addEventListener('shown.bs.modal', loadMenuForUser);
  }
});

// ==== Auto Refresh (every 3 min) — customer page only ====
// ==== Customer page: unified soft auto-refresh (no badge) ====
(function customerAutoRefresh() {
  const REFRESH_MS = 1 * 60 * 1000; // 1분

  // 엘리먼트/헬퍼
  const elsRef = (window.els || {});
  const getDate = () =>
    elsRef.datePicker?.value || document.getElementById('date-picker')?.value;

  const offcanvasOpen = () => !!document.querySelector('.offcanvas.show');
  const anyModalOpen  = () => !!document.querySelector('.modal.show,[role="dialog"][open]');
  const userIsTyping  = () => {
    const ae = document.activeElement;
    return !!(ae && ae.matches('input, textarea, select, [contenteditable="true"]'));
  };
  const canRefresh = () =>
    !document.hidden && !offcanvasOpen() && !anyModalOpen() && !userIsTyping();

  // 소프트 리프레시 (데이터만 다시 칠하기)
  async function softRefresh() {
    try {
      const date = getDate();
      if (!date) return;

      if (typeof window.loadAllRoomReservations === 'function') {
        await window.loadAllRoomReservations(date);
      }
      if (typeof window.markPastTableSlots === 'function') {
        // 고객용은 셀렉터/옵션 없이도 OK (네 기존 시그니처 유지)
        window.markPastTableSlots(date);
      }
      window.__lastRefreshAt = new Date(); // 필요시 디버깅에 사용
    } catch (e) {
      console.warn('[customer] softRefresh failed:', e);
    }
  }

  // 타이머 틱 (외부 참조 안 함)
  async function tick() {
    if (canRefresh()) await softRefresh();
  }

  // 주기 갱신 시작 (전역 타이머 핸들 저장)
  if (window.__customerReloadTimer) clearInterval(window.__customerReloadTimer);
  window.__customerReloadTimer = setInterval(tick, REFRESH_MS);

  // 탭 활성화 시 즉시 한 번
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && canRefresh()) tick();
  });

  // 모달/오프캔버스 닫히면 즉시 한 번 (같은 스코프의 tick 호출 → ReferenceError 없음)
  document.addEventListener('hidden.bs.modal', () => { tick(); });
  document.addEventListener('hidden.bs.offcanvas', () => { tick(); });

  // 디버그용 수동 트리거(선택)
  window.__forceRefreshNow = () => softRefresh();
})();

try {
  const bc = new BroadcastChannel('booking_sync');
  bc.onmessage = (e) => {
    if (e.data?.type === 'move_done') {
      console.log('Detected reservation move — refreshing');
      if (typeof refreshScreen === 'function') {
        refreshScreen({ reason: 'admin-move' });
      } else {
        location.reload();
      }
    }
  };
} catch (err) {
  console.warn('BroadcastChannel not supported:', err);
}