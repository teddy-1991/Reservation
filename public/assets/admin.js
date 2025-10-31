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

window.isEditMode = false;
// --- global guards (must be declared before any handlers) ---
if (typeof window.suppressClick === 'undefined') window.suppressClick = false;

function loadAllRoomReservations(date) {
  // 공용 로더만 호출 (이벤트는 문서 위임으로 항상 동작)
  return window.loadReservations(date, {
    rooms: allRoomNumbers,
    isAdmin: true
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

// // 한 번만 바인딩
// setupAdminDelegatedSlotClick();

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
    const [y, m, d] = els.datePicker.value.split("-").map(Number);
    const date = new Date(y, m - 1, d);
    date.setDate(date.getDate() - 1);
    const newDateStr = toYMD(date);
    window.location.href = `admin.php?date=${newDateStr}`;
});

nextBtn.addEventListener("click", () => {
  const [y, m, d] = els.datePicker.value.split("-").map(Number);
  const date = new Date(y, m - 1, d);
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

    fetch(`${API_BASE}/menu_price/upload_price_table.php`, {
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
  const groupId = modal.dataset.groupId;

  if (!id && !groupId) {
    alert("Reservation ID or Group ID is missing!");
    return;
  }
  if (!confirm("Are you sure you want to delete this reservation?")) return;

  try {
    const body = groupId
      ? `group_id=${encodeURIComponent(groupId)}`
      : `id=${encodeURIComponent(id)}`;

    const res = await fetch(`${API_BASE}/admin_reservation/delete_reservation.php`, {
      method: "DELETE",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body
    });
    const data = await res.json();

    // ✅ 서버 응답 규격에 맞춘 성공 판정
    const isSuccess = data.ok && ((data.deleted ?? 0) > 0 || (data.orphans_deleted ?? 0) > 0);

    if (isSuccess) {
      // 모달 닫고, 상태 정리
      const bsModal = bootstrap.Modal.getInstance(modal);
      if (bsModal) bsModal.hide();

      modal.dataset.resvId = "";
      modal.dataset.groupId = "";
      modal.dataset.start = "";
      modal.dataset.end = "";
      modal.dataset.room = "";

      document.getElementById('resvName').textContent = "";
      document.getElementById('resvPhone').textContent = "";
      document.getElementById('resvEmail').textContent = "";

      // 화면만 갱신해도 되면 이걸로 충분
      clearAllTimeSlots();
      await loadAllRoomReservations(els.datePicker.value);

      // 전체 새로고침이 꼭 필요하면 마지막에
      // location.reload();
      alert("Reservation deleted.");
    } else {
      alert("Failed to delete reservation.");
      console.warn("🛑 Delete failed (no rows affected):", data);
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
    const res = await fetch(`${API_BASE}/admin_reservation/get_single_reservation.php?id=${id}`);
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

    suppressChange = true;
    els.startSelect.value = startTimeValue;

    await rebuildEndOptions(startTimeValue, selectedRooms); 

    els.endSelect.value = endTimeValue;
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

  

  // ✅ 상세 모달을 정상적으로 닫고, 닫힌 뒤 오프캔버스를 연다
  const modalEl     = document.getElementById('reservationDetailModal');
  const offcanvasEl = els.offcanvasEl;
  await showOffcanvasAfterModalClose(modalEl, offcanvasEl);

});

// 전환 안전: 모달 닫힘 이벤트를 기다렸다가 오프캔버스 열기
async function showOffcanvasAfterModalClose(modalEl, offcanvasEl) {
  return new Promise((resolve) => {
    const md = bootstrap.Modal.getOrCreateInstance(modalEl);
    const oc = bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl);

    const open = () => {
      unlockPage(); // ★ 남은 백드롭/스크롤잠금 전부 해제
      oc.show();
      resolve();
    };

    const isShown = modalEl.classList.contains('show');
    if (isShown) {
      modalEl.addEventListener('hidden.bs.modal', open, { once: true });
      // 🔑 포커스가 모달 내부에 남아 있으면 aria-hidden 경고가 뜸 → 먼저 blur
      try { if (document.activeElement) document.activeElement.blur(); } catch {}
      md.hide(); // 여기서만 닫기 호출
    } else {
      open();    // 이미 닫혀 있으면 바로 오픈
    }
  });
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
    const res = await fetch(`${API_BASE}/business_hour/save_business_hours.php`, {
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
    const res = await fetch(`${API_BASE}/business_hour/save_business_special_hours.php`, {
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
    const res = await fetch(`${API_BASE}/info_note/save_notice.php`, {
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
      await refreshScreen({ reason: 'notice-saved' });
    } else {
      alert("❌ 저장 실패: " + text);
    }
  } catch (err) {
    alert("⚠️ 네트워크 오류: " + err.message);
  }
});

async function loadWeeklyBusinessHours() {
  try {
    const res = await fetch(`${API_BASE}/business_hour/get_business_hours_all.php`);
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
    const res = await fetch(`${API_BASE}/info_note/search_customer.php?${params.toString()}`);
    const data = await res.json();

    renderCustomerResults(data);
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
    const res = await fetch(`${API_BASE}/admin_reservation/update_reservation.php`, { method: "POST", body: formData });

    if (res.status === 409) {
      const j = await res.json();
      alert("⚠️ Time conflict with another reservation.");
      return;
    }

    const data = await res.json();
    if (data.success) {
      alert("Reservation updated!");
      bootstrap.Offcanvas.getInstance(els.offcanvasEl)?.hide();
      resetAdminForm();
      // ✅ 여기부터 추가 — 확인창 띄우고 재발송
    const { group_id, id, email } = data || {};
    const ok = confirm('Send update email to customer?');
    if (ok) {
      const params = new URLSearchParams();
      if (group_id) params.append('group_id', group_id);
      if (id)       params.append('id', id);
      if (email)    params.append('email', email);
      params.append('reason', 'updated'); // 제목/내용 분기용 플래그

      // (선택) 자동 새로고침 일시정지
      if (typeof pauseAutoReload === 'function') pauseAutoReload();

      try {
        const r2  = await fetch(`${API_BASE}/admin_reservation/resend_reservation_email.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: params.toString()
        });
        const j2 = await r2.json();
        if (r2.ok && j2?.success) {
          alert('Email sent.');
        } else {
          alert((j2 && (j2.message || j2.error)) || 'Failed to send email.');
        }
      } catch {
        alert('Network error while sending email.');
      } finally {
        if (typeof resumeAutoReload === 'function') resumeAutoReload();
      }
    }
    await refreshScreen({ reason: 'reservation-updated' });
    } else {
      alert("Update failed.");
    }
  } catch (err) {
    alert("An error occurred.");
  }
});

// 메모 편집 모달 열기 (예약상세/고객검색 공용)
// opts.refreshAfterSave === false 이면 저장 후 searchCustomer()를 실행하지 않음
async function openMemoModal(name, phone, email, opts = { refreshAfterSave: true }) {
  const refreshAfterSave = opts.refreshAfterSave !== false; // 기본 true

  // 누구 메모인지 표시 + hidden 키 저장
  document.getElementById('memoWho').textContent = `${name} · ${phone} · ${email}`;
  document.getElementById('memoName').value  = (name  || '').trim();
  document.getElementById('memoPhone').value = (phone || '').trim();
  document.getElementById('memoEmail').value = (email || '').trim();
  document.getElementById('memoText').value  = ''; // 기본 초기화

  // 기존 메모 불러오기 (GET)
  try {
    const q = new URLSearchParams({
      name:  document.getElementById('memoName').value,
      phone: document.getElementById('memoPhone').value,
      email: document.getElementById('memoEmail').value.toLowerCase()
    });
    const res = await fetch(`${API_BASE}/info_note/get_customer_note.php?${q.toString()}`);
    const j = await res.json();
    document.getElementById('memoText').value = j.note ?? '';
  } catch (e) {
    console.warn('memo load failed', e);
  }

  // 모달 dataset에 플래그/키 저장
  const modalEl = document.getElementById('memoModal');
  modalEl.dataset.refreshAfterSave = refreshAfterSave ? '1' : '0';
  modalEl.dataset.keyName  = document.getElementById('memoName').value;
  modalEl.dataset.keyEmail = document.getElementById('memoEmail').value.toLowerCase();
  modalEl.dataset.keyPhone = document.getElementById('memoPhone').value.replace(/\D+/g, '');

  new bootstrap.Modal(modalEl).show();
}

document.getElementById('saveMemoBtn')?.addEventListener('click', async () => {
  const name  = (document.getElementById('memoName').value  || '').trim();
  const phone = (document.getElementById('memoPhone').value || '').trim();
  const email = (document.getElementById('memoEmail').value || '').trim();
  const note  = document.getElementById('memoText').value;

  if (!name || !phone || !email) {
    alert('Invalid customer key.');
    return;
  }

  const btn = document.getElementById('saveMemoBtn');
  btn.disabled = true;

  try {
    const res = await fetch(`${API_BASE}/info_note/save_customer_note.php`, {
      method: 'POST',
      headers: { 'Content-Type':'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ name, phone, email: email.toLowerCase(), note })
    });
    const j = await res.json();

  if (j.success) {
    alert('Saved!');

    const memoModalEl = document.getElementById('memoModal');

    const row = memoModalEl?.__rowEl;
    if (row) {
      const memoEl = row.querySelector('.memo-text');
      if (memoEl) memoEl.textContent = (note || '').trim() || '—';
      // (선택) 버튼 data도 동기화
      row.querySelector('.btn-edit')?.setAttribute('data-memo', note || '');
    }

  // 예약 상세 모달 열려있으면 그 텍스트만 반영 (현행 유지)
    try {
      const nName  = name;
      const nEmail = email.toLowerCase();
      const nPhone = phone.replace(/\D+/g, '');

      const rName  = (document.getElementById('resvName')?.textContent || '').trim();
      const rEmail = (document.getElementById('resvEmail')?.textContent || '').trim().toLowerCase();
      const rPhone = (document.getElementById('resvPhone')?.textContent || '').replace(/\D+/g, '');

      if (nName && nEmail && nPhone && nName === rName && nEmail === rEmail && nPhone === rPhone) {
        const noteBox = document.getElementById('customerNoteText');
        if (noteBox) noteBox.textContent = note || '—';
      }
    } catch (_) {}

    // // --- ✅ 고객 목록 테이블에서도 즉시 반영 (낙관적 업데이트) ---
    // try {
    //   const nEmail = email.toLowerCase();
    //   const nPhone = phone.replace(/\D+/g, '');
    //   document.querySelectorAll('#customerResultTable tbody tr').forEach(tr => {
    //     // 데이터 속성 우선, 없으면 셀 텍스트로 매칭
    //     const rowEmail = (tr.dataset.email || tr.querySelector('.email-cell')?.textContent || '').trim().toLowerCase();
    //     const rowPhone = (tr.dataset.phone || tr.querySelector('.phone-cell')?.textContent || '').replace(/\D+/g, '');
    //     if (rowEmail === nEmail && rowPhone === nPhone) {
    //       const memoEl = tr.querySelector('.memo-text');
    //       if (memoEl) memoEl.textContent = note?.trim() || '—';
    //     }
    //   });
    // } catch (_) { /* no-op */ }

    // --- 모달 닫기 ---
    bootstrap.Modal.getInstance(memoModalEl)?.hide();

    // --- ✅ 재조회: 필터 있으면 Search, 없으면 Show All ---
    const doRefresh = memoModalEl?.dataset.refreshAfterSave === '1';
    if (doRefresh) {
      const qName  = document.getElementById('searchName')?.value.trim() || '';
      const qPhone = document.getElementById('searchPhone')?.value.trim() || '';
      const qEmail = document.getElementById('searchEmail')?.value.trim() || '';

      if (qName || qPhone || qEmail) {
        // 검색 상태 유지
        if (typeof searchCustomer === 'function') {
          await searchCustomer();
        } else {
          document.getElementById('btnSearch')?.click();
        }
      } else {
        // Show All 뷰 유지
        if (typeof showAllCustomers === 'function') {
          await showAllCustomers();
        } else {
          document.getElementById('btnShowAll')?.click();
        }
      }
    }
  } else {
    alert(j.message || 'Save failed.');
  }

  } catch (e) {
    console.error(e);
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

// admin.js

function onAdminDragStart(e) {
  const slot = e.target.closest('.time-slot.bg-danger');
  if (!slot) return;

  const id = slot.dataset.resvId;
  const groupId = slot.dataset.groupId || '';
  const meta = collectGroupMeta(id, groupId);
  if (!meta?.rooms?.length || !meta.slots) return;

  const DRAG_THRESHOLD = 6;
  const startX = e.clientX;
  const startY = e.clientY;
  let started = false;

  const begin = () => {
    if (started) return;
    started = true;

    // ✅ 드래그 중 텍스트 하이라이트 방지
    document.body.classList.add('dragging-noselect');
    const sel = window.getSelection && window.getSelection();
    if (sel && sel.removeAllRanges) sel.removeAllRanges();

    // 클릭 차단 + 자동 새로고침 멈춤
    suppressClick = true;
    pauseAutoReload();

    dragState.active   = true;
    dragState.id       = id;
    dragState.groupId  = groupId;
    dragState.rooms    = meta.rooms;
    dragState.slots    = meta.slots;
    dragState.fromStart= meta.start;

    markDragOrigin(id, groupId);
  };

  const move = (mv) => {
    const dx = (mv.clientX ?? 0) - startX;
    const dy = (mv.clientY ?? 0) - startY;
    if (!started && (Math.abs(dx) > DRAG_THRESHOLD || Math.abs(dy) > DRAG_THRESHOLD)) {
      begin(); // 일정 픽셀 이상 움직이면 드래그 시작
    }
  };

  const cleanup = () => {
    window.removeEventListener('mousemove', move, true);
    document.body.classList.remove('dragging-noselect'); // ✅ 복구

    if (!started) {
      // 드래그 시작 안 했으면 클릭 막지 않음
      suppressClick = false;
    }
  };

  window.addEventListener('mousemove', move, true);
  window.addEventListener('mouseup', cleanup, { once: true, capture: true });
}

// 혹시 ESC나 창 포커스 잃었을 때 드래그 상태가 남을 경우 대비
function forceCancelDrag() {
  if (!dragState.active && !suppressClick) return;
  clearMovePreview();
  clearDragOrigin();   // 내부에서 suppressClick=false 해줌
  document.body.classList.remove('dragging-noselect'); // ✅ 복구
  resumeAutoReload();
}
window.addEventListener('blur',  forceCancelDrag, true);
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') forceCancelDrag();
}, true);

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

    const res = await fetch(`${API_BASE}/admin_reservation/move_reservation.php`, {
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

      // ✅ 추가: 고객 페이지에게 “새로고침해!” 시그널 보내기
    try {
          const bc = new BroadcastChannel('booking_sync');
          bc.postMessage({ type: 'move_done', ts: Date.now() });
        } catch (err) {
          console.warn('BroadcastChannel not available:', err);
        }
    
    const gid   = (j && j.group_id != null) ? j.group_id : (dragState.groupId ?? null);
    const rid   = gid ? null : ((j && j.id != null) ? j.id : (dragState.id ?? null));
    const email = (j && j.email) || dragState.email || dragState.GB_email || '';
    
    // ✅ 여기서 바로 확인창 → 재발송(fetch)
    const ok = confirm('Send update email to customer?');
    if (ok) {
      const params = new URLSearchParams();
      if (gid) params.append('group_id', gid);
      if (rid) params.append('id', rid);
      if (email) params.append('email', email);
      params.append('reason', 'moved'); // ← 드래그앤드롭 전용 플래그

      try {
        // (선택) 자동 새로고침 잠깐 멈춤
        if (typeof pauseAutoReload === 'function') pauseAutoReload();

        const r2 = await fetch(`${API_BASE}/admin_reservation/resend_reservation_email.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: params.toString()
        });
        const j2 = await r2.json();
        if (r2.ok && j2?.success) {
          alert('Email sent.');
        } else {
          alert((j2 && (j2.message || j2.error)) || 'Failed to send email.');
        }
      } catch (e) {
        alert('Network error while sending email.');
      } finally {
        if (typeof resumeAutoReload === 'function') resumeAutoReload();
      }
    }

    await refreshScreen({ reason: 'reservation-moved' });

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
  renderWeeklyCounts(weeklyState.weekStart);

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
function ymdPrevLocal(ymd) {
  const d = parseYMDLocal(ymd);
  d.setDate(d.getDate() - 1);
  return toYMDLocal(d);
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

// 주간 영업시간 병합: weekly 기본 + special override
// 반환: { 'YYYY-MM-DD': { open_time:'HH:MM'|null, close_time:'HH:MM'|null, closed:boolean } }
async function getWeekBusinessHours(weekStartYMD) {
  const ymds = getWeekDates(weekStartYMD); // Sun..Sat

  // 1) 주간 기본 시간 (캐시 무력화)
  const weeklyArr = await fetch(
    `${API_BASE}/business_hour/get_business_hours_all.php?t=${Date.now()}`,
    { cache: 'no-store' }
  ).then(r => r.json());

  // weekday 키 정규화
  const weeklyMap = {};
  const normWeekdayKey = (v) => {
    if (v == null) return null;
    const s = String(v).trim().toLowerCase();
    if (/^\d+$/.test(s)) return ['sun','mon','tue','wed','thu','fri','sat'][parseInt(s,10)%7];
    const full = { sunday:'sun', monday:'mon', tuesday:'tue', wednesday:'wed', thursday:'thu', friday:'fri', saturday:'sat' };
    if (full[s]) return full[s];
    const abbr = s.slice(0,3);
    if (['sun','mon','tue','wed','thu','fri','sat'].includes(abbr)) return abbr;
    return null;
  };

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

  // 2) weekly → 날짜별 초기값 채우기
  const keys = ['sun','mon','tue','wed','thu','fri','sat'];
  const out = {};
  ymds.forEach((ymd, idx) => {
    const wk = weeklyMap[keys[idx]];
    if (wk && !wk.closed && wk.open_time && wk.close_time && String(wk.open_time).slice(0,5) !== String(wk.close_time).slice(0,5)) {
      out[ymd] = {
        open_time: String(wk.open_time).slice(0,5),
        close_time: String(wk.close_time).slice(0,5),
        closed: false
      };
    } else {
      out[ymd] = { open_time: null, close_time: null, closed: true };
    }
  });

  // 3) special override (일자별)
  await Promise.all(ymds.map(async (ymd) => {
    try {
      const url = `${API_BASE}/business_hour/get_business_hours.php?date=${encodeURIComponent(ymd)}&t=${Date.now()}`;
      let sp = await fetch(url, { cache: 'no-store' }).then(r => r.json());

      // 응답 포맷 관용 처리(data/result/배열 등)
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
        out[ymd] = { open_time: null, close_time: null, closed: true };
        return;
      }

      if (openStr || closeStr || rawClosed !== undefined) {
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


function hhmmNoWrap(totalMin) {
  const h = Math.floor(totalMin / 60); // 랩 안 함
  const m = totalMin % 60;
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

/* ---------- Build axis from DB (weekly min~max, 1h steps) ---------- */
// 자정 넘김(익일 마감)까지 안전하게 계산되도록 보정
function buildHourlyAxisFromBH(bhByDate) {
  // close 보정: 마감이 오픈과 같거나 이전이면 익일로 판단(자정 넘김)
  const safeCloseToMinLocal = (closeHHMM, isClosed, openMin) => {
    if (!closeHHMM || isClosed) return openMin;
    const hhmm = String(closeHHMM).slice(0, 5);
    const toMinLocal = (s) => {
      const [h, m] = s.split(':').map(Number);
      return h * 60 + m;
    };
    let cm = toMinLocal(hhmm);
    if (cm <= (openMin ?? 0)) cm += 1440; // 익일 보정
    return cm;
  };

  let minOpen = Infinity;
  let maxClose = -Infinity;

  for (const ymd in bhByDate) {
    const bh = bhByDate[ymd];
    if (!bh || bh.closed) continue;

    const open = (function toMinSafe(v) {
      if (!v) return null;
      const s = String(v).slice(0, 5);
      const [h, m] = s.split(':').map(Number);
      return h * 60 + m;
    })(bh.open_time);

    const close = safeCloseToMinLocal(bh.close_time, bh.closed, open);

    if (open == null || close == null) continue;
    if (open >= close) continue; // 방어

    minOpen = Math.min(minOpen, open);
    maxClose = Math.max(maxClose, close);
  }

  // 전체가 휴무거나 유효 범위가 없으면 빈 축
  if (!isFinite(minOpen) || !isFinite(maxClose) || minOpen >= maxClose) {
    return [];
  }

  // 1시간 단위 라벨 생성 (예: ['09:00','10:00',...])
  const out = [];
  for (let m = minOpen; m <= maxClose; m += 60) {
    out.push(hhmmNoWrap(m));   // ⬅️ 여기! minToHH 대신 no-wrap 사용
  }
  return out;
}

/* ---------- Render grid (final, normalized by API) + DEBUG LOGS ---------- */
async function renderWeeklyGrid() {
  console.warn('[weekly] renderWeeklyGrid start', Date.now());

  const grid = document.getElementById('weeklyGrid');
  if (!grid) return;

  // ===== Debug collectors =====
  const __axis = [];
  const __spill = [];   // 자정넘김(24:00+) 분기 로그
  const __normal = [];  // 일반시간 분기 로그

  // 1) 주간 날짜 목록 (Sun..Sat)
  const days = [];
  const start = parseYMDLocal(weeklyState.weekStart);
  for (let i = 0; i < 7; i++) {
    const dd = new Date(start);
    dd.setDate(start.getDate() + i);
    const ymd = toYMDLocal(dd);
    const label = dd.toLocaleDateString(undefined, { weekday: 'short' }) + ' ' + ymd.slice(5, 10);
    days.push({ ymd, label });
  }

  // 2) 영업시간 병합 + 축 생성
  const bhByDate = await getWeekBusinessHours(weeklyState.weekStart);
  const times = buildHourlyAxisFromBH(bhByDate);

  __axis.push({ keys: times.join(', ') });
  console.groupCollapsed('[weekly] axis keys');
  console.log(times);
  console.groupEnd();

  // 3) 주간 예약 데이터
  const resvData = await fetch(
    `${API_BASE}/admin_reservation/get_weekly_reservations.php?start=${days[0].ymd}&end=${days[6].ymd}`,
    { cache: 'no-store' }
  ).then(r => r.json());

  console.log('[weekly] resvData raw', resvData);

  // 4) 그리드 렌더
  const cells = [];
  cells.push(`<div class="cell header">Time</div>`);
  for (const d of days) {
    cells.push(
      `<div class="cell header day-header text-center" data-date="${d.ymd}" role="button">${d.label}</div>`
    );
  }

  if (!times.length) {
    cells.push(`<div class="cell time text-center">—</div>`);
    for (let i = 0; i < 7; i++) {
      cells.push(`<div class="cell data closed-cell text-center">Closed</div>`);
    }
  } else {
    for (const t of times) {
      cells.push(`<div class="cell time text-center">${displayTimeLabel(t)}</div>`);

      for (const d of days) {
        const bh = bhByDate[d.ymd];
        let txt = '—';
        let cls = '';

        // --- 스필 행: 24:00, 25:00, ... 은 전부 "해당 날짜"의 값만 본다 ---
        const hour = parseInt(t.slice(0, 2), 10);
        if (Number.isFinite(hour) && hour >= 24) {
          const cnt = Number(resvData?.[d.ymd]?.[t] ?? 0); // ✅ prevYmd 보지 말고, 당일 키만
          const has = cnt > 0;
          const txt = has ? `${cnt}/${allRoomNumbers.length}` : '';
          const cls = has ? 'reserved-cell' : 'closed-cell';
          cells.push(`<div class="cell data ${cls}" data-date="${d.ymd}" data-time="${t}">${txt}</div>`);
        
          continue;
        }


        // --- 일반 시간대
        if (!bh || bh.closed) {
          txt = 'Closed';
          cls = 'closed-cell';

          // DEBUG
          __normal.push({
            mode: 'CLOSED',
            date: d.ymd, t,
            open: bh?.open_time ?? null,
            close: bh?.close_time ?? null,
            reason: 'bh.closed'
          });
        } else {
          const open = toMin(bh.open_time);
          const close = safeCloseToMin(bh.close_time, bh.closed, open);
          const m = toMin(t);

          const closeCap = Math.min(close, 1440);
          if (m < open || (m + 60) > closeCap) {
            txt = '';
            cls = 'closed-cell';

            // DEBUG
            __normal.push({
              mode: 'OUTSIDE',
              date: d.ymd, t,
              open: bh.open_time, close: bh.close_time,
              openMin: open, closeMin: close, closeCap, m,
              reason: (m < open) ? 'm<open' : '(m+60)>closeCap'
            });
          } else {
            const count = Number(resvData?.[d.ymd]?.[t] ?? 0);
            if (count > 0) {
              txt = `${count}/${allRoomNumbers.length}`;
              cls = 'reserved-cell';

              // DEBUG
              __normal.push({
                mode: 'HIT',
                date: d.ymd, t,
                count, open: bh.open_time, close: bh.close_time, m
              });
            } else {
              txt = '';
              cls = '';

              // DEBUG
              __normal.push({
                mode: 'EMPTY',
                date: d.ymd, t,
                open: bh.open_time, close: bh.close_time, m
              });
            }
          }
        }

        cells.push(
          `<div class="cell data ${cls}" data-date="${d.ymd}" data-time="${t}">${txt}</div>`
        );
      }
    }
  }

  grid.innerHTML = cells.join('');

  // 날짜 클릭 시 해당일로 이동
  grid.querySelectorAll('.day-header').forEach(h => {
    const go = () => {
      const ymd = h.getAttribute('data-date');
      if (ymd) window.location.href = `admin.php?date=${ymd}`;
    };
    h.addEventListener('click', go);
  });

  // 범위 라벨 업데이트
  const end = parseYMDLocal(weeklyState.weekStart);
  end.setDate(end.getDate() + 6);
  const labelEl = document.getElementById('weeklyRangeLabel');
  if (labelEl) labelEl.textContent = `${weeklyState.weekStart} ~ ${toYMDLocal(end)}`;

  // ======= DEBUG OUTPUTS =======
  console.table(__axis);
  console.table(__spill);   // 24:00+ 분기에서 무엇을 집계했는지
  console.table(__normal);  // 일반 시간대에서 왜 비었는지/잡혔는지
}


/* ---------- Week navigation ---------- */
document.getElementById('weeklyPrevBtn')?.addEventListener('click', (e) => {
  e.preventDefault();
  const d = parseYMDLocal(weeklyState.weekStart);
  d.setDate(d.getDate() - 7);
  weeklyState.weekStart = toYMDLocal(d);
  renderWeeklyGrid();
  renderWeeklyCounts(weeklyState.weekStart);
});
document.getElementById('weeklyNextBtn')?.addEventListener('click', (e) => {
  e.preventDefault();
  const d = parseYMDLocal(weeklyState.weekStart);
  d.setDate(d.getDate() + 7);
  weeklyState.weekStart = toYMDLocal(d);
  renderWeeklyGrid();
  renderWeeklyCounts(weeklyState.weekStart);
});

// Fetch distinct reservation count for a date.
// NOTE: If your API requires rooms, use: `?date=${ymd}&rooms=1,2,3,4,5`
async function fetchDailyReservationCount(ymd) {
  const res = await fetch(`${API_BASE}/admin_reservation/get_reserved_info.php?date=${encodeURIComponent(ymd)}`, { cache: "no-store" });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const rows = await res.json();

  // Count distinct Group ID (handles multi-room same reservation)
  const ids = new Set();
  for (const r of rows) {
    const gid = r.Group_id ?? r.group_id ?? r.groupId ?? r.group ?? r.id; // be tolerant
    if (gid) ids.add(String(gid));
    else {
      // fallback: if no group id, treat each row as one reservation
      ids.add(`row-${r.reservation_id ?? r.id ?? Math.random()}`);
    }
  }
  return ids.size;
}

async function renderWeeklyCounts(weekStartYMD) {
  const mount = document.getElementById("weekly-overview-counts");
  if (!mount) return;

  // Build week dates (Sun..Sat)
  const ymds = (() => {
    const arr = [];
    const s = parseYMDLocal(weekStartYMD);
    for (let i = 0; i < 7; i++) {
      const d = new Date(s);
      d.setDate(s.getDate() + i);
      arr.push(toYMDLocal(d));
    }
    return arr;
  })();

  async function getCount(ymd) {
    // 1) 방 번호 안전 추출(중복 제거, 숫자화, 정렬)
    const pickRooms = () => {
      const cand =
        window.allRoomNumbers || window.ALL_ROOM_NUMBERS || window.ALL_ROOMS || window.rooms;
      if (Array.isArray(cand) && cand.length) {
        return [...new Set(cand.map(n => Number(n)).filter(n => Number.isFinite(n)))].sort((a,b)=>a-b);
      }
      // ✅ 기본값 (운영 방 수에 맞춰 필요시 바꾸세요)
      return [1,2,3,4,5,6];
    };
    const rooms = pickRooms();
    const roomsParam = rooms.length ? `&rooms=${encodeURIComponent(rooms.join(","))}` : "";

    // 2) 요청
    const url = `${API_BASE}/admin_reservation/get_reserved_info.php?date=${encodeURIComponent(ymd)}${roomsParam}&t=${Date.now()}`;
    const res = await fetch(url, { cache: "no-store" });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);

    let json = await res.json();
    let rows = Array.isArray(json) ? json : (Array.isArray(json?.rows) ? json.rows : []);
    if (!Array.isArray(rows) || rows.length === 0) return 0;

    // ✅ 스필(자정 넘김) 행은 일일 합계에서 제외: time_key/hour >= 24면 스킵
    const isSpillRow = (r) => {
      const k = String(r.time_key ?? r.time ?? r.t ?? '');
      const m = k.match(/^(\d{2})/);
      if (!m) return false;
      const h = parseInt(m[1], 10);
      return Number.isFinite(h) && h >= 24;
    };

    // ✅ 고유 예약 키 만들기 (group_id 우선, 없으면 visit_key -> 합성키)
    const makeId = (r) => {
      // 1) 서버에서 주는 정식 키들 우선
      const gid = r.Group_id ?? r.group_id ?? r.groupId ?? r.group;
      if (gid != null && String(gid) !== '') return `gid:${gid}`;

      // 2) visit_key가 있으면 사용 (서버 SQL에서 쓰던 키)
      if (r.visit_key) return `vk:${r.visit_key}`;

      // 3) 최후의 합성키: 같은 예약이면 동일해야 할 필드들로 구성
      //    (GB_date는 시작일 기준이어야 중복 방지됨)
      const parts = [
        r.GB_date ?? r.date ?? ymd,               // 시작 날짜
        (r.GB_start_time ?? r.start_time ?? '').slice?.(0,5) || '',
        (r.GB_phone ?? r.phone ?? '').replace?.(/\D+/g, '') || '',
        String(r.GB_email ?? r.email ?? '').toLowerCase()
      ];
      return `fx:${parts.join('|')}`;
    };

    // ✅ 집계: 스필 행은 제외하고, 고유키로 Set 집계
    const ids = new Set();
    for (const r of rows) {
      if (isSpillRow(r)) continue;     // 자정 넘김 스필은 일일 합계에서 제외
      ids.add(makeId(r));
    }
    return ids.size;
  }

  mount.innerHTML = `<div class="small text-muted">Loading daily totals…</div>`;

  const counts = await Promise.all(
    ymds.map(async (d) => {
      try { return await getCount(d); }
      catch { return "—"; }
    })
  );

  const headers = ymds.map((ymd) => {
    const dd = parseYMDLocal(ymd);
    const wd = dd.toLocaleDateString(undefined, { weekday: "short" });
    const md = ymd.slice(5);
    return `<th scope="col" class="text-center">${wd} ${md}</th>`;
  }).join("");

  const dataTds = counts.map((c) => {
    const v = (typeof c === 'number' && Number.isFinite(c)) ? String(c) : '—';
    return `<td class="text-center fw-semibold">${v}</td>`;
  }).join("");

  mount.innerHTML = `
    <div class="weekly-counts-wrap mx-auto">
      <table class="table table-sm table-bordered text-center align-middle mb-0">
        <thead>
          <tr>
            <th class="text-center" style="width: 90px;">Total</th>
            ${headers}
          </tr>
        </thead>
        <tbody>
          <tr>
            <th class="text-center">Bookings</th>
            ${dataTds}
          </tr>
        </tbody>
      </table>
    </div>
  `;
}

// === Menu Images (3 fixed slots) ===

async function openMenuModal() {
  // 모달 열릴 때 현재 상태 로딩
  await loadMenuImages();
  bindMenuUploadButtons();
  bindMenuDeleteButtons();
}

async function loadMenuImages() {
  try {
    const items = await fetchMenuFixed3();
    const map = new Map(items.map(it => [String(it.slot), it.url]));

    // 1..3 슬롯 반복
    for (let i = 1; i <= 3; i++) {
      const preview = document.getElementById(`menu${i}Preview`);
      const status  = document.getElementById(`menu${i}Status`);

      const url = map.get(String(i));
      if (url) {
        preview.src = url; // 이미 get_menu_fixed3.php에서 filemtime 기반 캐시버스트
        preview.alt = `menu_${i}`;
        status.textContent = 'Active';
        status.className = 'badge bg-success';
      } else {
        preview.src = '';
        preview.alt = `menu_${i}`;
        status.textContent = 'No image';
        status.className = 'badge bg-secondary';
      }
    }
  } catch (err) {
    console.error('Failed to load menu images:', err);
  }
}

function bindMenuUploadButtons() {
  for (let i = 1; i <= 3; i++) {
    const btn = document.getElementById(`menu${i}UploadBtn`);
    const fileInput = document.getElementById(`menu${i}File`);
    if (!btn || !fileInput) continue;

    btn.onclick = async () => {
      if (!fileInput.files || !fileInput.files[0]) {
        alert('Please choose a file first.');
        return;
      }
      const form = new FormData();
      form.append('slot', String(i));
      form.append('file', fileInput.files[0]);

      btn.disabled = true;
      btn.textContent = 'Uploading...';

      try {
        const res = await fetch(`${API_BASE}/menu_price/upload_menu_image.php`, {
          method: 'POST',
          body: form
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
          throw new Error(data?.error || 'Upload failed');
        }

        // 미리보기 즉시 갱신
        const preview = document.getElementById(`menu${i}Preview`);
        preview.src = data.url; // 서버에서 ?t= 추가해서 내려줌
        fileInput.value = '';
      } catch (err) {
        console.error(err);
        alert('Upload failed: ' + err.message);
      } finally {
        btn.disabled = false;
        btn.textContent = 'Upload';
        // 상태 라벨 갱신을 위해 다시 로드
        loadMenuImages();
      }
    };
  }
}

function bindMenuDeleteButtons() {
  for (let i = 1; i <= 3; i++) {
    const btn = document.getElementById(`menu${i}DeleteBtn`);
    if (!btn) continue;
    btn.onclick = async () => {
      if (!confirm(`Delete file in slot ${i}?`)) return;
      try {
        const res = await fetch(`${API_BASE}/menu_price/delete_menu_image.php`, {
          method: 'POST',
          headers: {'Content-Type':'application/x-www-form-urlencoded'},
          body: new URLSearchParams({ slot: String(i) }).toString()
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
          throw new Error(data?.error || 'Delete failed');
        }
        await loadMenuImages();
      } catch (err) {
        console.error(err);
        alert('Delete failed: ' + err.message);
      }
    };
  }
}
// attach handlers when the admin menu modal is shown
document.addEventListener('DOMContentLoaded', () => {
  const menuModalEl = document.getElementById('menuModal');
  if (!menuModalEl) return;

  menuModalEl.addEventListener('shown.bs.modal', () => {
    openMenuModal(); // inside: loadMenuImages + bindMenuUploadButtons + bindMenuDeleteButtons
  });
});

// 메모 조회: 이메일은 소문자, 캐시는 무효화(_ts)
async function fetchCustomerNoteByKey(name, email, phone) {
  const normName  = (name  || '').trim();
  const normEmail = (email || '').trim().toLowerCase();  // ★ 소문자
  const normPhone = (phone || '').trim();                // (현 DB 키와 동일하게 trim만)

  const q = new URLSearchParams({
    name: normName,
    email: normEmail,
    phone: normPhone,
    _ts: Date.now().toString()                           // ★ 캐시 무효화
  });

  const res = await fetch(`${API_BASE}/info_note/get_customer_note.php?${q.toString()}`, {
    cache: 'no-store'                                     // ★ 캐시 우회
  });
  if (!res.ok) throw new Error('HTTP ' + res.status);
  const j = await res.json();
  return (j && (j.note ?? j?.data?.note)) || '';
}

// 예약 상세 모달의 "Customer Note > Edit" 버튼 → 메모 편집 모달 열기
(function () {
  const btn = document.getElementById('openNoteEditorBtn');
  if (!btn) return;

  // 중복 바인딩 방지
  if (btn.dataset.clickBound) return;
  btn.dataset.clickBound = '1';

  btn.addEventListener('click', () => {
    const name  = (document.getElementById('resvName')?.textContent || '').trim();
    const email = (document.getElementById('resvEmail')?.textContent || '').trim();
    const phone = (document.getElementById('resvPhone')?.textContent || '').trim();

    if (typeof openMemoModal === 'function') {
      // ✅ 저장 후 검색 재조회는 하지 않도록 (예약 상세에서 열었을 때는 false)
      openMemoModal(name, phone, email, { refreshAfterSave: false });
    }
  });
})();

function renderCustomerResults(data) {
  // ✅ 1) 응답 형식 통합: 배열이면 그대로, 객체면 .rows 사용
  const rows = Array.isArray(data) ? data : (data?.rows ?? []);

  const tbody = document.querySelector("#customerResultTable tbody");
  tbody.innerHTML = "";

  if (rows.length === 0) {
    const tr = document.createElement("tr");
    const td = document.createElement("td");
    td.colSpan = 7;
    td.textContent = "No results found.";
    tr.appendChild(td);
    tbody.appendChild(tr);
    return;
  }

  rows.forEach(item => {
    // 화면표시용(escape) vs 데이터용(raw) 분리 권장
    const rawName  = item.name  ?? "";
    const rawPhone = item.phone ?? "";
    const rawEmail = (item.email ?? "");

    const safeName  = rawName.replace(/</g, "&lt;");
    const safePhone = rawPhone;
    const safeEmail = rawEmail;

    const tr = document.createElement("tr");
    tr.setAttribute('data-group-id',      item.latest_group_id || '');
    tr.setAttribute('data-current-name',  rawName);            // ← raw로 보관
    tr.setAttribute('data-current-email', rawEmail.toLowerCase());
    tr.setAttribute('data-birthday', item.birthday || '');

    tr.innerHTML = `
      <td data-role="customer-name" class="customer-name-cell">${safeName}</td>
      <td class="phone-cell">${safePhone}</td>
      <td class="email-cell">${safeEmail}</td>
      <td>${item.visit_count ?? 0}</td>
      <td>${formatMinutes(item.total_minutes)}</td>
      <td>
        <div class="memo-cell">
          <div class="memo-text">${(item.memo ?? '').replace(/</g,'&lt;')}</div>
          <button class="btn btn-sm btn-outline-primary memo-btn"
                  data-name="${rawName}"
                  data-phone="${safePhone}"
                  data-email="${safeEmail}">
            Edit
          </button>
        </div>
      </td>
      <td>${item.ips ?? "-"}</td>
    `;
    tbody.appendChild(tr);
  });

  // 이벤트 바인딩 그대로
  tbody.querySelectorAll('.memo-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const memoModalEl = document.getElementById('memoModal');
      memoModalEl.__rowEl = btn.closest('tr');
      memoModalEl.dataset.ctx = 'list';
      memoModalEl.dataset.refreshAfterSave = '1';
      openMemoModal(btn.dataset.name, btn.dataset.phone, btn.dataset.email);
    });
  });
}


async function searchAllCustomers() {
  try {
    const res = await fetch(`${API_BASE}/info_note/search_customer.php?all=1`);
    const data = await res.json();
    renderCustomerResults(data);
  } catch (err) {
    console.error("Search-all failed:", err);
    alert("An error occurred while loading all customers.");
  }
}

document.getElementById('showAllCustomersBtn')?.addEventListener('click', () => {
  searchAllCustomers();
});

// === admin.js ===
document.addEventListener('DOMContentLoaded', () => {
  // 페이지 상단 달력(#date-picker)에 표시된 날짜를 그대로 사용
  const pageYmd =
    document.getElementById('date-picker')?.value ||
    (typeof toYMD === 'function' ? toYMD(new Date()) : new Date().toISOString().slice(0,10));

  // 표시용 텍스트
  const formDateDisplay = document.getElementById('form-selected-date');
  if (formDateDisplay) formDateDisplay.textContent = pageYmd;

  // 제출용 숨김값
  const gb = document.getElementById('GB_date');
  if (gb) gb.value = pageYmd;

  // (옵션) 시간 슬롯 계산이 날짜를 참조하면 페이지 날짜만 보도록 강제
  try { window.__FORCE_SLOT_DATE__ = pageYmd; } catch {}
});

// 모달 열기: 행(tr)의 data-*에서 값 받아 프리필
function openEditContactModalFromRow(tr) {
  const gid   = tr?.dataset.groupId || '';
  const name  = (tr?.dataset.currentName  || '').trim();
  const email = (tr?.dataset.currentEmail || '').trim();

  if (!gid) return alert('no group_id.');

  document.getElementById('editGroupId').value = gid;
  document.getElementById('editName').value  = name;
  document.getElementById('editEmail').value = email;
  document.getElementById('editBirthday').value = tr.dataset.birthday || '';

  new bootstrap.Modal(document.getElementById('editContactModal')).show();
}

// 저장 클릭 → API 호출 → 재조회
document.getElementById('saveContactBtn').addEventListener('click', async () => {
  const gid   = document.getElementById('editGroupId').value.trim();
  const name  = document.getElementById('editName').value.trim();
  const email = document.getElementById('editEmail').value.trim().toLowerCase();
  const bdayEl = document.getElementById('editBirthday');                 // ★ ADD
  const birthday = bdayEl ? (bdayEl.value || '').trim() : '';             // ★ ADD
  if (!gid) return alert('no group_id.');

  const body = { group_id: gid };
  if (name)  body.new_name  = name.replace(/\s+/g, ' ');
  if (email) {
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return alert('Please, enter a valid email address.');
    body.new_email = email;
  }
  if (birthday) {                                                         // ★ ADD
    if (!/^\d{4}-\d{2}-\d{2}$/.test(birthday)) return alert('Invalid birthday format (YYYY-MM-DD).');
    body.birthday = birthday;
  }
  if (!body.new_name && !body.new_email && !body.birthday) {
    // 아무 것도 안 바꾸면 닫기만
    return bootstrap.Modal.getInstance(document.getElementById('editContactModal')).hide();
  }

  const btn = document.getElementById('saveContactBtn');
  btn.disabled = true;

  try {
    const res = await fetch(`${API_BASE}/info_note/update_info.php`, {
      method: 'POST',
      headers: { 'Content-Type':'application/json' },
      body: JSON.stringify(body)
    });
    const text = await res.text();
    let j;
    try { j = JSON.parse(text); }
    catch { throw new Error(`Server returned non-JSON (${res.status}): ${text.slice(0,160)}`); }

    if (!j.ok) throw new Error(j.error || 'Update failed');

    bootstrap.Modal.getInstance(document.getElementById('editContactModal')).hide();
    alert(`Info updated! (updated ${j.affected} cases)`);

    // ✅ 고객 목록 즉시 다시 불러오기
    if (typeof searchAllCustomers === 'function') {
      await searchAllCustomers();
    }

    // 예약표 갱신은 유지
    await refreshScreen({ reason: 'contact-updated' });
  } catch (err) {
    console.error(err);
    alert('Update failed: ' + err.message);
  } finally {
    btn.disabled = false;
  }
});

// 고객검색 테이블에서 이름/이메일 셀 클릭 → 모달 열기 (위임)
document.querySelector('#customerResultTable tbody').addEventListener('click', (e) => {
  const cell = e.target.closest('[data-role="customer-name"], .email-cell');
  if (!cell) return;
  const tr = cell.closest('tr');
  openEditContactModalFromRow(tr);
});


// === Modal Hygiene: 모든 모달 공통 포커스/ARIA 정리 + 자동 새로고침 일시중지 ===
(function installModalHygiene(){
  if (window.__modalHygieneInstalled) return;
  window.__modalHygieneInstalled = true;

  const firstFocusable = (root) =>
    root.querySelector('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])');

  // 초기: 모달은 숨김이라 가정
  document.querySelectorAll('.modal').forEach(m => m.setAttribute('aria-hidden', 'true'));

  // 열리기 직전: blur + aria-hidden 제거 + auto reload 일시중지
  document.addEventListener('show.bs.modal', (e) => {
    try { document.activeElement?.blur(); } catch {}
    e.target.removeAttribute('aria-hidden');
    try { typeof pauseAutoReload === 'function' && pauseAutoReload(); } catch {}
  });

  // 완전히 열린 뒤: 안전 포커스
  document.addEventListener('shown.bs.modal', (e) => {
    const m = e.target;
    (firstFocusable(m) || m).focus?.({ preventScroll: true });
  });

  // 닫히기 직전: 내부 포커스 남아있으면 먼저 blur (경고/프리징 방지 핵심)
  document.addEventListener('hide.bs.modal', (e) => {
    const m = e.target;
    if (m.contains(document.activeElement)) {
      try { document.activeElement.blur(); } catch {}
    }
  });

  document.addEventListener('hidden.bs.modal', () => {
    unlockPage();
    try { typeof resumeAutoReload === 'function' && resumeAutoReload(); } catch {}
  });
  document.addEventListener('hide.bs.offcanvas', (e) => {
    // 포커스가 남아 aria 경고 나는 케이스 방지
    try { document.activeElement?.blur(); } catch {}
  });
  document.addEventListener('hidden.bs.offcanvas', () => {
    unlockPage();
    try { typeof resumeAutoReload === 'function' && resumeAutoReload(); } catch {}
  });
  document.addEventListener('shown.bs.offcanvas', () => {
    try { typeof pauseAutoReload === 'function' && pauseAutoReload(); } catch {}
  });
})();

function openReservationDetailFromSlot(slot) {
  const tooltip = slot.getAttribute('title') || '';
  const [name = '', phone = '', email = ''] = tooltip.split('\n');

  // 표시
  const nameEl  = document.getElementById('resvName');
  const phoneEl = document.getElementById('resvPhone');
  const emailEl = document.getElementById('resvEmail');
  if (nameEl)  nameEl.textContent  = name  || 'N/A';
  if (phoneEl) phoneEl.textContent = phone || 'N/A';
  if (emailEl) emailEl.textContent = email || 'N/A';

  // 모달 dataset
  const modalEl = document.getElementById('reservationDetailModal');
  modalEl.dataset.resvId  = slot.dataset.resvId  || '';
  modalEl.dataset.groupId = slot.dataset.groupId || '';
  modalEl.dataset.start   = slot.dataset.start   || '';
  modalEl.dataset.end     = slot.dataset.end     || '';
  modalEl.dataset.room    = slot.dataset.room    || '';

  // 고객 메모
  const noteTextEl    = document.getElementById('customerNoteText');
  const noteSpinnerEl = document.getElementById('customerNoteSpinner');
  if (noteTextEl && noteSpinnerEl && typeof fetchCustomerNoteByKey === 'function') {
    noteTextEl.textContent = '—';
    noteSpinnerEl.classList.remove('d-none');
    fetchCustomerNoteByKey(name, email, phone)
      .then(note => { noteTextEl.textContent = note || '—'; })
      .catch(() => { noteTextEl.textContent = '—'; })
      .finally(() => { noteSpinnerEl.classList.add('d-none'); });
  }

  // aria-hidden 경고 예방: 먼저 blur, 열린 뒤 포커스 이동
  try { document.activeElement?.blur(); } catch {}
  modalEl.addEventListener('shown.bs.modal', () => {
    (modalEl.querySelector('#editReservationBtn') || modalEl)
      .focus?.({ preventScroll: true });
  }, { once: true });

  bootstrap.Modal.getOrCreateInstance(modalEl).show();
}
(function bindReservedSlotDelegation(){
  if (window.__RESV_DELEGATED) return;
  window.__RESV_DELEGATED = true;

  document.addEventListener('click', (e) => {
    // 드래그 중 클릭 막기 (이미 쓰는 suppressClick 활용)
    if (window.suppressClick) return;

    const slot = e.target.closest('.time-slot.bg-danger');
    if (!slot) return;

    openReservationDetailFromSlot(slot);
  });
})();


// ================= Scoreboard minimal (no IIFE) =================

// 1) helpers
const $id = (id) => document.getElementById(id);
const setText = (id, v) => { const el = $id(id); if (el) el.textContent = (v ?? '—'); };
const yyyymm = (d=new Date()) => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`;

// 2) 데이터 → 오버뷰 채우기
function fillCompetitionOverview(resp) {
  const ev   = resp?.event || {};
  const pars = Array.isArray(ev.pars) ? ev.pars : [];
  window.__currentEvent = ev; // ✅ Save에서 event_id 읽어갈 전역
  renderContextBar({
   month_label: ev.event_date ? ev.event_date.slice(0,7) : ev.month_label,
   title: ev.title,
   course_name: ev.course_name,
   event_par: (resp.par_total ?? ev.event_par)
 });

  for (let i=1;i<=18;i++) setText(`par${i}`, pars[i-1] ?? '—');

  const front = pars.slice(0,9).reduce((a,b)=>a+(+b||0),0);
  const back  = pars.slice(9).reduce((a,b)=>a+(+b||0),0);
  const total = front + back;

  setText('ovr-par-front', pars.length >= 9  ? String(front) : '—');
  setText('ovr-par-back',  pars.length === 18 ? String(back)  : '—');
  setText('ovr-par-sum',   pars.length === 18 ? String(total) : '—');

  const miss = [];
  for (let i=0;i<18;i++) if (!(+pars[i] > 0)) miss.push(`H${i+1}`);
  const warnEl = $id('ovr-par-warning');
  const missEl = $id('ovr-missing-holes');
  if (warnEl && missEl) {
    if (miss.length) { missEl.textContent = miss.join(', '); warnEl.classList.remove('d-none'); }
    else { warnEl.classList.add('d-none'); }
  }
}

// 3) API
async function fetchCompetitionByMonth(month) {
  const m = month || yyyymm();
  const url = `${API_BASE}/scoreboard/competition_get.php?month=${encodeURIComponent(m)}`;
  const res = await fetch(url, { cache: 'no-store' });
  const txt = await res.text();
  let j; try { j = JSON.parse(txt); } catch { throw new Error('Invalid JSON: ' + txt.slice(0,120)); }
  if (!res.ok || !j.ok) throw new Error(j?.error || ('HTTP '+res.status));
  return j;
}

// 4) 로더 (전역에서 호출 가능)
async function loadCompetitionOverview(month) {
  // compMonth 인풋이 있으면 우선 사용, 없으면 인자로 받은 month, 둘 다 없으면 현재월
  const compEl = $id('compMonth');
  const m = (compEl && compEl.value) ? compEl.value : (month || getPageMonth());

  const data = await fetchCompetitionByMonth(m);
  fillCompetitionOverview(data);
}

// 5) 모달 이벤트 바인딩
(function bindCompetitionModalOnce(){
  const modal = $id('competitionModal');
  if (!modal) { console.warn('[SB] #competitionModal not found'); return; }
  if (modal.__sbBound) return;
  modal.__sbBound = true;

  modal.addEventListener('shown.bs.modal', () => {

    // compMonth 기본값 없으면 현재월 세팅
    const compEl = $id('compMonth');
    if (compEl && !compEl.value) compEl.value = getPageMonth();
    loadCompetitionOverview(compEl?.value).catch(err => console.error('[SB] load error:', err));
  });
})();

// ===== Competition Setup → Save (Setup 탭용) =====
// API_BASE: 전역에 이미 있다고 가정

function collectSetupPayload() {
  const title  = document.getElementById('set_title')?.value.trim() || '';
  const month  = document.getElementById('set_month')?.value || ''; // YYYY-MM
  const course = document.getElementById('set_course')?.value.trim() || '';
  const pars   = Array.from(document.querySelectorAll('.set-par-input'))
                    .map(inp => parseInt(inp.value, 10));

  // 검증
  const invalidIdx = pars.findIndex(v => !Number.isInteger(v) || v < 3 || v > 6);
  const allFilled  = pars.length === 18 && invalidIdx === -1;
  const total      = pars.reduce((a,b) => a + (Number.isFinite(b) ? b : 0), 0);

  return { title, month, course_name: course, pars, total, allFilled, invalidIdx };
}

async function saveCompetitionSetup() {
  const btn = document.getElementById('set_save_btn');
  if (!btn) return;

  const { title, month, course_name, pars, total, allFilled, invalidIdx } = collectSetupPayload();

  if (!title)  return alert('Title을 입력해 주세요.');
  if (!month)  return alert('Month(YYYY-MM)를 선택해 주세요.');
  if (!allFilled) {
    const hole = (invalidIdx >= 0 ? invalidIdx + 1 : '중 일부');
    return alert(`Hole Par 입력을 확인해 주세요. (문제 위치: H${hole})`);
  }

  const payload = {
    title,
    course_name,
    month,            // 서버에서 YYYY-MM-01로 처리하도록 (기존 규격 유지)
    pars,             // [18]
    event_par_total: total
  };

  const orig = btn.textContent;
  btn.disabled = true; btn.textContent = 'Saving…';

  try {
    const res  = await fetch(`${API_BASE}/scoreboard/competition_create.php`, {
      method : 'POST',
      headers: { 'Content-Type': 'application/json' },
      body   : JSON.stringify(payload),
      cache  : 'no-store'
    });

    const text = await res.text();
    let j; try { j = JSON.parse(text); } catch { throw new Error('Invalid JSON: ' + text.slice(0,160)); }
    if (!res.ok || !j.ok) throw new Error(j.error || ('HTTP ' + res.status));

    // 저장 성공 → 오버뷰 갱신
    try { 
      if (typeof loadCompetitionOverview === 'function') {
        await loadCompetitionOverview(month);
      }
    } catch (_) {}

    // 탭을 Overview로 돌리고 알림
    document.querySelector('[data-bs-target="#tabOverview"]')?.click();
    alert('Saved!');
    btn.textContent = orig;
  } catch (err) {
    console.error(err);
    alert('Save failed: ' + err.message);
    btn.textContent = orig;
  } finally {
    btn.disabled = false;
  }
}

// 버튼 바인딩
document.getElementById('set_save_btn')?.addEventListener('click', () => {
  saveCompetitionSetup();
});

function getPageMonth() {
  const dp = document.getElementById('date-picker')?.value; // 'YYYY-MM-DD'
  if (dp && /^\d{4}-\d{2}-\d{2}$/.test(dp)) return dp.slice(0, 7); // YYYY-MM
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`;
}
// ===== Hole Par live summary =====
function updateSetupParSummary() {
  const inputs = Array.from(document.querySelectorAll('.set-par-input'));
  // 숫자 한 글자만, 3~6만 허용
  inputs.forEach(inp => {
    let v = (inp.value || '').replace(/\D/g, '').slice(0,1);
    if (v && !/[3-6]/.test(v)) v = ''; // 3~6 외 입력은 비움
    if (inp.value !== v) inp.value = v;
  });

  const vals = inputs.map(i => +i.value || 0);
  const front = vals.slice(0,9).reduce((a,b)=>a+b,0);
  const back  = vals.slice(9,18).reduce((a,b)=>a+b,0);
  const total = front + back;

  const setTxt = (id, v) => {
    const el = document.getElementById(id);
    if (el) el.textContent = v ? String(v) : '—';
  };
  setTxt('set_front', front);
  setTxt('set_back',  back);
  setTxt('set_total', total);

}

// 입력 시마다 합계 업데이트
document.addEventListener('input', (e) => {
  if (e.target && e.target.classList?.contains('set-par-input')) {
    updateSetupParSummary();
  }
});

// Setup 탭 열릴 때 한 번, 오버뷰에서 미리채울 때도 한 번
document.querySelector('[data-bs-target="#tabSetup"]')
  ?.addEventListener('shown.bs.tab', updateSetupParSummary);

  // Step 1: 참가자 - 전화 검색 → 결과 "리스트만" 표시
(function setupParticipantPhoneSearch(){
  const phoneEl   = document.getElementById('prt_phone');   // 입력칸
  const resultBox = document.getElementById('prt_results'); // 결과 컨테이너(.list-group)
  if (!phoneEl || !resultBox) return;

  if (resultBox.__bound) { console.warn('[prt] search bind skipped (already bound)'); return; }
  resultBox.__bound = true; console.info('[prt] search bound');
  const normPhone = (p)=> (p||'').replace(/\D+/g,''); // 숫자만
  let timer = null;

  phoneEl.addEventListener('input', () => {
    const q = normPhone(phoneEl.value);

    // 7자리 미만이면 결과 숨김/초기화
    if (timer) clearTimeout(timer);
    if (q.length < 7) {
      resultBox.classList.add('d-none');
      resultBox.innerHTML = '';
      return;
    }

    // 스피너 표시 후 250ms 디바운스 검색
    resultBox.classList.remove('d-none');
    resultBox.innerHTML = '<div class="list-group-item small text-muted">Searching…</div>';
    timer = setTimeout(() => searchByPhone(q), 250);
  });

  async function searchByPhone(digits) {
    try {
      const url = `${API_BASE}/info_note/search_customer.php?phone=${encodeURIComponent(digits)}&t=${Date.now()}`;
      const res = await fetch(url, { cache: 'no-store' });
      const data = await res.json();
      const rows = Array.isArray(data) ? data : (data?.rows ?? []);

      if (!rows.length) {
 resultBox.innerHTML = `
    <div class="list-group-item d-flex justify-content-between align-items-center">
      <span>No matches. 새 고객으로 추가 가능합니다.</span>
      <button type="button" class="btn btn-sm btn-outline-primary" id="prt_add_new_btn">Add as New</button>
    </div>`;
        return;
      }

      // 리스트만 보여줌 (선택/자동채움은 다음 단계에서)
      const esc = s => String(s ?? '').replace(/</g,'&lt;');
      resultBox.innerHTML = rows.slice(0, 8).map(r => {
        const obj = {
          customer_id: r.id ?? r.customer_id ?? null,
          name : (r.name ?? r.full_name ?? '').trim(),
          phone: (r.phone ?? '').trim(),
          email: (r.email ?? '').trim(),
        };
        const data = JSON.stringify(obj).replace(/"/g, '&quot;'); // attr 안전
        return `
          <button type="button" class="list-group-item text-start prt-result"
                  data-json="${data}">
            <div class="fw-semibold">${esc(obj.name) || '(no name)'}</div>
            <div class="small text-muted">${esc(obj.phone) || '—'} · ${esc(obj.email) || '—'}</div>
          </button>`;
      }).join('');
    } catch (e) {
      resultBox.innerHTML = '<div class="list-group-item small text-danger">Search failed.</div>';
    }
  }
  resultBox.addEventListener('click', (e) => {
  const item = e.target.closest('.prt-result');
  if (!item) return;
  try {
    const entry = JSON.parse((item.dataset.json || '').replace(/&quot;/g, '"'));
    console.log('[prt] selected:', entry);
  } catch {}
});

resultBox.addEventListener('click', (e) => {
  const btn = e.target.closest('#prt_add_new_btn');
  if (!btn) return;
  const mEl = document.getElementById('newCustomerModal');
  if (mEl) bootstrap.Modal.getOrCreateInstance(mEl).show();
});
})();

// STEP 2 — 컨텍스트바 렌더링
function renderContextBar(ev) {
  const monthEl  = document.getElementById('ctx-month');
  const titleEl  = document.getElementById('ctx-title');
  const courseEl = document.getElementById('ctx-course');
  const parEl    = document.getElementById('ctx-par');

  if (!ev) {
    monthEl.textContent  = '—';
    titleEl.textContent  = '—';
    courseEl.textContent = '—';
    parEl.textContent    = 'Par —';
    return;
  }

  monthEl.textContent  = ev.month_label || ev.event_date || '—';
  titleEl.textContent  = ev.title       || '—';
  courseEl.textContent = ev.course_name || '—';
  parEl.textContent    = `Par ${ev.event_par ?? '—'}`;
}
// 임시 로스터 상태 (없으면 생성)
window.__prtRoster = window.__prtRoster || [];

// 중복 방지 키: 고객 id 우선, 없으면 전화번호
const prtKeyOf = (x) => x.customer_id ? `id:${x.customer_id}` : `ph:${(x.phone||'').replace(/\D+/g,'')}`;

// 아주 최소 렌더러 (이미 있으면 이건 지워도 됨)
function renderRosterTable() {
  const tbody = document.getElementById('prt_table_body');
  const saveBtn = document.getElementById('prt_save_btn');
  if (!tbody) return;

  if (!__prtRoster.length) {
    tbody.innerHTML = `<tr><td colspan="6" class="text-center text-muted small py-3">No participants yet.</td></tr>`;
    saveBtn?.setAttribute('disabled','');
    return;
  }
  const esc = s => String(s ?? '').replace(/</g,'&lt;');
  tbody.innerHTML = __prtRoster.map((r,i)=>`
    <tr data-idx="${i}">
      <td class="text-center">${i+1}</td>
      <td>${esc(r.name)}</td>
      <td>${esc(r.phone)}</td>
      <td>${esc(r.email)}</td>
      <td><input class="form-control form-control-sm prt-note" placeholder="Note (optional)" value="${esc(r.note||'')}"></td>
      <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger prt-del">Del</button></td>
    </tr>
  `).join('');
  saveBtn?.removeAttribute('disabled');
}

// 결과 클릭 → 곧바로 로스터에 추가(임시), 중복이면 무시
document.getElementById('prt_results')?.addEventListener('click', (e) => {
  const item = e.target.closest('.prt-result');
  if (!item) return;
  const data = (item.dataset.json || '').replace(/&quot;/g,'"');
  let entry = null; try { entry = JSON.parse(data); } catch {}
  if (!entry) return;

  const key = prtKeyOf(entry);
  if (__prtRoster.some(r => prtKeyOf(r) === key)) return; // already added

  __prtRoster.push(entry);
  renderRosterTable();
  clearParticipantSearchUI();                  // (1) 검색창 정리
  scrollAndFlashRowByIndex(__prtRoster.length - 1); // (2)(3) 스크롤 + 하이라이트
});

document.getElementById('prt_table_body')?.addEventListener('click', (e) => {
  const btn = e.target.closest('.prt-del');
  if (!btn) return;
  const tr = btn.closest('tr');
  const idx = Number(tr?.dataset.idx ?? -1);
  if (idx >= 0) {
    __prtRoster.splice(idx, 1);
    renderRosterTable();
  }
});
document.getElementById('prt_table_body')?.addEventListener('input', (e) => {
  if (!e.target.classList?.contains('prt-note')) return;
  const tr = e.target.closest('tr');
  const idx = Number(tr?.dataset.idx ?? -1);
  if (idx >= 0) __prtRoster[idx].note = e.target.value;
});

(() => {
  const btn = document.getElementById('prt_save_btn');
  if (!btn || btn.__bound) return;
  btn.__bound = true;

  btn.addEventListener('click', async () => {
    const eventId = window.__currentEvent?.id;
    if (!eventId) { alert('이벤트가 없습니다. 먼저 Overview를 불러오세요.'); return; }

    // 기존 고객만 전송 (신규는 다음 단계에서 처리)
    const existing = (window.__prtRoster || [])
      .filter(r => !!r.customer_id)
      .map(r => ({ customer_id: r.customer_id, note: r.note || '' }));

    if (existing.length === 0) { alert('등록할 기존 고객이 없습니다.'); return; }

    const orig = btn.textContent;
    btn.disabled = true; btn.textContent = 'Saving…';

    try {
      const res = await fetch(`${API_BASE}/scoreboard/participants_save.php`, {
        method : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body   : JSON.stringify({ event_id: eventId, roster: existing }),
        cache  : 'no-store'
      });
      const j = await res.json();
      if (!res.ok || !j.ok) throw new Error(j.error || `HTTP ${res.status}`);

      console.log('[prt] saved roster', { event_id: eventId, roster: existing, server: j });
      alert(`Saved! added ${j.added}, skipped ${j.skipped}`);
    } catch (e) {
      alert('Save failed: ' + (e?.message || e));
    } finally {
      btn.disabled = false; btn.textContent = orig;
    }
  });
})();


// 모달 "Add" 버튼 → 이름/전화/이메일으로 정확일치 find-or-create
document.getElementById('nc_confirm_btn')?.addEventListener('click', async () => {
  const name  = (document.getElementById('nc_name')?.value || '').trim();
  const phone = (document.getElementById('nc_phone')?.value || '').replace(/\D+/g,'');
  const email = (document.getElementById('nc_email')?.value || '').trim().toLowerCase();

  if (!name)  { alert('Name을 입력해 주세요.'); return; }
  if (!phone || phone.length < 7) { alert('유효한 Phone(7자리 이상)을 입력해 주세요.'); return; }

  const url = `${API_BASE}/info_note/search_customer.php`; // ← GET 검색과 같은 엔드포인트 (POST + exact:1)
  const payload = { exact: 1, name, phone, email };

  const btn = document.getElementById('nc_confirm_btn');
  const orig = btn.textContent; btn.disabled = true; btn.textContent = 'Saving…';

  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      cache: 'no-store'
    });
    const j = await res.json();
    if (!res.ok || !j.ok) throw new Error(j.error || `HTTP ${res.status}`);

    // 서버가 반환한 확정 customer_id와 스냅샷으로 로스터에 추가
    const cid = j.customer_id;
    const snap = j.snapshot || { full_name: name, phone, email };
    if (!window.__prtRoster) window.__prtRoster = [];

    // 중복 방지(같은 customer_id 이미 있으면 스킵)
    if (!__prtRoster.some(r => r.customer_id === cid)) {
      __prtRoster.push({
        customer_id: cid,
        name: snap.full_name || name,
        phone: snap.phone || phone,
        email: snap.email || email
      });
      if (typeof renderRosterTable === 'function') renderRosterTable();
      clearParticipantSearchUI();                  // (1)
      scrollAndFlashRowByIndex(__prtRoster.length - 1); // (2)(3)
    }

    // 모달 닫기
    const mEl = document.getElementById('newCustomerModal');
    if (mEl) bootstrap.Modal.getOrCreateInstance(mEl).hide();

  } catch (e) {
    alert('Create/find failed: ' + (e?.message || e));
  } finally {
    btn.disabled = false; btn.textContent = orig;
  }
});

// 서버에서 로스터 불러오기
async function fetchParticipantsList(eventId) {
  const url = `${API_BASE}/scoreboard/participants_list.php?event_id=${encodeURIComponent(eventId)}&t=${Date.now()}`;
  const res = await fetch(url, { cache: 'no-store' });
  const j = await res.json();
  if (!res.ok || !j.ok) throw new Error(j.error || `HTTP ${res.status}`);
  return j.registrations || [];
}

// Participants 탭 열릴 때 1회만 프리로드
async function preloadParticipants() {
  const ev = window.__currentEvent;
  if (!ev?.id) return;                             // 이벤트 없으면 패스
  if (preloadParticipants._loadedFor === ev.id) return; // 같은 이벤트로는 한 번만
  preloadParticipants._loadedFor = ev.id;

  const rows = await fetchParticipantsList(ev.id);
  // 서버 목록 → 로컬 로스터로 매핑
  window.__prtRoster = rows.map(r => ({
    registration_id: Number(r.registration_id) || null,
    customer_id: Number(r.customer_id) || null,
    name: r.name || r.full_name_snapshot || '',
    phone: r.phone || '',
    email: r.email || '',
  }));
  if (typeof renderRosterTable === 'function') renderRosterTable();
}

// 탭 shown 시 로딩
document.querySelector('[data-bs-target="#tabParticipants"]')
  ?.addEventListener('shown.bs.tab', () => {
    preloadParticipants().catch(err => console.error('[prt] preload error:', err));
  });
// 어디서 클릭되든 #prt_add_btn 누르면 가장 먼저 모달 띄우기
document.addEventListener('click', (e) => {
  const btn = e.target.closest('#prt_add_btn');
  if (!btn) return;

  // 이 핸들러가 최우선으로 동작
  e.preventDefault();
  e.stopImmediatePropagation();
  e.stopPropagation();

  const mEl = document.getElementById('newCustomerModal');
  if (mEl) bootstrap.Modal.getOrCreateInstance(mEl).show();
  else console.warn('[prt] #newCustomerModal not found');
}, true); // ← capture = true

// === Participants UX helpers: clear search, scroll, flash ===
function clearParticipantSearchUI() {
  const phoneEl   = document.getElementById('prt_phone');
  const resultBox = document.getElementById('prt_results');
  if (phoneEl)   phoneEl.value = '';
  if (resultBox) { resultBox.classList.add('d-none'); resultBox.innerHTML = ''; }
}

function ensureRowFlashStyle() {
  if (document.getElementById('prt-row-flash-style')) return;
  const css = `
    @keyframes prtFlash { 0% { background: #fff8c5; } 100% { background: transparent; } }
    #prt_table_body tr.flash { animation: prtFlash 1.5s ease-out; }
  `;
  const s = document.createElement('style');
  s.id = 'prt-row-flash-style';
  s.textContent = css;
  document.head.appendChild(s);
}
function scrollAndFlashRowByIndex(idx) {
  ensureRowFlashStyle();
  const row = document.querySelector(`#prt_table_body tr[data-idx="${idx}"]`);
  if (!row) return;
  row.classList.remove('flash'); // re-trigger
  void row.offsetWidth;
  row.classList.add('flash');
  setTimeout(() => row.classList.remove('flash'), 1600);
}
function unlockPage() {
  document.querySelectorAll('.modal-backdrop, .offcanvas-backdrop').forEach(b => b.remove());
  document.body.classList.remove('modal-open');
  document.body.style.removeProperty('overflow');
  document.body.style.removeProperty('paddingRight');
  document.documentElement.style.removeProperty('overflow'); // ★ html도 해제
}

// --- Sticky footer hotfix: 확장프로그램이 body 끝에 뭘 꽂아도 footer를 항상 맨 뒤로 ---
(function installFooterFix(){
  if (window.__footerFixInstalled) return; // 중복 방지
  window.__footerFixInstalled = true;

  const pickFooter = () => document.querySelector('footer, .site-footer, #footer');

  const ensureLast = () => {
    const f = pickFooter();
    if (!f) return;
    if (document.body.lastElementChild !== f) {
      document.body.appendChild(f); // footer를 body의 마지막 자식으로 되돌림
    }
  };

  // 1) 지금 한 번
  ensureLast();

  // 2) 이후로 body 자식이 변하면(확장프로그램이 span/iframe 끼워 넣을 때) 다시 정리
  const obs = new MutationObserver(() => ensureLast());
  obs.observe(document.body, { childList: true });

  // 3) 로드 완료 시 한 번 더(안전)
  window.addEventListener('load', ensureLast);
})();

// === New Customer Modal: 항상 리셋 ===
(function initNcModalReset(){
  const modal = document.getElementById('newCustomerModal');
  if (!modal || modal.__ncResetBound) return;
  modal.__ncResetBound = true;

  const ids = ['nc_name','nc_phone','nc_email'];
  function resetNcForm() {
    ids.forEach(id => {
      const el = document.getElementById(id);
      if (!el) return;
      el.value = '';
      el.classList.remove('is-invalid','is-valid');
    });
  }

  // 열릴 때도 비워서 “항상 빈 상태”로 시작
  modal.addEventListener('show.bs.modal', resetNcForm);
  // 닫히면 다시 비워 두기 (성공/취소 모두 커버)
  modal.addEventListener('hidden.bs.modal', resetNcForm);

  // (선택) 열리고 나서 이름 칸에 포커스
  modal.addEventListener('shown.bs.modal', () => {
    document.getElementById('nc_name')?.focus({ preventScroll: true });
  });
})();

// ==== Admin Auto Refresh (every 3 min, soft refresh) ====
(function setupAdminAutoRefresh(){
  // 외부에서 재사용할 수 있게 노출
  const REFRESH_MS = 3 * 60 * 1000; // 운영: 3분 (테스트는 10*1000으로)

  // 타이머 핸들
  let timer = null;

  // 조건: 리프레시 해도 괜찮을 때만
  function offcanvasOpen() {
    const oc = els.offcanvasEl; // #bookingCanvas
    return !!oc && oc.classList.contains('show');
  }
  function anyModalOpen() {
    return !!document.querySelector('.modal.show,[role="dialog"][open]');
  }
  function userIsTyping() {
    const ae = document.activeElement;
    return !!(ae && ae.matches('input, textarea, select, [contenteditable="true"]'));
  }
  function canAutoRefresh() {
    if (document.hidden) return false;            // 백그라운드 탭
    if (offcanvasOpen()) return false;            // 오프캔버스 열림
    if (anyModalOpen()) return false;             // 모달 열림
    if (userIsTyping()) return false;             // 입력 중
    if (window.suppressClick) return false;       // 드래그 중 클릭 차단 상태
    if (window.isEditMode) return false;          // 편집 모드
    if (window.dragState?.active) return false;   // 예약 드래그 중
    return true;
  }

  async function softRefresh() {
    try {
      const date = els.datePicker?.value;
      if (!date) return;
      // 화면만 새로 그리기
      clearAllTimeSlots();
      await loadAllRoomReservations(date);
      // 약간 늦게 과거 슬롯 마킹 (DOM 반영 후)
      setTimeout(() => {
        try { markPastTableSlots(date, '.time-slot', { disableClick: true }); } catch {}
      }, 50);
      window.__lastRefreshAt = new Date();
      // console.debug('[admin-auto-refresh] softRefresh OK', window.__lastRefreshAt);
    } catch (e) {
      console.warn('[admin-auto-refresh] softRefresh failed:', e);
    }
  }

  async function tick() {
    if (!canAutoRefresh()) return;   // 조건 안 되면 스킵
    await softRefresh();
  }

  function startTimer() {
    if (timer) clearInterval(timer);
    timer = setInterval(tick, REFRESH_MS);
  }

  // 공개 API (기존 pause/resume 대체)
  window.pauseAutoReload = function() { if (timer) { clearInterval(timer); timer = null; } };
  window.resumeAutoReload = function() { startTimer(); };

  // 즉시 시작
  startTimer();

  // 탭이 다시 보이면 한 번 즉시 갱신(원하면 주석)
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && canAutoRefresh()) softRefresh();
  });

  // 모달/오프캔버스 닫히면 즉시 갱신 + 타이머 리셋
  document.addEventListener('hidden.bs.modal', async () => { await softRefresh(); startTimer(); });
  els.offcanvasEl?.addEventListener('hidden.bs.offcanvas', async () => { await softRefresh(); startTimer(); });

})();

// ==== Unified refresh helper ====
async function refreshScreen(opts = {}) {
  const { hard = false, reason = '' } = opts;

  // 하드 리로드가 필요한 케이스만 강제
  if (hard) return location.reload();

  // 소프트 리프레시 (관리자 페이지 전용)
  try {
    const date = els?.datePicker?.value;
    if (!date) return location.reload(); // 안전한 폴백

    // 자동 새로고침과 충돌 방지
    if (typeof pauseAutoReload === 'function') pauseAutoReload();

    // 전체 갈아엎지 말고 덮어그리기
    await loadAllRoomReservations(date);
    requestAnimationFrame(() => {
      try { markPastTableSlots(date, '.time-slot', { disableClick: true }); } catch {}
    });

    // 타이머 재개
    if (typeof resumeAutoReload === 'function') resumeAutoReload();

    window.__lastRefreshAt = new Date();
    return;
  } catch (e) {
    console.warn('[refreshScreen] soft failed, fallback reload. reason=', reason, e);
    return location.reload(); // 폴백
  }
}


(function mountRefreshBadge(){
  const badge = document.createElement('div');
  badge.id = 'refreshBadge';
  badge.style.cssText = `
    position:fixed; right:10px; bottom:10px; z-index:9999;
    background:#0008; color:#fff; padding:6px 10px; border-radius:8px;
    font-size:12px; backdrop-filter:saturate(1.5) blur(2px);
  `;
  badge.textContent = 'Last refresh: —';
  document.body.appendChild(badge);

  // 2초마다 표시 업데이트
  setInterval(() => {
    if (!window.__lastRefreshAt) return;
    badge.textContent = 'Last refresh: ' + window.__lastRefreshAt.toLocaleTimeString();
  }, 2000);
})();

// 자정 넘김 보정: close <= open 이면 +1440 (익일 마감)
function safeCloseToMin(closeHHMM, isClosed, openMin) {
  if (!closeHHMM || isClosed) return openMin ?? 0;
  const [h, m] = String(closeHHMM).slice(0, 5).split(':').map(Number);
  let cm = h * 60 + m;
  if (cm <= (openMin ?? 0)) cm += 1440; // 익일 보정
  return cm;
}

function displayTimeLabel(hhmmOrMin) {
  const isNum = typeof hhmmOrMin === 'number';
  let min = isNum ? hhmmOrMin : toMin(hhmmOrMin);
  // 24:00, 24:30, 25:00 … → 화면에는 00:00, 00:30, 01:00
  const m = ((min % 1440) + 1440) % 1440;
  const h = String(Math.floor(m / 60)).padStart(2, '0');
  const mm = String(m % 60).padStart(2, '0');
  return `${h}:${mm}`;
}
