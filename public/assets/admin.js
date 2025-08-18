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
    fetch(`${API_BASE}/get_reserved_info.php?date=${date}&room=${room}`)
      .then(res => res.json())
      .then(data => {
        markReservedTimes(data, ".time-slot");
        if (window.IS_ADMIN === true || window.IS_ADMIN === "true") {
          setupAdminSlotClick(); // ✅ 클릭 이벤트 등록
        }
        markPastTableSlots(date, ".time-slot", { disableClick: true });

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
  const ymd = toYMD(selectedDate);
  window.location.href = `?date=${ymd}`;
  updateDateInputs(selectedDate, flatpickrInstance);
  clearAllTimeSlots();
  loadAllRoomReservations(toYMD(selectedDate));
  markPastTableSlots(toYMD(selectedDate), ".time-slot", { disableClick: true });
  updateStartTimes();
});

// ✅ handlers 주입 필수
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

markPastTableSlots(els.datePicker.value, ".time-slot", { disableClick: true });

handleReservationSubmit(els, { requireOTP: false });

prevBtn.addEventListener("click", () => {
    const date = new Date(els.datePicker.value);
    date.setDate(date.getDate() - 1);
    const newDateStr = toYMD(date);
    window.location.href = `admin.php?date=${newDateStr}`;
});

nextBtn.addEventListener("click", () => {
  const date = new Date(els.datePicker.value);
  date.setDate(date.getDate() + 1);
  const newDateStr = toYMD(date);
  window.location.href = `admin.php?date=${newDateStr}`;
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

    fetch(`${ROOT}/includes/upload_price_table.php`, {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
;
            const img = document.getElementById('priceTableImg');
            if (img) {
                img.src = `${ROOT}/images/price_table.png?t=` + new Date().getTime();
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
  document.getElementById('noticeEditorForm').classList.add('d-none');
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
    const res = await fetch(`${API_BASE}/delete_reservation.php`, {
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
    const res = await fetch(`${API_BASE}/get_single_reservation.php?id=${id}`);
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

  // ✅ 방 체크박스 처리 (문자열 비교 보장)
    const selectedRooms = Array.isArray(data.GB_room_no)
      ? data.GB_room_no.map(String)
      : [];

    els.roomCheckboxes.forEach(cb => {
      cb.checked = selectedRooms.includes(cb.value);
      if (cb.checked) cb.dispatchEvent(new Event("change"));
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

  // modal은 이미 위에서 가져온 그 변수
  const gid = modal.dataset.groupId || "";
  document.getElementById("Group_id").value = gid;   // ✅ 폼에 고정 저장
  els.form.dataset.groupId = gid;                     // (참고용)

  // ✅ 버튼 토글 (Reserve → Update)
  document.getElementById('reserveBtn')?.classList.add('d-none');
  document.getElementById('updateBtn')?.classList.remove('d-none');
  els.form.dataset.mode = 'edit'; // 모드 표시 (가드용)

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

 let __reloadTimer = setInterval(() => location.reload(), 2 * 60 * 1000);
 function pauseAutoReload() {
   if (__reloadTimer) { clearInterval(__reloadTimer); __reloadTimer = null; }
 }
 function resumeAutoReload() {
   if (!__reloadTimer) { __reloadTimer = setInterval(() => location.reload(), 2 * 60 * 1000); }
 }

document.getElementById("saveWeeklyBtn").addEventListener("click", async () => {
  const weekdays = ["mon", "tue", "wed", "thu", "fri", "sat", "sun"];
  const formData = new FormData();
  formData.append("action", "weekly");

  weekdays.forEach(day => {
    const openInput = document.getElementById(`${day}_open`);
    const closeInput = document.getElementById(`${day}_close`);
    const closedCheckbox = document.getElementById(`${day}_closed`);

    const open = openInput?.value || '';
    const close = closeInput?.value || '';
    const isClosed = closedCheckbox?.checked ? 1 : 0;

    formData.append(`${day}_open`, open);
    formData.append(`${day}_close`, close);
    formData.append(`${day}_closed`, isClosed);
  });
  console.log("🟢 FormData Preview:");
  for (const [key, value] of formData.entries()) {
    console.log(`${key}: ${value}`);
  }
  try {
    const res = await fetch(`${API_BASE}/save_business_hours.php`, {
      method: "POST",
      body: formData,
    });
    const data = await res.json();
    if (data.success) {
      alert("Weekly business hours saved successfully.");
    } else {
      alert("Failed to save weekly hours: " + (data.message || "Unknown error."));
    }
  } catch (err) {
    alert("Error saving weekly hours.");
  }
});

document.getElementById("saveSpecialBtn").addEventListener("click", async () => {
  const date = document.getElementById("special_date")?.value;
  const open = document.getElementById("special_open")?.value;
  const close = document.getElementById("special_close")?.value;

  if (!date || !open || !close) {
    alert("Please fill in all fields for the special date.");
    return;
  }

  const formData = new FormData();
  formData.append("action", "special");
  formData.append("date", date);
  formData.append("open_time", open);
  formData.append("close_time", close);

  try {
    const res = await fetch(`${API_BASE}/save_business_special_hours.php`, {
      method: "POST",
      body: formData,
    });
    const data = await res.json();
    if (data.success) {
      alert("Special business hours saved successfully.");
    } else {
      alert("Failed to save special hours: " + (data.message || "Unknown error."));
    }
  } catch (err) {
    alert("Error saving special hours.");
  }
});

function showNoticeEditor() {
  document.getElementById("adminMainList")?.classList.add("d-none");
  document.getElementById("businessHoursForm")?.classList.add("d-none");
  document.getElementById("noticeEditorForm")?.classList.remove("d-none");

  // Quill 초기화 1회만
  if (!window.quill) {
    window.quill = new Quill('#editor-container', {
      theme: 'snow',
      modules: {
        toolbar: [
          [{ 'size': ['small', false, 'large', 'huge'] }],  // ✅ 글씨 크기
          ['bold', 'italic', 'underline'], // 굵게, 기울임, 밑줄
          [{ 'color': ['#000000', '#e60000', '#0000ff', '#ffff00', '#00ff00'] }],
          [{ 'background': ['#ffff00', '#ff0000', '#00ff00', '#00ffff', '#ffffff'] }], // ✅ 하이라이트 색
          [{ 'align': [] }], // 정렬: left, center, right, justify
          [{ 'list': 'ordered' }, { 'list': 'bullet' }], // 번호/불릿 리스트
        ]
      }
    });
// ✅ 매번 최신 파일을 다시 가져오고, 캐시를 사용하지 않도록
  const url = `${ROOT}/data/notice.html?t=${Date.now()}`;
  fetch(url, { cache: 'no-store' })
    .then(res => res.text())
    .then(html => {
      window.quill.root.innerHTML = html;
    })
    .catch(err => {
      console.error("공지사항 로드 실패:", err);
    });
  }
};


document.getElementById("noticeEditorForm").addEventListener("submit", async function (e) {
  e.preventDefault();

  const html = window.quill.root.innerHTML;

  try {
    const res = await fetch(`${API_BASE}/save_notice.php`, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body: "html=" + encodeURIComponent(html)
    });

    const text = await res.text();

    if (res.ok) {
      alert("공지사항이 저장되었습니다!");
      window.quill.setContents([]);
      const canvas = bootstrap.Offcanvas.getInstance(document.getElementById('adminSettings'));
      if (canvas) canvas.hide();
      location.reload();
    } else {
      alert("❌ 저장 실패: " + text);
    }
  } catch (err) {
    alert("⚠️ 네트워크 오류: " + err.message);
  }
});

async function loadWeeklyBusinessHours() {
  try {
    const res = await fetch(`${API_BASE}/get_business_hours_all.php`);
    const hours = await res.json();

    hours.forEach(entry => {
      const { weekday, open_time, close_time, is_closed } = entry;

      const openEl = document.querySelector(`[name="${weekday}_open"]`);
      const closeEl = document.querySelector(`[name="${weekday}_close"]`);
      const closedEl = document.querySelector(`[name="${weekday}_closed"]`);

      if (openEl) openEl.value = open_time;
      if (closeEl) closeEl.value = close_time;

      if (closedEl) {
        closedEl.checked = is_closed == 1;

        // ✅ checked 반영 후 disable 처리까지 함께
        const isClosed = is_closed == 1;
        openEl.disabled = isClosed;
        closeEl.disabled = isClosed;

        if (isClosed) {
          openEl.value = "00:00";
          closeEl.value = "00:00";
        }
      }
    });
  } catch (err) {
    console.error("비즈니스 아워 불러오기 실패", err);
  }
}

// 페이지 로드 시 실행
loadWeeklyBusinessHours();

async function searchCustomer() {
  const name = document.getElementById("searchName").value.trim();
  const phone = document.getElementById("searchPhone").value.trim();
  const email = document.getElementById("searchEmail").value.trim();

  if (!name && !phone && !email) {
    alert("Please enter at least one of name, phone, or email.");
    return;
  }

  const params = new URLSearchParams();
  if (name) params.append("name", name);
  if (phone) params.append("phone", phone);
  if (email) params.append("email", email);

  try {
    const res = await fetch(`${API_BASE}/search_customer.php?${params.toString()}`);
    const data = await res.json();

    // inside searchCustomer() after fetching `data`
    const tbody = document.querySelector("#customerResultTable tbody");
    tbody.innerHTML = "";

    if (data.length === 0) {
      const tr = document.createElement("tr");
      const td = document.createElement("td");
      td.colSpan = 6; // 🔼 컬럼 수: 이름/폰/이메일/방문횟수/이용시간/메모 = 6
      td.textContent = "No results found.";
      tr.appendChild(td);
      tbody.appendChild(tr);
      return;
    }

    data.forEach(item => {
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td>${item.name ?? ""}</td>
        <td>${item.phone ?? ""}</td>
        <td>${item.email ?? ""}</td>
        <td>${item.visit_count ?? 0}</td>
        <td>${formatMinutes(item.total_minutes)}</td>
        <td>
          <div class="memo-cell">
                <div class="memo-text">${(item.memo ?? '').replace(/</g,'&lt;')}</div>
                <button class="btn btn-sm btn-outline-primary memo-btn"
                        data-name="${item.name ?? ""}"
                        data-phone="${item.phone ?? ""}"
                        data-email="${item.email ?? ""}">
                  Edit
                </button>
            </div>
        </td>
      `;
      tbody.appendChild(tr);
    });
     // 렌더 후 버튼 클릭 바인딩
    tbody.querySelectorAll('.memo-btn').forEach(btn => {
        btn.addEventListener('click', () => openMemoModal(
        btn.dataset.name, btn.dataset.phone, btn.dataset.email
      ));
    });
  } catch (err) {
    console.error("Search failed:", err);
    alert("An error occurred during search.");
  }
}



function openCustomerSearchModal() {
  // 오프캔버스 닫기
  const offcanvasEl = document.querySelector(".offcanvas.show");
  if (offcanvasEl) {
    const instance = bootstrap.Offcanvas.getInstance(offcanvasEl);
    if (instance) instance.hide();
  }

  // 🔧 모달 열기 전에 입력/결과 리셋
  const nameEl  = document.getElementById("searchName");
  const phoneEl = document.getElementById("searchPhone");
  const emailEl = document.getElementById("searchEmail");
  if (nameEl)  nameEl.value  = "";
  if (phoneEl) phoneEl.value = "";
  if (emailEl) emailEl.value = "";
  const tbody = document.querySelector("#customerResultTable tbody");
  if (tbody) tbody.innerHTML = "";

  // 모달 열기
  const modalEl = document.getElementById("customerSearchModal");
  const modal = new bootstrap.Modal((modalEl), {
    backdrop: true,
    keyboard: true
  });
  modal.show();
}

// 고객 검색 input에서 Enter 누를 시 검색 실행
document.querySelectorAll('#searchName, #searchPhone, #searchEmail').forEach(input => {
  input.addEventListener('keypress', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault(); // 기본 form 제출 막기
      searchCustomer();   // 검색 함수 호출
    }
  });
});

document.addEventListener("DOMContentLoaded", () => {
  // 기존 이벤트 설정은 유지
  document.querySelectorAll('input[type="time"]').forEach(input => {
    input.addEventListener('change', function () {
      const [hour] = this.value.split(":");
      this.value = `${hour.padStart(2, "0")}:00`;
    });
  });

document.querySelectorAll('.closed-checkbox').forEach(checkbox => {
    const day = checkbox.id.replace('_closed', '');
    const openInput = document.getElementById(`${day}_open`);
    const closeInput = document.getElementById(`${day}_close`);

    const updateDisabledState = () => {
      const isChecked = checkbox.checked;
      openInput.disabled = isChecked;
      closeInput.disabled = isChecked;

      if (isChecked) {
        openInput.value = "00:00";
        closeInput.value = "00:00";
      }
    };

    // ✅ 페이지 로드 시 초기화
    updateDisabledState();

    // ✅ 체크박스 변경 시에도 처리
    checkbox.addEventListener("change", updateDisabledState);
  });
});

function formatMinutes(mins) {
  mins = Number(mins || 0);
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  if (h > 0 && m > 0) return `${h}h ${m}m`;
  if (h > 0) return `${h}h`;
  return `${m}m`;
}

// Admin 전용 폼 리셋
function resetAdminForm() {
  if (!els.form) return;

  // 기본 필드
  els.form.reset();

  // 날짜는 달력값으로 맞추기
  const ymd = els.datePicker?.value || toYMD(new Date());
  if (els.bookingDateInput) els.bookingDateInput.value = ymd;
  if (els.formDateDisplay)  els.formDateDisplay.textContent = ymd;

  // 룸/시간 초기화
  els.roomCheckboxes?.forEach(cb => (cb.checked = false));
  if (els.endSelect) {
    els.endSelect.innerHTML = '<option disabled selected>Select a start time first</option>';
  }

  // 유효성 표시 제거
  els.form.querySelectorAll(".is-invalid, .is-valid").forEach(el => {
    el.classList.remove("is-invalid", "is-valid");
  });

  // 버튼/모드 원복
  document.getElementById('reserveBtn')?.classList.remove('d-none');
  const u = document.getElementById('updateBtn');
  if (u) u.classList.add('d-none');

  els.form.dataset.mode = '';
  window.isEditMode = false;

  // 예전 예약 식별자 제거(혹시 남아있을 수 있음)
  const detail = document.getElementById('reservationDetailModal');
  if (detail) {
    detail.dataset.resvId = '';
    detail.dataset.groupId = '';
    detail.dataset.start = '';
    detail.dataset.end = '';
    detail.dataset.room = '';
  }
  const g = document.getElementById("Group_id"); if (g) g.value = "";

}
els.offcanvasEl?.addEventListener("hidden.bs.offcanvas", resetAdminForm);

// Reserve 버튼: 신규만 제출(share.js의 submit 핸들러를 호출)
document.getElementById('reserveBtn')?.addEventListener('click', () => {
  if (els.form.dataset.mode === 'edit') return; // 편집 중엔 막기
  els.form.requestSubmit(); // -> share.js의 handleReservationSubmit로 흐름 전달
});

// Update 버튼: 편집일 때만 동작 (기존 update submit 로직 그대로 이식)
document.getElementById('updateBtn')?.addEventListener('click', async (e) => {
  e.preventDefault();              // ✅ 폼 submit 기본 동작 취소
  e.stopImmediatePropagation();    // ✅ 다른 submit 리스너들로 전파 차단

  if (els.form.dataset.mode !== 'edit') return;
  if (!validDateForm()) return;

  const formData = new FormData(els.form);
  // ✅ hidden/폼/dataset 순으로 안전하게 가져와서 세팅
  const gid = document.getElementById("Group_id")?.value 
          || els.form.dataset.groupId 
          || document.getElementById("reservationDetailModal")?.dataset.groupId 
          || "";

  formData.set("Group_id", gid);

  const groupId = document.getElementById("reservationDetailModal")?.dataset.groupId;
  if (groupId) formData.set("Group_id", groupId);

  try {
    const res = await fetch(`${API_BASE}/update_reservation.php`, { method: "POST", body: formData });
    const data = await res.json();
    if (data.success) {
      alert("Reservation updated!");
      bootstrap.Offcanvas.getInstance(els.offcanvasEl)?.hide();
      resetAdminForm();
      location.reload();
    } else {
      alert("Update failed.");
    }
  } catch (err) {
    alert("An error occurred.");
  }
});

async function openMemoModal(name, phone, email) {
  // 누구 메모인지 표시 + hidden 키 저장
  document.getElementById('memoWho').textContent = `${name} · ${phone} · ${email}`;
  document.getElementById('memoName').value  = name;
  document.getElementById('memoPhone').value = phone;
  document.getElementById('memoEmail').value = email;
  document.getElementById('memoText').value  = ''; // 기본 초기화

  // 기존 메모 불러오기
  try {
    const q = new URLSearchParams({ name, phone, email });
    const res = await fetch(`${API_BASE}/get_customer_note.php?${q.toString()}`);
    const j = await res.json();
    document.getElementById('memoText').value = j.note ?? '';
  } catch (e) {
    console.warn('memo load failed', e);
  }

  new bootstrap.Modal(document.getElementById('memoModal')).show();
}

document.getElementById('saveMemoBtn')?.addEventListener('click', async () => {
  const name  = document.getElementById('memoName').value.trim();
  const phone = document.getElementById('memoPhone').value.trim();
  const email = document.getElementById('memoEmail').value.trim();
  const note  = document.getElementById('memoText').value;

  if (!name || !phone || !email) {
    alert('Invalid customer key.'); 
    return;
  }

  const btn = document.getElementById('saveMemoBtn');
  btn.disabled = true;

  try {
    const res = await fetch(`${API_BASE}/save_customer_note.php`, {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ name, phone, email, note })
    });
    const j = await res.json();
    if (j.success) {
      alert('Saved!');
      bootstrap.Modal.getInstance(document.getElementById('memoModal'))?.hide();
      await searchCustomer(); // 입력값 그대로 다시 조회 → 표 리프레시
    } else {
      alert(j.message || 'Save failed.');
    }
  } catch (e) {
    alert('Network error.');
  } finally {
    btn.disabled = false;
  }
});

// ===== Step 1: 드래그 '시작'만 (Alt+클릭으로 시작) =====
if ((window.IS_ADMIN === true || window.IS_ADMIN === "true") && !window.__dragStartBound) {
  window.__dragStartBound = true;
  document.addEventListener('mousedown', onAdminDragStart, true);
}

const dragState = {
  active: false,
  id: null,
  groupId: '',
  rooms: [],
  slots: 0,
  fromStart: null
};

// 그룹/단일 메타 만들기
function collectGroupMeta(resvId, groupId) {
  if (!groupId) {
    // 단일 예약: 같은 GB_id로 이어진 칸들
    const cells = [...document.querySelectorAll(`.time-slot.bg-danger[data-resv-id="${resvId}"]`)];
    const times = cells.map(td => td.dataset.time).sort();
    return {
      rooms: [cells[0]?.dataset.room],
      start: times[0],
      slots: cells.length
    };
  }
  // 그룹 예약: 같은 Group_id 전부
  const cells = [...document.querySelectorAll(`.time-slot.bg-danger[data-group-id="${groupId}"]`)];
  const rooms = [...new Set(cells.map(td => td.dataset.room))].sort((a,b)=>Number(a)-Number(b));
  const times = cells.map(td => td.dataset.time).sort();
  const perRoom = Math.round(cells.length / rooms.length); // 방당 30분 슬롯 수
  return { rooms, start: times[0], slots: perRoom };
}

// 선택 표시/해제
function markDragOrigin(resvId, groupId) {
  const selector = groupId
    ? `.time-slot.bg-danger[data-group-id="${groupId}"]`
    : `.time-slot.bg-danger[data-resv-id="${resvId}"]`;
  document.querySelectorAll(selector).forEach(td => td.classList.add('drag-origin'));
}
function clearDragOrigin() {
  dragState.active = false;
  document.querySelectorAll('.time-slot.drag-origin').forEach(td => td.classList.remove('drag-origin'));
  setTimeout(() => { suppressClick = false; }, 0);  // ✅ click 허용 복귀
}

// Alt + 예약칸 클릭으로 시작
function onAdminDragStart(e) {
  if (!e.altKey) return; // 임시 가드: Alt 누를 때만
  const slot = e.target.closest('.time-slot.bg-danger');
  if (!slot) return;

  // 모달 열기와 충돌 방지
  e.preventDefault();
  e.stopPropagation();
  suppressClick = true;          // ✅ click 막기 시작
  pauseAutoReload();

  const id = slot.dataset.resvId;
  const groupId = slot.dataset.groupId || '';

  const meta = collectGroupMeta(id, groupId);
  if (!meta.rooms?.length || !meta.slots) return;

  dragState.active = true;
  dragState.id = id;
  dragState.groupId = groupId;
  dragState.rooms = meta.rooms;
  dragState.slots = meta.slots;
  dragState.fromStart = meta.start;

  // 시각 확인 + 로그
  markDragOrigin(id, groupId);


}

let suppressClick = false;

// 클릭 차단(캡처 단계) — 드래그 중이거나 suppressClick이면 모달 오픈 막기
document.addEventListener('click', function blockClickDuringDrag(e) {
  if (!dragState.active && !suppressClick) return;
  if (e.target.closest('.time-slot.bg-danger')) {
    e.stopImmediatePropagation();
    e.preventDefault();
  }
}, true); // ← 캡처 단계!

// ===== Step 2: 드래그 중 미리보기(방 세트 평행 이동) =====
document.addEventListener('mousemove', onAdminDragMove, true);

function clearMovePreview() {
  document.querySelectorAll('.time-slot.drag-preview, .time-slot.drag-invalid')
    .forEach(td => td.classList.remove('drag-preview','drag-invalid'));
}

function paintPreview(rooms, start, ok) {
  const cls = ok ? 'drag-preview' : 'drag-invalid';
  for (const room of rooms) {
    let cur = start;
    for (let i = 0; i < dragState.slots; i++) {
      const td = document.querySelector(`.time-slot[data-time="${cur}"][data-room="${room}"]`);
      if (td) td.classList.add(cls);
      cur = add30Minutes(cur); // share.js에 이미 있음
    }
  }
}

function onAdminDragMove(e) {
  if (!dragState.active) return;

  const over = e.target.closest('.time-slot');
  clearMovePreview();
  if (!over) return;

  const dropStart = over.dataset.time;
  const dropRoom  = Number(over.dataset.room);
  if (!dropStart || !dropRoom) return;

  // 원본 세트 → 드롭한 방을 새 "첫 방"으로 평행 이동
  const baseRooms   = dragState.rooms.map(n => Number(n)).sort((a,b)=>a-b);
  const baseFirst   = baseRooms[0];
  const delta       = dropRoom - baseFirst;
  const targetRooms = baseRooms.map(r => r + delta);

  // 방 범위 체크(예: 1~5)
  const minRoom = Math.min(...allRoomNumbers);
  const maxRoom = Math.max(...allRoomNumbers);
  if (targetRooms.some(r => r < minRoom || r > maxRoom)) {
    paintPreview(targetRooms, dropStart, false);
    dragState.validPreview = false;
    dragState.preview = null;
    return;
  }

  // 겹침 체크: 내 그룹/내 예약과 겹치는 건 허용, 다른 예약과 겹치면 불가
  let valid = true;
  for (const room of targetRooms) {
    let cur = dropStart;
    for (let i = 0; i < dragState.slots; i++) {
      const td = document.querySelector(`.time-slot[data-time="${cur}"][data-room="${room}"]`);
      if (!td) { valid = false; break; }

      if (td.classList.contains('bg-danger')) {
        if (dragState.groupId) {
          if (td.dataset.groupId !== dragState.groupId) { valid = false; break; }
        } else {
          if (td.dataset.resvId !== dragState.id) { valid = false; break; }
        }
      }
      cur = add30Minutes(cur);
    }
    if (!valid) break;
  }

  paintPreview(targetRooms, dropStart, valid);
  dragState.validPreview = valid;
  dragState.preview = valid ? { targetRooms, dropStart, delta } : null;
}

// ===== Step 3: 드롭하면 서버로 저장 요청 =====
document.addEventListener('mouseup', onAdminDrop, true);

async function onAdminDrop(e) {
  if (!dragState.active) return;

  // 드래그 종료 전 미리보기/선택 표시 제거
  const ymd = document.getElementById('date-picker').value;
  clearMovePreview();

  // 유효하지 않은 위치면 아무 것도 안 함
  if (!dragState.validPreview || !dragState.preview) {
    clearDragOrigin(); // ← Step1에서 만든 거 (점선 표시 제거 + suppressClick 해제)
    return;
  }

  const { targetRooms, dropStart } = dragState.preview;
  // “새 첫 방”은 평행 이동된 방 세트의 최솟값
  const newFirstRoom = Math.min(...targetRooms.map(Number));

  // 끝시간 계산
  let end = dropStart;
  for (let i = 0; i < dragState.slots; i++) end = add30Minutes(end);

  try {
    const body = new URLSearchParams({
      date: ymd,
      start_time: dropStart,
      end_time: end,
      first_room: String(newFirstRoom)   // ← 서버가 delta 계산할 때 사용할 값
    });
    if (dragState.groupId) {
      body.append('Group_id', dragState.groupId);  // 서버가 대문자만 읽는 경우 대비
      body.append('group_id', dragState.groupId);  // 서버가 소문자만 읽는 경우 대비
    } else {
      body.append('id', dragState.id);
      body.append('GB_id', dragState.id);          // 혹시 GB_id로 읽는 서버 대비
    }

    const res = await fetch(`${API_BASE}/move_reservation.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    });

    const j = await res.json();

    if (res.status === 409) {
      alert(j.message || 'Selected slot conflicts with another reservation.');
      clearDragOrigin();
      return;
    }
    if (!res.ok || !j.success) {
      alert(j.message || 'Move failed. Please try again.');
      console.warn('move_reservation payload:', body.toString());

      clearDragOrigin();
      return;
    }

    // 성공: 화면 새로 칠하기
    clearAllTimeSlots();
    loadAllRoomReservations(ymd);
    setTimeout(() => markPastTableSlots(ymd, '.time-slot', { disableClick: true }), 50);
    // 알림
    alert('Reservation moved!');
  } catch (err) {
    console.error(err);
    alert('Error while moving.');
  } finally {
    resumeAutoReload();
    clearDragOrigin(); // 점선/플래그 정리
  }
}

// ===== Weekly Overview (clean, DB-only axis) ==================================

// State: weekStart is Sunday (YYYY-MM-DD)
let weeklyState = { weekStart: null };

/** Open the weekly modal and render */
function openWeeklyOverviewModal() {
  const base = document.getElementById('date-picker')?.value || toYMDLocal(new Date());
  weeklyState.weekStart = getSunday(base);
  renderWeeklyGrid();

  const modalEl = document.getElementById('weeklyOverviewModal');
  if (modalEl) new bootstrap.Modal(modalEl).show();
}

/* ---------- Date helpers (local) ---------- */
function parseYMDLocal(ymd) {
  const [y, m, d] = ymd.split('-').map(Number);
  return new Date(y, m - 1, d);
}
function toYMDLocal(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}
function getSunday(ymd) {
  const d = parseYMDLocal(ymd);
  const dow = d.getDay(); // 0=Sun
  d.setDate(d.getDate() - dow);
  return toYMDLocal(d);
}
function getWeekDates(weekStartYMD) {
  const out = [];
  const start = parseYMDLocal(weekStartYMD);
  for (let i = 0; i < 7; i++) {
    const dd = new Date(start);
    dd.setDate(start.getDate() + i);
    out.push(toYMDLocal(dd)); // Sun..Sat
  }
  return out;
}

/* ---------- Time helpers ---------- */
function toMin(hhmm) { // 'HH:MM' -> minutes
  if (!hhmm) return null;
  const [h, m] = String(hhmm).slice(0,5).split(':').map(Number);
  return h * 60 + m;
}
function minToHH(m) {
  const h = Math.floor(m / 60);
  return String(h).padStart(2,'0') + ':00';
}

/* ---------- Week business hours (weekly + special merge) ---------- */
function normWeekdayKey(v) {
  if (v == null) return null;
  const s = String(v).trim().toLowerCase();
  if (/^\d+$/.test(s)) return ['sun','mon','tue','wed','thu','fri','sat'][parseInt(s,10)%7];
  const full = { sunday:'sun', monday:'mon', tuesday:'tue', wednesday:'wed', thursday:'thu', friday:'fri', saturday:'sat' };
  if (full[s]) return full[s];
  const abbr = s.slice(0,3);
  if (['sun','mon','tue','wed','thu','fri','sat'].includes(abbr)) return abbr;
  return null;
}

/** returns: { 'YYYY-MM-DD': { open_time:'HH:MM'|null, close_time:'HH:MM'|null, closed:boolean } } */
async function getWeekBusinessHours(weekStartYMD) {
  const ymds = getWeekDates(weekStartYMD); // Sun..Sat

  // 주간 기본 시간 (캐시 무력화)
  const weeklyArr = await fetch(
    `${API_BASE}/get_business_hours_all.php?t=${Date.now()}`,
    { cache: 'no-store' }
  ).then(r => r.json());

  // weekly -> map by sun..sat
  const weeklyMap = {};
  weeklyArr.forEach(w => {
    const key = normWeekdayKey(w.weekday);
    if (!key) return;
    const rawClosed = (w.is_closed !== undefined) ? w.is_closed : w.closed;
    const closed = rawClosed === true || rawClosed === 1 || rawClosed === '1' || String(rawClosed).toLowerCase() === 'true';
    weeklyMap[key] = {
      open_time: w.open_time ?? null,
      close_time: w.close_time ?? null,
      closed: !!closed
    };
  });

  // init per date from weekly
  const keys = ['sun','mon','tue','wed','thu','fri','sat'];
  const out = {};
  ymds.forEach((ymd, idx) => {
    const wk = weeklyMap[keys[idx]];
    if (!wk || wk.closed || !wk.open_time || !wk.close_time || String(wk.open_time).slice(0,5) === String(wk.close_time).slice(0,5)) {
      out[ymd] = { open_time: null, close_time: null, closed: true };
    } else {
      out[ymd] = {
        open_time: String(wk.open_time).slice(0,5),
        close_time: String(wk.close_time).slice(0,5),
        closed: false
      };
    }
  });

  // special override (per day)
  await Promise.all(ymds.map(async (ymd) => {
    try {
      const url = `${API_BASE}/get_business_hours.php?date=${encodeURIComponent(ymd)}&t=${Date.now()}`;
      let sp = await fetch(url, { cache: 'no-store' }).then(r => r.json());

      // 응답 포맷 방어 (data/result/배열 등)
      if (sp && typeof sp === 'object'
          && !('open_time' in sp) && !('close_time' in sp)
          && !('open' in sp) && !('close' in sp)
          && !('is_closed' in sp) && !('closed' in sp)) {
        sp = sp.data || sp.result || (Array.isArray(sp) ? sp[0] : sp);
      }
      if (!sp) return;

      const rawClosed = (sp.is_closed !== undefined) ? sp.is_closed : sp.closed;
      const closed = rawClosed === true || rawClosed === 1 || rawClosed === '1' || String(rawClosed).toLowerCase() === 'true';

      const openStr  = (sp.open_time ?? sp.open)  ? String(sp.open_time ?? sp.open).slice(0,5)   : null;
      const closeStr = (sp.close_time ?? sp.close) ? String(sp.close_time ?? sp.close).slice(0,5) : null;

      if (rawClosed !== undefined && closed === true) {
        // 스페셜이 '휴무'면 확실히 닫힘 처리
        out[ymd] = { open_time: null, close_time: null, closed: true };
        return;
      }

      if (openStr || closeStr || rawClosed !== undefined) {
        // 시간만 내려와도 열린 날로 본다
        out[ymd] = {
          open_time: openStr  ?? out[ymd].open_time,
          close_time: closeStr ?? out[ymd].close_time,
          closed: (rawClosed !== undefined) ? !!closed : false
        };
      }

      // 열고닫는 시간이 같으면 휴무로 간주
      const v = out[ymd];
      if (v.open_time && v.close_time && v.open_time === v.close_time) {
        out[ymd] = { open_time: null, close_time: null, closed: true };
      }
    } catch (e) {
      console.warn('special fetch failed for', ymd, e);
    }
  }));

  return out;
}


/* ---------- Build axis from DB (weekly min~max, 1h steps) ---------- */
function buildHourlyAxisFromBH(bhByDate) {
  let minOpen = Infinity, maxClose = -Infinity;
  for (const ymd in bhByDate) {
    const bh = bhByDate[ymd];
    if (!bh || bh.closed) continue;
    const o = toMin(bh.open_time);
    const c = closeToMinEnd(bh.close_time);
    if (o == null || c == null) continue;
    minOpen = Math.min(minOpen, o);
    maxClose = Math.max(maxClose, c);
  }
  if (!isFinite(minOpen) || !isFinite(maxClose) || minOpen >= maxClose) {
    return []; // all closed or invalid → empty axis (UI can show "Closed this week")
  }
  const out = [];
  for (let m = minOpen; m + 60 <= maxClose; m += 60) out.push(minToHH(m));
  return out; // ['09:00','10:00',...]
}

/* ---------- Render grid ---------- */
async function renderWeeklyGrid() {
  const grid = document.getElementById('weeklyGrid');
  if (!grid) return;

  const days = [];
  const start = parseYMDLocal(weeklyState.weekStart);
  for (let i = 0; i < 7; i++) {
    const dd = new Date(start);
    dd.setDate(start.getDate() + i);
    const ymd = toYMDLocal(dd);
    const label = dd.toLocaleDateString(undefined, { weekday: 'short' }) + ' ' + ymd.slice(5,10);
    days.push({ ymd, label });
  }

  const bhByDate = await getWeekBusinessHours(weeklyState.weekStart);
  const times = buildHourlyAxisFromBH(bhByDate);
  // ✅ 주간 예약 데이터 (start~end 한 번에)
  const resvData = await fetch(`${API_BASE}/get_weekly_reservations.php?start=${days[0].ymd}&end=${days[6].ymd}`)
    .then(r => r.json());

  const cells = [];
  // Header
  cells.push(`<div class="cell header">Time</div>`);
  for (const d of days) {
    cells.push(`<div class="cell header day-header text-center" data-date="${d.ymd}" role="button" tabindex="0">${d.label}</div>`);
  }

  // Body
  if (!times.length) {
    // All closed this week
    cells.push(`<div class="cell time text-center">—</div>`);
    for (let i = 0; i < 7; i++) {
      cells.push(`<div class="cell data closed-cell text-center">Closed</div>`);
    }
  } else {
    for (const t of times) {
      cells.push(`<div class="cell time text-center">${t}</div>`);
      for (const d of days) {
        const bh = bhByDate[d.ymd];
        let txt = '—';
        let cls = '';

        if (!bh || bh.closed) {
          txt = 'Closed';
          cls = 'closed-cell';
        } else {
          const OPEN = toMin(bh.open_time);
          const CLOSE = closeToMinEnd ? closeToMinEnd(bh.close_time) : toMin(bh.close_time); // 00:00=24:00 대응
            const m = toMin(t);
            if (m < OPEN || (m + 60) > CLOSE) {
              txt = '';
              cls = 'closed-cell';
            } else {
              // ✅ 영업시간 "안"이면 해당 시간의 예약 개수 표시
              const count = Number(resvData?.[d.ymd]?.[t] ?? 0);
              if (count > 0) {
                txt = `${count}/${allRoomNumbers.length}`;
                cls += (cls ? ' ' : '') + 'reserved-cell';
              }
            }
          }

        cells.push(`<div class="cell data ${cls}" data-date="${d.ymd}" data-time="${t}">${txt}</div>`);
      }
    }
  }

  grid.innerHTML = cells.join('');

  // Day header → go to daily view
  grid.querySelectorAll('.day-header').forEach(h => {
    const go = () => {
      const ymd = h.getAttribute('data-date');
      if (ymd) window.location.href = `admin.php?date=${ymd}`;
    };
    h.addEventListener('click', go);
    h.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); }
    });
  });

  // Range label
  const end = parseYMDLocal(weeklyState.weekStart);
  end.setDate(end.getDate() + 6);
  const labelEl = document.getElementById('weeklyRangeLabel');
  if (labelEl) labelEl.textContent = `${weeklyState.weekStart} ~ ${toYMDLocal(end)}`;
}

/* ---------- Week navigation ---------- */
document.getElementById('weeklyPrevBtn')?.addEventListener('click', (e) => {
  e.preventDefault();
  const d = parseYMDLocal(weeklyState.weekStart);
  d.setDate(d.getDate() - 7);
  weeklyState.weekStart = toYMDLocal(d);
  renderWeeklyGrid();
});
document.getElementById('weeklyNextBtn')?.addEventListener('click', (e) => {
  e.preventDefault();
  const d = parseYMDLocal(weeklyState.weekStart);
  d.setDate(d.getDate() + 7);
  weeklyState.weekStart = toYMDLocal(d);
  renderWeeklyGrid();
});

function closeToMinEnd(hhmm) {
  if (!hhmm) return null;
  const s = String(hhmm).slice(0,5);     // 'HH:MM'
  if (s === '24:00') return 1440;        // 명시적 24:00
  const m = toMin(s);                    // 00:00 -> 0
  // 닫는 시간이 00:00(12am)인 경우, "자정까지 영업"으로 간주 → 24:00
  return (m === 0 && s === '00:00') ? 1440 : m;
}