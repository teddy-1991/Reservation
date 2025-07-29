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

const selectedDate = els.datePicker.value;
const prevBtn = document.getElementById("prevDateBtn");
const nextBtn = document.getElementById("nextDateBtn");
let flatpickrInstance;

flatpickrInstance = setupDatePicker(function (selectedDate) {
  updateDateInputs(selectedDate, flatpickrInstance);
  clearAllTimeSlots();
  loadAllRoomReservations(toYMD(selectedDate));
  markPastTableSlots();
});

// ✅ handlers 주입 필수
handlers.updateDateInputs = (date) => updateDateInputs(date, flatpickrInstance);


updateDateInputs(selectedDate);
setupGlobalDateListeners(els);
setupSlotClickHandler(els);

clearAllTimeSlots();

markPastTableSlots(els.datePicker.value, ".time-slot", { disableClick: false });

setupStartTimeUpdater(els);
setupEndTimeUpdater(els);

setupOffcanvasDateSync(els);
setupOffcanvasBackdropCleanup(els);
setupOffcanvasCloseFix(els);  // ✅ 추가


handleReservationSubmit(els, { requireOTP: false });

if (prevBtn) {
  prevBtn.addEventListener("click", () => {
    const currentDateStr = document.getElementById("date-picker").value;
    prevDate(currentDateStr, { minDate: today }, handlers);
  });
}

if (nextBtn) {
  nextBtn.addEventListener("click", () => {
    const currentDateStr = document.getElementById("date-picker").value;
    nextDate(currentDateStr, { maxDate }, handlers);
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