<?php

    // DB 접속 정보
    $host = 'localhost';
    $db = 'golf_booking';
    $user = 'root';
    $pass = '8888';
    $charset = 'utf8mb4';

    // PDO 설정
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        echo "Error: ".$e->getMessage();
        exit();
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $GB_date = $_POST['GB_date'] ?? null;
        $GB_room_no = $_POST['GB_room_no'] ?? [];
        $GB_start_time = $_POST['GB_start_time'] ?? null;
        $GB_end_time = $_POST['GB_end_time'] ?? null;
        $GB_num_guests = $_POST['GB_num_guests'] ?? null;
        $GB_preferred_hand = $_POST['GB_preferred_hand'] ?? null; 
        $GB_name = $_POST['GB_name'] ?? null;
        $GB_email = $_POST['GB_email'] ?? null;
        $GB_phone = $_POST['GB_phone'] ?? null;
        $GB_consent = ($_POST["GB_consent"] ?? '') === "on" ? 1 : 0;


         // 유효성 검사 (예: 필수값 확인)
        if ($GB_date && !empty($GB_room_no) && $GB_start_time && $GB_end_time && $GB_name && $GB_email && $GB_num_guests && $GB_phone && $GB_consent && $GB_preferred_hand) {
            foreach ($GB_room_no as $room_no) {
                $sql = "INSERT INTO gb_reservation 
                    (GB_date, GB_room_no, GB_start_time, GB_end_time, GB_num_guests, GB_preferred_hand, GB_name, GB_email, GB_phone, GB_consent)
                    VALUES 
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $GB_date,
                    $room_no,
                    $GB_start_time,
                    $GB_end_time,
                    $GB_num_guests,
                    $GB_preferred_hand,
                    $GB_name,
                    $GB_email,
                    $GB_phone,
                    $GB_consent
                ]);
            }

            header("Location: ". $_SERVER['PHP_SELF'] . "?success=true");
            exit();
        } 
    }


    function generate_time_slots($start = "09:00", $end = "21:00", $interval = 30) {
        $slots = [];
        $startTime = strtotime($start);
        $endTime = strtotime($end);

        while ($startTime <= $endTime) {
            $slots[] = date("H:i", $startTime);
            $startTime += $interval * 60;
        }

        return $slots;
    }

?>


<?php
// Temporary default date
$today = date("Y-m-d");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sportech Indoor Golf | Book</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        td { height: 35px; }
        .past-slot{ background:#6c757d!important; color:#fff!important; text-align:center; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
            
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-outline-secondary" onclick="prevDate()">&laquo;</button>
                <!-- date picker -->
                <input type="date" id="date-picker" class="form-control"
                min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+8 weeks')) ?>"
                value="<?= isset($_GET['date']) ? htmlspecialchars($_GET['date']) : date('Y-m-d') ?>" />
                <button class="btn btn-outline-secondary" onclick="nextDate()">&raquo;</button>
            </div>
            <div>
                <a href="https://sportechgolf.com/" target="_blank">
                    <img src="./images/logo.png" alt="Sportech Logo" style="width: 350px; height: 60px;" />
                </a>
            </div>
            <!-- Right side Buttons -->
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#priceModal">Price</button>
                <button type="button" class="btn btn-primary" data-bs-toggle="offcanvas" data-bs-target="#bookingCanvas">Book</button>
            </div>
        </div>
    </div>

    <div class="container mb-5">
        <table class="table table-bordered text-center align-middle" style="table-layout: fixed; border-color: #adb5bd;">
            <colgroup>
                <col style="width: 8%;"> <!-- Time Column -->
                <col style="width: 19%;"> <!-- Room Column -->
                <col style="width: 20%;">
                <col style="width: 19%;">
                <col style="width: 19%;">
                <col style="width: 19%;">
            </colgroup>
            <thead class="table-light">
                <tr>
                    <th>Time</th>
                    <th>Private #1</th>
                    <th>Private #2 (Right-handed)</th>
                    <th>VIP #3</th>
                    <th>Public #4</th>
                    <th>Public #5</th>
                </tr>
            </thead>
            <tbody>
                <?php
                    $time_slots = generate_time_slots("09:00", "21:30");

                    foreach ($time_slots as $i => $time) {
                        $isHourStart = substr($time, -2) === "00";
                        if ($isHourStart) {
                            $hourLabel = substr($time, 0, 2) . ":00";
                            echo "<tr><td rowspan='2' class='align-middle fw-bold'>$hourLabel</td>";
                        } else {
                            echo "<tr>";
                        }
                    

                        for ($room = 1; $room <= 5; $room++) {
                            $cls = $text = "";

                            if (
                                ($room === 4 && ($time === '09:00' || $time === '21:30')) ||
                                ($room === 5 && ($time === '09:00' || $time === '21:30'))
                            ) {
                                $cls = 'class="bg-secondary text-white text-center"';
                                $text = 'X';
                            }

                            if ($time === '09:30') {
                                $text = '<span class="text-muted small">09:30</span>';
                            }

                            echo "<td $cls class='time-slot' data-time='{$time}' data-room='{$room}'>$text</td>";
                        }
                        echo "</tr>";
                    }
                ?>

            </tbody>
        </table>
    </div>
    <div class="offcanvas offcanvas-end" style="width: 600px;" tabindex="-1" id="bookingCanvas" aria-labelledby="bookingCanvasLabel">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title text-center w-100 m-0 fs-2" id="bookingCanvasLabel">Book a Screen Room</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
                <form id="bookingForm" method="POST">
                    <div class="mb-3 d-flex align-items-center">
                        <label for="booking-date" class="form-label me-2 mb-0 fw-semibold">Date:</label>
                        <span id="form-selected-date"></span>
                        <input type="hidden" name="GB_date" id="GB_date">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Room:</label>
                        <div class="d-flex gap-2">
                            <input type="checkbox" class="btn-check" name="GB_room_no[]" id="room1" value="1" autocomplete="off">
                            <label class="btn btn-outline-primary flex-fill room-btn" for="room1">Private #1</label>

                            <input type="checkbox" class="btn-check" name="GB_room_no[]" id="room2" value="2" autocomplete="off">
                            <label class="btn btn-outline-primary flex-fill room-btn" for="room2">Private #2</label>

                            <input type="checkbox" class="btn-check" name="GB_room_no[]" id="room3" value="3" autocomplete="off">
                            <label class="btn btn-outline-primary flex-fill room-btn" for="room3">VIP #3</label>

                            <input type="checkbox" class="btn-check" name="GB_room_no[]" id="room4" value="4" autocomplete="off">
                            <label class="btn btn-outline-primary flex-fill room-btn" for="room4">Public #4</label>

                            <input type="checkbox" class="btn-check" name="GB_room_no[]" id="room5" value="5" autocomplete="off">
                            <label class="btn btn-outline-primary flex-fill room-btn" for="room5">Public #5</label>
                        </div>
                        <div id="rightHandedNotice" class="text-danger small mt-2 d-none text-center">
                            The Private room #2 is for RIGHT-HANDED players ONLY!!!
                        </div>   
                        <div id="roomError" class="text-danger small mt-2" style="display:none;">Please, Select the room.</div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-6">
                            <label for="startTime" class="form-label fw-semibold">Start Time:</label>
                            <select id="startTime" name="GB_start_time" class="form-select">
                            <option disabled selected>Select start time</option>    
                            <?php foreach (generate_time_slots("09:00", "21:00") as $time): ?>
                                    <option value="<?= $time ?>"><?= $time ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div id="timeError" class="invalid-feedback">Please, Select the start time.</div>
                        </div>

                        <div class="col-6">
                            <label for="endTime" class="form-label fw-semibold">End Time:</label>
                            <select id="endTime" name="GB_end_time" class="form-select">
                                <option disabled selected>Select start time first</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Number of Guests:</label>
                            <input type="text" id="guests" name="GB_num_guests" class="form-control" placeholder="Enter the number of guests" />
                            <div id="guestsError" class="invalid-feedback">Enter the number of people.</div>
                        </div>

                        <div class="col-6">
                            <label class="form-label fw-semibold">Preferred Hand for Play:</label>
                            <select id="handedness" name="GB_preferred_hand" class="form-select">
                                <option disabled selected>Select Hand Preference</option>
                                <option value="right">Right-handed</option>
                                <option value="left">Left-handed</option>
                                <option value="both">Both</option>
                            </select>
                            <div id="handError" class="invalid-feedback">Please, Select your preferred hand.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="name" class="form-label fw-semibold">Name:</label>
                        <input type="text" id="name" name="GB_name" class="form-control" placeholder="Enter your name" />
                        <div id="nameError" class="invalid-feedback">Please, Use English or Korean.</div>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label fw-semibold">Email:</label>
                        <input type="email" id="email" name="GB_email" class="form-control" placeholder="Enter your email address" />
                        <div id="emailError" class="invalid-feedback">Please, Enter valid email.</div>
                    </div>

                    <div class="mb-3">
                        <label for="phone" class="form-label fw-semibold">Phone number:</label>
                        <input type="text" id="phone" name="GB_phone" class="form-control" placeholder="Enter your phone number (ex.1234567890)" />
                        <div id="phoneError" class="invalid-feedback">Please, Use only numbers.</div>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="consentCheckbox" name="GB_consent" required>
                        <label class="form-check-label small" for="consentCheckbox">
                            “I agree to the collection and use of my personal information for the purpose of reservation, contact, and customer management. The information will not be shared with third parties.”
                        </label>
                        <div id="consentError" class="text-danger small mt-1" style="display:none;">Please, Check the box.</div>
                    </div>

                    <div class="mt-4">
                        <h5 class="mb-3 fs-2 text-center text-danger">Important Notes</h5>
                        <ul class="small">
                            <li>Club rentals are available!</li>
                            <li>If you would like to book in 30-minute increments, please contact us via sportechgolf@gmail.com or 403-455-4951!</li>
                            <li>Each slot below represents one hour! (Only the start time will appear in confirmation)</li>
                            <li>Management holds no liability for any injuries or incidents inside the facility.</li>
                            <li>You will be charged based on reserved time!</li>
                        </ul>
                    </div>

                    <div class="d-flex justify-content-center gap-3 mt-4">
                        <button type="submit" class="btn btn-primary px-4 fs-5" style="width: 150px;">Book</button>
                        <button type="button" class="btn btn-secondary px-4 fs-5" style="width: 150px;" data-bs-dismiss="offcanvas">Cancel</button>
                    </div>

                </form>
                
        </div>
    </div>

    <!-- 프라이스 모달 -->
    <div class="modal fade" id="priceModal" tabindex="-1" aria-labelledby="priceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="priceModalLabel">Price Table</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <img src="./images/price_table.png" alt="price table" class="img-fluid rounded shadow" />
            </div>
            </div>
        </div>
    </div>

    <script>
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        const maxDate = new Date(today);
        maxDate.setDate(today.getDate() + 56);
        maxDate.setHours(0, 0, 0, 0);

        const datePicker = document.getElementById('date-picker');
        const bookingDateInput = document.getElementById('GB_date');
        const formDateDisplay = document.getElementById('form-selected-date');
        
        const selectedRooms = Array.from(document.querySelectorAll('input[name="GB_room_no[]"]:checked')).map(cb => cb.value);
        const notice = document.getElementById('rightHandedNotice');
        const roomCheckboxes = document.querySelectorAll('input[name="GB_room_no[]"]');

        const startSelect = document.getElementById('startTime');
        const endSelect = document.getElementById('endTime');
        const allTimes = <?= json_encode(generate_time_slots("09:00", "22:00")); ?>;

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
            
            console.log("▶️ selectedDate:", selectedDate.toString());
            console.log("▶️ today:", today.toString());

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
            fetch(`get_reserved_times.php?date=${date}&room=${room}`)
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

        function markReservedTimes(reservedTimes, room) {
            
            // 예약된 시간 칠하기
            reservedTimes.forEach(item => {
                let current = item.start_time;
                while (current < item.end_time) {
                    const slot = document.querySelector(`.time-slot[data-time='${current}'][data-room='${item.room_no}']`);
                    if (slot) {
                        slot.classList.add("bg-danger", "text-white");
                        slot.innerText = "X";
                    }
                    current = add30Minutes(current); // 이 함수는 아래에 설명
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

            form.addEventListener("submit", function (e) {
                e.preventDefault();

                if (!validDateForm()) return;

                const formData = new FormData(form);

                selectedRooms.forEach(room => {
                formData.append("GB_room_no[]", room);
                });

                const date = formData.get("GB_date");
                const startTime = formData.get("GB_start_time");

                for (const room of selectedRooms) {
                    const res = fetch(`get_reserved_times.php?date=${date}&room=${room}`);
                    const reservedTimes = res.json();

                    if (reservedTimes.includes(startTime)) {
                        alert(`Room ${room} is already booked at ${startTime}. Please choose another time.`);
                        return;
                    }
                }   

                fetch("customer_booknow.php", {
                    method: "POST",
                    body: formData
                })
                .then(res => res.text())
                .then(result => {
                    
                    for (let i = 1; i <= 5; i++) {
                        fetchReservedTimes(date, i);
                    }

                    alert("Reservation complete!");

                    const offcanvasEl = document.getElementById("bookingCanvas");
                    const bsOffcanvas = bootstrap.Offcanvas.getInstance(offcanvasEl);
                    if (bsOffcanvas) bsOffcanvas.hide();

                })
                .catch(error => {
                    alert("Reservation failed");
                    console.error("Failed to book", error);
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


    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>

<?php
    include("footer.php");
?>