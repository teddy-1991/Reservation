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
maxDate.setDate(today.getDate() + 56);
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

flatpickr('#date-picker', {
  dateFormat: 'Y-m-d',          // 기존 PHP가 기대하는 YYYY-MM-DD 형식
  minDate: 'today',
  maxDate: new Date().fp_incr(56)  // 8 주 뒤
});

// helper: datePicker + form에 모두 새 날짜 반영
function updateDateInputs(date) {
    const ymd = toYMD(date);
    suppressChange = true;
    els.datePicker.value = ymd;
    suppressChange = false;
    els.bookingDateInput.value = ymd;
}

function clearAllTimeSlots() {
    const slots = document.querySelectorAll('.time-slot');
    slots.forEach(slot => {
        slot.classList.remove('bg-danger', 'text-white','past-slot','pe-none'); // 예약 칠한 클래스 제거
        slot.innerText = ""; // 텍스트도 비워줌 (예: "X" 등)
    });
}

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
                if(slotMin <= nowMin - BUFFER_MIN){
                    td.dataset.orig = td.innerHTML;   // 나중에 초기화용 백업
                    td.innerHTML = "X";
                    td.classList.add("past-slot","pe-none");
                }
        }
    });   
}

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


const allRoomNumbers = [1, 2, 3, 4, 5];

function loadAllRoomReservations(date) {
allRoomNumbers.forEach(room => {
    fetchReservedTimes(date, room);
});
}

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


// 최초 페이지 로드시
window.addEventListener("DOMContentLoaded", () => {
loadAllRoomReservations(els.datePicker.value);
markPastTableSlots(); // 지나간 타임-셀 표시
});

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
            // ✅ 여기서 미리보기 이미지 src 변경
           console.log("업로드 성공, 이미지 변경 시도");
            const img = document.getElementById('priceTableImg');
            if (img) {
                img.src = '/images/price_table.png?t=' + new Date().getTime();
                console.log("이미지 src 바꿈:", img.src);
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
