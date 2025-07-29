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
    fetch(`/api/get_reserved_info.php?date=${date}&room=${room}`)
      .then(res => res.json())
      .then(data => {
        markReservedTimes(data, ".time-slot");
        if (window.IS_ADMIN === true || window.IS_ADMIN === "true") {
          setupAdminSlotClick(); // ✅ 클릭 이벤트 등록
        }
      })
      .catch(err => console.error("Fail to fetch:", err));
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

prevBtn.addEventListener("click", () => {
  const dateStr = els.datePicker.value;
  prevDate(dateStr, {}, handlers);  // ❗ 날짜 제한 없음
});

nextBtn.addEventListener("click", () => {
  const dateStr = els.datePicker.value;
  nextDate(dateStr, {}, handlers);  // ❗ 날짜 제한 없음
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

// 예약된 슬롯 클릭 시 정보 표시 (관리자 전용)
document.querySelectorAll('.time-slot.bg-danger').forEach(slot => {
  slot.addEventListener('click', () => {
    if (!(window.IS_ADMIN === true || window.IS_ADMIN === "true")) return;

    const name = slot.getAttribute('title')?.split('\n')[0] || 'N/A';
    const phone = slot.getAttribute('title')?.split('\n')[1] || 'N/A';
    const email = slot.getAttribute('title')?.split('\n')[2] || 'N/A';


    document.getElementById('resvName').textContent = name;
    document.getElementById('resvPhone').textContent = phone;
    document.getElementById('resvEmail').textContent = email;

    const modal = new bootstrap.Modal(document.getElementById('reservationDetailModal'));
    modal.show();
  });
});

function setupAdminSlotClick() {
  document.querySelectorAll('.time-slot.bg-danger').forEach(slot => {
    slot.addEventListener('click', () => {
      const tooltip = slot.getAttribute('title') || '';
      const [name, phone, email] = tooltip.split('\n');


      document.getElementById('resvName').textContent = name || 'N/A';
      document.getElementById('resvPhone').textContent = phone || 'N/A';
      document.getElementById('resvEmail').textContent = email || 'N/A';
      const resvId = slot.dataset.resvId;
      document.getElementById('reservationDetailModal').dataset.resvId = resvId;



      const modal = new bootstrap.Modal(document.getElementById('reservationDetailModal'));
      modal.show();
    });
  });
}

function validDateForm() {
  const form = document.getElementById('bookingForm');
  if (!form) return false;

  const name = form.querySelector('input[name="GB_name"]');
  const email = form.querySelector('input[name="GB_email"]');
  const phone = form.querySelector('input[name="GB_phone"]');
  const startTime = form.querySelector('select[name="GB_start_time"]');
  const endTime = form.querySelector('select[name="GB_end_time"]');

  let isValid = true;

  [name, email, phone, startTime, endTime].forEach(el => {
    if (!el || !el.value.trim()) {
      el?.classList.add("is-invalid");
      isValid = false;
    } else {
      el?.classList.remove("is-invalid");
    }
  });

  return isValid;
}

// 예약 지우기
document.getElementById("deleteReservationBtn").addEventListener("click", () => {
  const modal = document.getElementById("reservationDetailModal");
  const id = modal.dataset.resvId;

  if (!id) {
    alert("Reservation ID is missing!");
    return;
  }

  if (!confirm("Are you sure you want to delete this reservation?")) return;

  fetch(`/api/delete_reservation.php?id=${id}`, {
    method: "DELETE",
    headers: { "Content-Type": "application/json" },
    body: `id=${id}`
  })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        alert("Reservation deleted.");
        bootstrap.Modal.getInstance(modal).hide();
        clearAllTimeSlots();
        loadAllRoomReservations(document.getElementById('date-picker').value);
      } else {
        alert("Failed to delete reservation.");
      }
    })
    .catch(() => alert("Error occurred while deleting."));
});