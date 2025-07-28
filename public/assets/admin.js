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
    flatpickrInstance.setDate(ymd, true);        // flatpickr UI도 동기화

    suppressChange = false;

    if (els.bookingDateInput) els.bookingDateInput.value = ymd;
    if (els.formDateDisplay) els.formDateDisplay.textContent = ymd;

}

function prevDate() {
    const [year, month, day] = els.datePicker.value.split('-').map(Number);
    const current = new Date();
    current.setFullYear(year, month - 1, day);
    current.setHours(0, 0, 0, 0);

    const previous = new Date(current);
    previous.setDate(previous.getDate() - 1);

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
            
    const formatted = toYMD(next);
    updateDateInputs(next);
    clearAllTimeSlots();
    loadAllRoomReservations(formatted);
    markPastTableSlots();

}

// DB에 가져오기
function fetchReservedTimes(date, room) {
    fetch(`/api/get_reserved_info.php?date=${date}&room=${room}`)
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
      const tooltip = `${item.GB_name}\n${item.GB_phone}\n${item.GB_email}`;

      while(current < end){
          const slot = document.querySelector(
               `.time-slot[data-time='${current}'][data-room='${item.room_no}']`);
          if(slot){
              slot.classList.add('bg-danger','text-white');
              slot.innerText = 'O';
              slot.setAttribute('title', tooltip);
          }
          current = add30Minutes(current);
      }
    });

}

// 최초 페이지 로드시
window.addEventListener("DOMContentLoaded", () => {
loadAllRoomReservations(els.datePicker.value);
markPastTableSlots(); // 지나간 타임-셀 표시
});

// 날짜 바뀔 때마다
els.datePicker.addEventListener("change", (e) => {
    const selectedDate = e.target.value;

    loadAllRoomReservations(selectedDate);
    clearAllTimeSlots(); // 날짜 바뀌면 초기화
    markPastTableSlots(); // 지나간 타임-셀 표시
});


function markPastTableSlots(){
    const todayYmd = new Date().toISOString().slice(0,10);
    const selectedDate = els.datePicker.value;        // YYYY-MM-DD
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
                    td.classList.add("past-slot","pe-none");
                }
        }
    });   
}


const flatpickrInstance = flatpickr('#date-picker', {
  dateFormat: 'Y-m-d',
  minDate: null,
  maxDate: null,
  disableMobile: true,
  closeOnSelect: true,
  onValueUpdate: function(selectedDates, dateStr, instance) {
    if (suppressChange) return;

    // dateStr이 비었으면 → 유효하지 않은 날짜 선택 시도
    if (!dateStr) return;

    const [year, month, day] = dateStr.split('-').map(Number);
    const selectedDate = new Date();
    selectedDate.setFullYear(year, month - 1, day);
    selectedDate.setHours(0, 0, 0, 0);


    updateDateInputs(selectedDate);
    clearAllTimeSlots();
    loadAllRoomReservations(toYMD(selectedDate));
    markPastTableSlots();
  }
});


const allRoomNumbers = [1, 2, 3, 4, 5];

function loadAllRoomReservations(date) {
allRoomNumbers.forEach(room => {
    fetchReservedTimes(date, room);
});
}


if (window.IS_ADMIN === true || window.IS_ADMIN === "true") {
  document.getElementById('editPriceBtn').classList.remove('d-none');
}

document.getElementById('editPriceBtn').addEventListener('click', () => {
    document.getElementById('priceImageInput').classList.remove('d-none');
    document.getElementById('savePriceBtn').classList.remove('d-none');
});

document.getElementById('savePriceBtn').addEventListener('click', () => {
    const fileInput = document.getElementById('priceImageInput');
    const file = fileInput.files[0];

    if (!file) {
        alert("Please choose an image.");
        return;
    }

    const formData = new FormData();
    formData.append("priceTableImage", file);

    fetch("/includes/upload_price_table.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
;
            const img = document.getElementById('priceTableImg');
            if (img) {
                img.src = '/images/price_table.png?t=' + new Date().getTime();
            } else {
                console.log("❌ priceTableImg 못 찾음");
            }


            alert("Image updated!");
            fileInput.classList.add('d-none');
            document.getElementById('savePriceBtn').classList.add('d-none');
        } else {
            alert("Upload failed.");
        }
    });
});

function showBusinessHours() {
  document.getElementById('adminMainList').classList.add('d-none');
  document.getElementById('businessHoursForm').classList.remove('d-none');
}

function backToAdminList() {
  document.getElementById('businessHoursForm').classList.add('d-none');
  document.getElementById('adminMainList').classList.remove('d-none');
}

document.querySelectorAll('.closed-checkbox').forEach(checkbox => {
  checkbox.addEventListener('change', function () {
    const day = this.dataset.day;
    const openInput = document.querySelector(`.open-time[data-day="${day}"]`);
    const closeInput = document.querySelector(`.close-time[data-day="${day}"]`);

    const shouldDisable = this.checked;
    openInput.disabled = shouldDisable;
    closeInput.disabled = shouldDisable;
  });
});

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
    const res = await fetch(`/api/get_reserved_info.php?date=${date}&${roomParam}`);
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

// 현재 체크된 방 번호 배열 반환
function getCheckedRooms(){
  return [...els.roomCheckboxes].filter(cb=> cb.checked).map(cb => cb.value);
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