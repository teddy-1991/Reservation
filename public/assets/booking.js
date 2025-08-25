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
const allRoomNumbers = [1, 2, 3, 4, 5];

function loadAllRoomReservations(date) {
  allRoomNumbers.forEach(room => {
    fetchReservedTimes(date, room);
  });
}

const handlers = {
  updateDateInputs: (date) => updateDateInputs(date, flatpickrInstance),
  clearAllTimeSlots,
  loadAllRoomReservations,
  markPastTableSlots
};

// 상수
const allTimes = window.ALL_TIMES; // PHP가 미리 심어준 전역 배열 사용
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

// ✅ 이 코드 추가
handlers.updateDateInputs = (date) => updateDateInputs(date, flatpickrInstance);


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

  // 입력값 정규화
  phoneInput.value = digits;
  phoneInput.classList.remove("is-invalid");
  if (phoneError) phoneError.style.display = "none";

  // 기존 예약 번호면 스킵
  const checkRes  = await fetch(`${API_BASE}/check_phone_num.php?phone=${encodeURIComponent(digits)}`);
  const checkData = await checkRes.json();

  if (checkData.verified === true) {
    if (isVerifiedI) isVerifiedI.value = '1';
    otpSection?.classList.add('d-none');
    alert("This number is already verified. You can proceed without verification.");
    return;
  }

  // 신규 번호 → OTP 발송
  const res  = await fetch(`${API_BASE}/send_otp.php`, {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'phone=' + encodeURIComponent(digits)
  });
  const data = await res.json();
  if (data.success) {
    otpSection?.classList.remove('d-none');
  } else {
    alert(data.message || 'Failed to send code');
  }
}



function verifyOTP() {
  const code = document.getElementById("otpCode").value.trim();
  const phoneDigits = document.getElementById("phone").value.replace(/\D/g, '').slice(0,10);

  fetch(`${API_BASE}/verify_otp.php`, {
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

document.querySelectorAll(".time-slot").forEach(td => {
  td.addEventListener("click", () => {
    // 이미 예약되었거나 막힌 슬롯은 무시
    if (td.classList.contains("bg-danger") || td.classList.contains("past-slot") || td.classList.contains("pe-none")) {
      return;
    }

    const selectedTime = td.dataset.time;
    const selectedRoom = td.dataset.room;

    // select 박스에서 해당 시간 선택 (startTime)
    els.startSelect.value = selectedTime;

    // 룸 체크박스 자동 선택
    els.roomCheckboxes.forEach(cb => {
      cb.checked = cb.value === selectedRoom;
      cb.dispatchEvent(new Event('change'));  // ✅ 문구 트리거용
    });

    // 날짜 및 시작시간에 맞는 종료시간 옵션 다시 세팅
    updateStartTimes().then(() => {
        els.startSelect.value = selectedTime;
        els.startSelect.dispatchEvent(new Event('change'));

    });

      // ✅ 종료 시간 자동 선택
    const selectedIndex = allTimes.indexOf(selectedTime);
    const defaultEndTime = allTimes[selectedIndex + 2]; // 30분 x 2 = 1시간 뒤
    if (defaultEndTime) {
        els.endSelect.value = defaultEndTime;
    }


    // 예약 폼 열기
    const offcanvas = new bootstrap.Offcanvas(els.offcanvasEl);
    offcanvas.show();
  });
});
