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
window.isEditMode = false;

function loadAllRoomReservations(date) {
  allRoomNumbers.forEach(room => {
    fetch(`/api/get_reserved_info.php?date=${date}&room=${room}`)
      .then(res => res.json())
      .then(data => {
        markReservedTimes(data, ".time-slot");
        if (window.IS_ADMIN === true || window.IS_ADMIN === "true") {
          setupAdminSlotClick(); // ✅ 클릭 이벤트 등록
        }
        markPastTableSlots(date, ".time-slot", { disableClick: false });

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

document.addEventListener("change", function (e) {
  if (e.target.classList.contains("closed-checkbox")) {
    const day = e.target.dataset.day;
    const openInput = document.querySelector(`.open-time[data-day="${day}"]`);
    const closeInput = document.querySelector(`.close-time[data-day="${day}"]`);

    const shouldDisable = e.target.checked;
    openInput.disabled = shouldDisable;
    closeInput.disabled = shouldDisable;
  }
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
    if (slot.dataset.clickBound) return; // ✅ 이미 바인딩된 슬롯은 스킵
    slot.dataset.clickBound = "1";       // ✅ 바인딩 표시

    slot.addEventListener('click', () => {
      const tooltip = slot.getAttribute('title') || '';
      const [name, phone, email] = tooltip.split('\n');

      document.getElementById('resvName').textContent = name || 'N/A';
      document.getElementById('resvPhone').textContent = phone || 'N/A';
      document.getElementById('resvEmail').textContent = email || 'N/A';

      const resvId = slot.dataset.resvId;
      const groupId = slot.dataset.groupId || "";  // ✅ 여기 추가
      const start = slot.dataset.start;
      const end = slot.dataset.end;
      const room = slot.dataset.room;

      const modalEl = document.getElementById('reservationDetailModal');
      modalEl.dataset.resvId = resvId;
      modalEl.dataset.groupId = groupId;     // ✅ 이 줄 추가
      modalEl.dataset.start = start;
      modalEl.dataset.end = end;
      modalEl.dataset.room = room;

      const modal = new bootstrap.Modal(modalEl);
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

document.getElementById("deleteReservationBtn").addEventListener("click", async () => {
  const modal = document.getElementById("reservationDetailModal");
  const id = modal.dataset.resvId;
  const groupId = modal.dataset.groupId; // ✅ 새로 추가된 groupId 사용

  if (!id && !groupId) {
    alert("Reservation ID or Group ID is missing!");
    return;
  }

  if (!confirm("Are you sure you want to delete this reservation?")) return;

  try {
    const res = await fetch(`/api/delete_reservation.php`, {
      method: "DELETE",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: groupId ? `Group_id=${groupId}` : `id=${id}`
    });

    const data = await res.json();

    if (data.success) {
      alert("Reservation deleted.");
      location.reload(); // ✅ 페이지 전체 새로고침
      const bsModal = bootstrap.Modal.getInstance(modal);
      if (bsModal) bsModal.hide();

      modal.dataset.resvId = "";
      modal.dataset.groupId = ""; // ✅ groupId 초기화
      modal.dataset.start = "";
      modal.dataset.end = "";
      modal.dataset.room = "";

      document.getElementById('resvName').textContent = "";
      document.getElementById('resvPhone').textContent = "";
      document.getElementById('resvEmail').textContent = "";

      clearAllTimeSlots();
      loadAllRoomReservations(els.datePicker.value);

    } else {
      alert("Failed to delete reservation.");
      console.warn("🛑 Server failed to delete reservation:", data);
    }

  } catch (err) {
    console.error("🔥 Error during deletion:", err);
    alert("Error occurred while deleting.");
  }
});


document.getElementById("editReservationBtn").addEventListener("click", async () => {
  isEditMode = true; // ✅ 수정 모드 진입
  const modal = document.getElementById("reservationDetailModal");
  const id = modal.dataset.resvId;


  try {
    const res = await fetch(`/api/get_single_reservation.php?id=${id}`);
    if (!res.ok) throw new Error("Fetch failed");
    const data = await res.json();

    // ✅ 날짜 반영 (form, date-picker, 텍스트)
    document.getElementById("GB_date").value = data.GB_date || '';
    document.getElementById("date-picker").value = data.GB_date || '';
    const formDateDisplay = document.getElementById("form-selected-date");
    if (formDateDisplay) formDateDisplay.textContent = data.GB_date;

    // ✅ 이름/이메일/전화
    document.getElementById("GB_id").value = data.GB_id;
    document.getElementById("name").value = data.GB_name || '';
    document.getElementById("email").value = data.GB_email || '';
    document.getElementById("phone").value = data.GB_phone || '';

    // ✅ 방 체크박스 처리
    els.roomCheckboxes.forEach(cb => {
      cb.checked = Array.isArray(data.GB_room_no) && data.GB_room_no.includes(cb.value);
      cb.dispatchEvent(new Event("change"));
    });

    // ✅ 시간 옵션 준비 후 값 설정
    await updateStartTimes(); // 옵션 채우기

    const startTimeValue = data.GB_start_time?.slice(0, 5);
    const endTimeValue = data.GB_end_time?.slice(0, 5);

    // ✅ fallback: 값이 없으면 option 직접 추가
    if (!els.startSelect.querySelector(`option[value="${startTimeValue}"]`)) {
      const opt = document.createElement("option");
      opt.value = startTimeValue;
      opt.textContent = startTimeValue;
      els.startSelect.appendChild(opt);
    }
    if (!els.endSelect.querySelector(`option[value="${endTimeValue}"]`)) {
      const opt = document.createElement("option");
      opt.value = endTimeValue;
      opt.textContent = endTimeValue;
      els.endSelect.appendChild(opt);
    }
    suppressChange = true;
    els.startSelect.value = data.GB_start_time?.slice(0, 5);
    els.endSelect.value = data.GB_end_time?.slice(0, 5);
    suppressChange = false;

  } catch (err) {
    console.error(err);
    alert("Failed to load reservation info.");
    return;
  }

  // ✅ 기존 모달 닫기
  const bsModal = bootstrap.Modal.getInstance(modal);
  if (bsModal) bsModal.hide();

  // ✅ 오프캔버스 강제 리셋 → 프리징 방지
  setTimeout(() => {
    const offcanvasEl = els.offcanvasEl;

    // 완전 초기화
    offcanvasEl.classList.remove("show");
    offcanvasEl.removeAttribute("aria-hidden");
    offcanvasEl.style.removeProperty("visibility");
    offcanvasEl.style.removeProperty("transform");

    document.querySelectorAll(".offcanvas-backdrop").forEach(el => el.remove());
    document.body.classList.remove("offcanvas-backdrop", "modal-open");
    document.body.style.removeProperty("overflow");

    // ✅ bootstrap 인스턴스 강제 제거 후 재생성
    bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl).hide();
    const instance = new bootstrap.Offcanvas(offcanvasEl);
    instance.show();
  }, 300);
});

// setInterval(() => {
//   location.reload();
// }, 2 * 60 * 1000); // ✅ 2분마다 새로고침
els.form.addEventListener("submit", async function (e) {
  e.preventDefault();

  if (!validDateForm()) return;

  if (isEditMode) {
    const formData = new FormData(els.form);
    const groupId = document.getElementById("reservationDetailModal")?.dataset.groupId;
    formData.append("Group_id", groupId);

    try {
      const res = await fetch("/api/update_reservation.php", {
        method: "POST",
        body: formData
      });

      const data = await res.json();

      if (data.success) {
        alert("Reservation updated!");
        window.isEditMode = false;

        const modal = document.getElementById("reservationDetailModal");
        const bsModal = bootstrap.Modal.getInstance(modal);
        if (bsModal) bsModal.hide();

        location.reload();
      } else {
        alert("Update failed.");
      }
    } catch (err) {
      alert("An error occurred.");
    }

    return;
  }
});


document.getElementById("saveBusinessHoursBtn")?.addEventListener("click", async function (e) {
  e.preventDefault();
  const form = document.getElementById("businessHoursForm");
  const formData = new FormData(form);

  try {
    const res = await fetch("/api/save_business_hours.php", {
      method: "POST",
      body: formData
    });

    const data = await res.json();

    if (data.success) {
      alert("Business hours saved!");
    } else {
      alert("Saving failed: " + (data.error || ''));
    }
  } catch (err) {
    console.error("Saving error:", err);
    alert("Error occurred while saving.");
  }
});