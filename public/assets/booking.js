
const allTimes = window.ALL_TIMES; // PHP가 미리 심어준 전역 배열 사용
let suppressChange = false;

const today = new Date();
today.setHours(0, 0, 0, 0);

const maxDate = new Date(today);
maxDate.setDate(today.getDate() + 56);
maxDate.setHours(0, 0, 0, 0);

const datePicker = document.getElementById('date-picker');
const bookingDateInput = document.getElementById('GB_date');
const formDateDisplay = document.getElementById('form-selected-date');
       
const notice = document.getElementById('rightHandedNotice');
const roomCheckboxes = document.querySelectorAll('input[name="GB_room_no[]"]');

const startSelect = document.getElementById('startTime');
const endSelect = document.getElementById('endTime');

const offcanvasEl = document.getElementById('bookingCanvas');
const formEl = document.getElementById('bookingForm');
const roomNote = document.getElementById('roomNote');

offcanvasEl.addEventListener('hidden.bs.offcanvas', function () {
    formEl.reset(); // 폼 전체 초기화

    const handSelect = document.getElementById('handPreference');
    if (handSelect) handSelect.selectedIndex = 0;

    endSelect.innerHTML = '<option disabled selected>Select a start time first</option>';

    // 경고 문구 숨기기
    notice.classList.add('d-none');

    // 버튼 active 제거
    document.querySelectorAll('.room-btn').forEach(btn => btn.classList.remove('active'));
});

startSelect.addEventListener('change', ()=> {
    const startTime = startSelect.value;
    const startIdx = allTimes.indexOf(startTime);
    endSelect.innerHTML = "";

    for (let i = startIdx + 2; i < allTimes.length; i++) {
        const option = document.createElement("option");
        option.value = allTimes[i];
        option.textContent = allTimes[i];
        endSelect.appendChild(option);
    }
});

offcanvasEl.addEventListener('show.bs.offcanvas', function () {
    const selectedDate = datePicker.value;
    bookingDateInput.value = selectedDate;
    formDateDisplay.textContent = selectedDate;  // ← 여기가 중요!
    console.log("오프캔버스 열릴 때 설정된 날짜:", selectedDate);
});

// date picker 직접 수정했을 때
datePicker.addEventListener('change', () => {
    const [year, month, day] = datePicker.value.split('-').map(Number);
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

// helper: Date 객체 -> "YYYY-MM-DD" 문자열
function toYMD(date) {
    return date.toISOString().slice(0,10);
}

// helper: datePicker + form에 모두 새 날짜 반영
function updateDateInputs(date) {
    const ymd = toYMD(date);
    suppressChange = true;
    datePicker.value = ymd;
    suppressChange = false;
    bookingDateInput.value = ymd;
}

function prevDate() {
    const [year, month, day] = datePicker.value.split('-').map(Number);
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
    const current = new Date(datePicker.value);
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
roomCheckboxes.forEach(checkbox => {
    checkbox.addEventListener('change', () => {
        const isRoom2Selected = Array.from(roomCheckboxes)
        .some(cb => cb.checked && cb.value === "2");

        if (isRoom2Selected) {
            notice.classList.remove('d-none');
        } else {
            notice.classList.add('d-none');
        }
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

function add30Minutes(timeStr) {
    const [hour, minute] = timeStr.split(":").map(Number);
    const date = new Date();
    date.setHours(hour, minute + 30, 0);

    const hh = String(date.getHours()).padStart(2, '0');
    const mm = String(date.getMinutes()).padStart(2, '0');
    return `${hh}:${mm}`;
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
              slot.innerText = 'X';
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
    const guestsInput = document.getElementById("guests");
    const dateInput = document.getElementById("GB_date");
    const timeDropdown = document.getElementById("startTime");
    const handDropdown = document.getElementById("handedness");
    const consentCheckbox = document.getElementById("consentCheckbox");

    const nameError = document.getElementById("nameError");
    const emailError = document.getElementById("emailError");
    const phoneError = document.getElementById("phoneError");
    const guestsError = document.getElementById("guestsError");
    const dateError = document.getElementById("dateError");
    const timeError = document.getElementById("timeError");
    const handError = document.getElementById("handError");
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
    resetField(guestsInput, guestsError);
    resetField(dateInput, dateError);
    timeDropdown.classList.remove("is-invalid", "is-valid");
    handDropdown.classList.remove("is-invalid", "is-valid");
    if (timeError) timeError.style.display = "none";
    if (handError) handError.style.display = "none";
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

    const guests = guestsInput.value.trim();
    const guestRegex = /^[0-9]+$/;
    if (!guests || !guestRegex.test(guests)) {
        guestsInput.classList.add("is-invalid");
        guestsError.style.display = "block";
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

    if (handDropdown.selectedIndex === 0) {
        handDropdown.classList.add("is-invalid");
        handError.style.display = "block";
        isValid = false;
    }

    const roomCheckboxes = document.querySelectorAll('input[name="GB_room_no[]"]');
   const roomSelected = [...roomCheckboxes].some(cb => cb.checked);

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

document.getElementById("guests").addEventListener("input", () => {
const input = document.getElementById("guests");
const error = document.getElementById("guestsError");
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

document.getElementById("handedness").addEventListener("change", () => {
const select = document.getElementById("handedness");
const error = document.getElementById("handError");
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
                loadAllRoomReservations(datePicker.value);
                rebuildStartOptions([]);     // 드롭다운 초기화
                throw new Error('conflict');
            });
            if (!res.ok) throw new Error('server');
            return res.json();
        })
        .then(()=> {
            alert("Reservation complete!");
            bootstrap.Offcanvas.getInstance(offcanvasEl).hide();
            loadAllRoomReservations(datePicker.value);    // 테이블 리프레시
        })
        .catch(err=>{
            if (err.message !== 'conflict')
                alert("Reservation failed. Please try again.");
        });
        });
    });

    const bookedDate = document.querySelector("input[type='date']");
    const allRoomNumbers = [1, 2, 3, 4, 5];

    function loadAllRoomReservations(date) {
    allRoomNumbers.forEach(room => {
        fetchReservedTimes(date, room);
    });
 }

// 최초 페이지 로드시
window.addEventListener("DOMContentLoaded", () => {
loadAllRoomReservations(bookedDate.value);
markPastTableSlots(); // 지나간 타임-셀 표시
});

// 날짜 바뀔 때마다
bookedDate.addEventListener("change", (e) => {
    const selectedDate = e.target.value;

    loadAllRoomReservations(selectedDate);
    clearAllTimeSlots(); // 날짜 바뀌면 초기화
    markPastTableSlots(); // 지나간 타임-셀 표시
});


function clearAllTimeSlots() {
    const slots = document.querySelectorAll('.time-slot');
    slots.forEach(slot => {
        slot.classList.remove('bg-danger', 'text-white','past-slot','pe-none'); // 예약 칠한 클래스 제거
        slot.innerText = ""; // 텍스트도 비워줌 (예: "X" 등)
    });
        }

function markPastTableSlots(){
    const todayYmd = new Date().toISOString().slice(0,10);
    const selectedDate = datePicker.value;        // YYYY-MM-DD
    const now = new Date();
    const nowMin = now.getHours()*60 + now.getMinutes();

    document.querySelectorAll(".time-slot").forEach(td=>{
        // 이미 예약(빨간 셀)이면 그대로 둠
        if(td.classList.contains("bg-danger")) return;

        // 초기화
        td.classList.remove("past-slot","pe-none");
        if(td.dataset.orig) td.innerHTML = td.dataset.orig;  // 이전에 저장한 내용 복원

        if(selectedDate===todayYmd){
            const [hh,mm] = td.dataset.time.split(":").map(Number);
            const slotMin = hh*60 + mm;
                if(slotMin <= nowMin){
                    td.dataset.orig = td.innerHTML;   // 나중에 초기화용 백업
                    td.innerHTML = "X";
                    td.classList.add("past-slot","pe-none");
                }
        }
    });   
}
// 현재 체크된 방 번호 배열 반환
function getCheckedRooms(){
  return [...document.querySelectorAll('input[name="GB_room_no[]"]:checked')]
           .map(cb => cb.value);
}