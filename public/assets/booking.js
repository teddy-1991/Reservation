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

// 상수
const allTimes = window.ALL_TIMES; // PHP가 미리 심어준 전역 배열 사용
const BUFFER_MIN = 60; // 예약 가능 시간 버퍼 (분 단위)
let suppressChange = false;

const today = new Date();
today.setHours(0, 0, 0, 0);

const maxDate = new Date(today);
maxDate.setDate(today.getDate() + 28);
maxDate.setHours(0, 0, 0, 0);


// 유틸 

// helper: Date 객체 -> "YYYY-MM-DD" 문자열
function toYMD(date) {
    return date.toISOString().slice(0,10);
}

function add30Minutes(timeStr) {
    const [hour, minute] = timeStr.split(":").map(Number);
    const date = new Date();
    date.setHours(hour, minute + 30, 0);

    const hh = String(date.getHours()).padStart(2, '0');
    const mm = String(date.getMinutes()).padStart(2, '0');
    return `${hh}:${mm}`;
}

// 현재 체크된 방 번호 배열 반환
function getCheckedRooms(){
  return [...els.roomCheckboxes].filter(cb=> cb.checked).map(cb => cb.value);
}

function clearAllTimeSlots() {
    const slots = document.querySelectorAll('.time-slot');
    slots.forEach(slot => {
        slot.classList.remove('bg-danger', 'text-white','past-slot','pe-none'); // 예약 칠한 클래스 제거
        slot.innerText = ""; // 텍스트도 비워줌 (예: "X" 등)
    });
}

// helper: datePicker + form에 모두 새 날짜 반영
function updateDateInputs(date) {
    const ymd = toYMD(date);
    suppressChange = true;
    els.datePicker.value = ymd;
    suppressChange = false;
    els.bookingDateInput.value = ymd;
}


els.startSelect.addEventListener('change', ()=> {
    const startTime = els.startSelect.value;
    const startIdx = allTimes.indexOf(startTime);
    els.endSelect.innerHTML = "";

    for (let i = startIdx + 2; i < allTimes.length; i++) {
        const option = document.createElement("option");
        option.value = allTimes[i];
        option.textContent = allTimes[i];
        els.endSelect.appendChild(option);
    }
});

els.offcanvasEl.addEventListener('show.bs.offcanvas', function () {
    const selectedDate = els.datePicker.value;
    els.bookingDateInput.value = selectedDate;
    els.formDateDisplay.textContent = selectedDate;  // ← 여기가 중요!
    console.log("오프캔버스 열릴 때 설정된 날짜:", selectedDate);
});

// date picker 직접 수정했을 때
els.datePicker.addEventListener('change', () => {
    const [year, month, day] = els.datePicker.value.split('-').map(Number);
    const selectedDate = new Date();
    selectedDate.setFullYear(year, month - 1, day);
    selectedDate.setHours(0, 0, 0, 0);
            
    if (selectedDate < today) {
        alert("You cannot select a past date.");
        updateDateInputs(today);
        return;
    }

    if (selectedDate > maxDate) {
        alert("You can only book within 8 weeks from today.");
        updateDateInputs(maxDate);
        return;
    }

    updateDateInputs(selectedDate);
    markPastTableSlots(); // 지나간 타임-셀 표시
});

function prevDate() {
    const [year, month, day] = els.datePicker.value.split('-').map(Number);
    const current = new Date();
    current.setFullYear(year, month - 1, day);
    current.setHours(0, 0, 0, 0);

    const previous = new Date(current);
    previous.setDate(previous.getDate() - 1);

    if (previous < today) {
        alert("You cannot go to a past date.");
        return;
    }
    const formatted = toYMD(previous);
    updateDateInputs(previous);
    clearAllTimeSlots();
    loadAllRoomReservations(formatted);
    markPastTableSlots();
}

// 다음 날짜 버튼
function nextDate() {
    const current = new Date(els.datePicker.value);
    const next = new Date(current);
    next.setDate(next.getDate() + 1);

    if (next > maxDate) {
        alert("You can only book within 2 months from today.");
        return;
    }
            
    const formatted = toYMD(next);
    updateDateInputs(next);
    clearAllTimeSlots();
    loadAllRoomReservations(formatted);
    markPastTableSlots();
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

// DB에 가져오기
function fetchReservedTimes(date, room) {
    fetch(`/api/get_reserved_times.php?date=${date}&room=${room}`)
    .then(response => response.json())
    .then(reservedTimes => {
        markReservedTimes(reservedTimes, room);
    })
    .catch(error => {
        console.error("Fail to fetch the data:", error);
    });
}



function markReservedTimes(reservedTimes){
  reservedTimes.forEach(item=>{
      let current = item.start_time.slice(0,5);  // "10:00:00" → "10:00"
      const end   = item.end_time.slice(0,5);

      while(current < end){
          const slot = document.querySelector(
               `.time-slot[data-time='${current}'][data-room='${item.room_no}']`);
          if(slot){
              slot.classList.add('bg-danger','text-white');
              slot.innerText = 'Booked!';
          }
          current = add30Minutes(current);
      }
  });
}

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

    const phone = phoneInput.value.trim();
    const phoneRegex = /^[0-9]{10,11}$/;
    if (!phone || !phoneRegex.test(phone)) {
        phoneInput.classList.add("is-invalid");
        phoneError.style.display = "block";
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

document.addEventListener("DOMContentLoaded", function () {
    const form = document.querySelector("#bookingForm");
    if (!form) {
        console.error("form not found!");
        return;
    }

    form.addEventListener("submit", async function (e) {
        e.preventDefault();

        if (!validDateForm()) return;

        if (document.getElementById('isVerified').value !== '1') {
            alert("Please verify your phone number before booking.");
            return;
        }
        
        const formData = new FormData(form);

        getCheckedRooms().forEach(room => {
        formData.append("GB_room_no[]", room);
        });

        const date = formData.get("GB_date");
        const startTime = formData.get("GB_start_time");

        for (const room of getCheckedRooms()) {
            const reservedTimes = await fetch(`/api/get_reserved_times.php?date=${date}&room=${room}`)
                .then(r => r.json());
            

            if (reservedTimes.includes(startTime)) {
            alert(`Room ${room} is already booked at ${startTime}. Please choose another time.`);
            return;
            }
        }   

        fetch('/api/create_reservation.php', {method:'POST', body: formData})
        .then(res=>{
            if (res.status === 409) return res.json().then(j=>{
                alert("⚠️ " + j.message);
                // 최신 예약 현황 다시 불러오기
                loadAllRoomReservations(els.datePicker.value);
                rebuildStartOptions([]);     // 드롭다운 초기화
                updateStartTimes(); // 시작 시간 옵션 초기화
                throw new Error('conflict');
            });
            if (!res.ok) throw new Error('server');
            return res.json();
        })
        .then(()=> {
            alert("Reservation complete!");
            bootstrap.Offcanvas.getInstance(els.offcanvasEl).hide();
            loadAllRoomReservations(els.datePicker.value);    // 테이블 리프레시
        })
        .catch(err=>{
            if (err.message !== 'conflict')
                alert("Reservation failed. Please try again.");
        });
        });
    });

    const allRoomNumbers = [1, 2, 3, 4, 5];

    function loadAllRoomReservations(date) {
    allRoomNumbers.forEach(room => {
        fetchReservedTimes(date, room);
    });
 }

// 최초 페이지 로드시
window.addEventListener("DOMContentLoaded", () => {
loadAllRoomReservations(els.datePicker.value);
markPastTableSlots(); // 지나간 타임-셀 표시
updateStartTimes(); // 시작 시간 옵션 초기화
});

// 날짜 바뀔 때마다
els.datePicker.addEventListener("change", (e) => {
    const selectedDate = e.target.value;

    loadAllRoomReservations(selectedDate);
    clearAllTimeSlots(); // 날짜 바뀌면 초기화
    markPastTableSlots(); // 지나간 타임-셀 표시
});




function markPastTableSlots() {
  const todayYmd = new Date().toISOString().slice(0, 10);
  const selectedDate = els.datePicker.value;
  const now = new Date();
  const nowMin = now.getHours() * 60 + now.getMinutes();

  document.querySelectorAll(".time-slot").forEach(td => {
    const time = td.dataset.time;
    const room = td.dataset.room;
    if (!time || !room) return;

    // ✅ 예약된 셀은 건드리지 말자!
    if (td.classList.contains("bg-danger")) return;

    // 초기화
    td.classList.remove("pe-none");

    const [hh, mm] = time.split(":").map(Number);
    const slotMin = hh * 60 + mm;

    // 방 별로 열리는 시간/닫히는 시간 설정
    const isLateRoom = room === "4" || room === "5";
    const OPEN_MIN = isLateRoom ? 9 * 60 + 30 : 9 * 60;       // 9:30 or 9:00
    const CLOSE_MIN = isLateRoom ? 21.5 * 60 : 22 * 60;       // 21:30 or 22:00

    // 제한 조건 계산
    const isPast = (selectedDate === todayYmd) && (slotMin <= nowMin);
    const tooEarly = slotMin < OPEN_MIN;
    const tooLate = slotMin + 60 > CLOSE_MIN;  // 종료시간 기준 체크

    if (isPast || tooEarly || tooLate) {
      td.classList.add("pe-none");  // ✅ 클릭만 막음 (스타일 그대로)
    }

    if (isPast) {
        td.classList.add("past-slot");
    }

  });
}


function rebuildStartOptions(reservedTimes) {
    els.startSelect.innerHTML = '<option disabled selected>Select a start time</option>';
    reservedTimes.forEach(t => {
        const opt = document.createElement('option');
        opt.value = t;
        opt.textContent = t;
        els.startSelect.appendChild(opt);
    });

    els.endSelect.innerHTML = '<option disabled selected>Select a start time first</option>';
}

async function updateStartTimes() {
    const date = els.datePicker.value;
    const rooms = getCheckedRooms();

    if (!date || rooms.length === 0) {
        rebuildStartOptions([]);
        return;
    }
    
    const roomParam = rooms.length===1
        ? `room=${rooms[0]}`
        : `rooms=${rooms.join(',')}`;

    const LateRooms = rooms.some( r => r === '4' || r === '5');
    const CLOSE_HOUR = LateRooms ? 21.5 : 22;
    const OPEN_MIN = LateRooms ? 9*60 + 30 : 9*60; // 9:30 or 9:00
    const res = await fetch(`/api/get_reserved_times.php?date=${date}&${roomParam}`);
    const data = await res.json();

    const reservedRanges = data.map(r=> {
        const [sh, sm] = r.start_time.slice(0,5).split(":").map(Number);
        const [eh, em] = r.end_time.slice(0,5).split(":").map(Number);
        return { start: sh*60+sm, end: eh*60+em };
    });

    const todayYmd = new Date().toISOString().slice(0,10);
    const now = new Date();
    const nowMin = now.getHours() * 60 + now.getMinutes();

    const avail = allTimes.filter(t => {
        const [hh, mm] = t.split(":").map(Number);
        const slotStart = hh * 60 + mm;

        const isPast = (date === todayYmd) && (slotStart <= nowMin);
        const overlap = reservedRanges.some(r => slotStart < r.end && (slotStart + 30) > r.start);
        const beforeOpen = slotStart < OPEN_MIN;
        const endTooLate = slotStart + 60 > CLOSE_HOUR * 60;

        return !beforeOpen && !overlap && !isPast && !endTooLate;
    });

    rebuildStartOptions(avail);
}

els.datePicker.addEventListener('change', updateStartTimes);

flatpickr('#date-picker', {
  dateFormat: 'Y-m-d',          // 기존 PHP가 기대하는 YYYY-MM-DD 형식
  minDate: 'today',
  maxDate: new Date().fp_incr(28)  // 4 주 뒤
});

async function sendOTP() {
  const phone = document.getElementById("phone").value.trim();

  // ✅ 번호 길이 검증 먼저
  if (phone.length !== 10) {
    document.getElementById('phoneError').classList.remove('d-none');
    document.getElementById('otpSection').classList.add('d-none');
    return;
  }

  // ✅ 먼저 DB에서 인증된 번호인지 확인
  const checkRes = await fetch(`/api/check_phone_num.php?phone=${encodeURIComponent(phone)}`);
  const checkData = await checkRes.json();

  if (checkData.verified === true) {
    alert("This number is already verified. You can proceed without verification.");
    document.getElementById('isVerified').value = '1';
    document.getElementById('otpSection').classList.add('d-none');
    return;
  }

  // ✅ 아니면 기존대로 OTP 요청 진행
  fetch('/api/send_otp.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'phone=' + encodeURIComponent(phone)
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      document.getElementById('otpSection').classList.remove('d-none');
    } else {
      alert(data.message || 'Failed to send code');
    }
  });
}


function verifyOTP() {
  const code = document.getElementById("otpCode").value;
  const phone = document.getElementById("phone").value;

  fetch('/api/verify_otp.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `phone=${encodeURIComponent(phone)}&code=${encodeURIComponent(code)}`
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      alert('Verification success!');
      document.getElementById('otpError').classList.add('d-none');
      document.getElementById('isVerified').value = '1';
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