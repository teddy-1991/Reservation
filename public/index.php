<?php

    require_once __DIR__.'/includes/config.php'; // $pdo
    require_once __DIR__.'/includes/functions.php'; // generate_time_slots()


    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $GB_date = $_POST['GB_date'] ?? null;
        $GB_room_no = $_POST['GB_room_no'] ?? [];
        $GB_start_time = $_POST['GB_start_time'] ?? null;
        $GB_end_time = $_POST['GB_end_time'] ?? null;
        $GB_name = $_POST['GB_name'] ?? null;
        $GB_email = $_POST['GB_email'] ?? null;
        $GB_phone = $_POST['GB_phone'] ?? null;
        $GB_consent = ($_POST["GB_consent"] ?? '') === "on" ? 1 : 0;


         // 유효성 검사 (예: 필수값 확인)
        if ($GB_date && !empty($GB_room_no) && $GB_start_time && $GB_end_time && $GB_name && $GB_email && $GB_phone && $GB_consent) {
            foreach ($GB_room_no as $room_no) {
                $sql = "INSERT INTO gb_reservation 
                    (GB_date, GB_room_no, GB_start_time, GB_end_time, GB_name, GB_email, GB_phone, GB_consent)
                    VALUES 
                    (?, ?, ?, ?, ?, ?, ?, ?)";

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
    // ✅ 여기가 1단계 작업 위치
    $date = $_GET['date'] ?? date("Y-m-d");

    $businessHours = fetch_business_hours_for_php($pdo, $date);
    $open = $businessHours['open_time'];
    $close = $businessHours['close_time'];

    $timeSlots = generate_time_slots($open, $close);
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <link rel="stylesheet" href="./assets/index.css">

    <style>
        td { height: 35px; }
        .past-slot{ background:#6c757d!important; color:#fff!important; text-align:center; }
    </style>
</head>
<body>
<main>
    <div class="container-fluid mt-4 header-container">

        <div class="booking-header row justify-content-between align-items-center mb-4">
            <!-- 날짜 선택 (모바일은 아래쪽, 데스크탑은 왼쪽) -->
            <div class="col-auto d-flex align-items-center gap-2 date-selector">
                <button  id= "prevDateBtn" class="btn btn-outline-secondary">&laquo;</button>
                <input type="text" id="date-picker" class="flat-date form-control text-center fw-bold"
                min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+4 weeks')) ?>"
                value="<?= isset($_GET['date']) ? htmlspecialchars($_GET['date']) : date('Y-m-d') ?>" />
                <button id="nextDateBtn" class="btn btn-outline-secondary">&raquo;</button>
            </div>

              <!-- 로고: 가운데 -->
            <div class="col-auto text-center logo-area">
                <a href="https://sportechgolf.com/" target="_blank">
                <img src="./images/logo.png" alt="Sportech Logo" />
                </a>
            </div>

            <!-- 버튼들: 오른쪽 -->
            <div class="col-auto d-flex align-items-center gap-2 button-group">
                <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#priceModal">Price</button>
                <button class="btn btn-primary" data-bs-toggle="offcanvas" data-bs-target="#bookingCanvas">Book</button>
            </div>

        </div>
    </div>


    <div class="container-fluid mb-5">
        <div class="table-responsive">
            <table class="table table-bordered text-center align-middle" style="table-layout: fixed; border-color: #adb5bd;">
                <colgroup>
                    <col style="width: 15%;">
                    <col style="width: 16.99%;">
                    <col style="width: 16.99%;">
                    <col style="width: 16.99%;">
                    <col style="width: 16.99%;">
                    <col style="width: 16.99%;">
                </colgroup>
                <thead class="table-light align-middle">
                    <tr>
                        <th>Time</th>
                        <th>Private #1</th>
                        <th>Private #2<br>(Right-handed)</th>
                        <th>VIP #3</th>
                        <th>Public #4</th>
                        <th>Public #5</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $time_slots = $timeSlots;

                foreach ($time_slots as $i => $time) {
                    $isHourStart = substr($time, -2) === "00";
                    if ($isHourStart) {
                        $hourLabel = substr($time, 0, 2) . ":00";
                        echo "<tr><td rowspan='2' class='align-middle fw-bold'>$hourLabel</td>";
                    } else {
                        echo "<tr>";
                    }

                    for ($room = 1; $room <= 5; $room++) {
                        $classes = ['time-slot'];
                        $text = '';

                        // 룸별 오픈/클로즈 시간 조정
                        $roomOpen = ($room >= 4)
                            ? date("H:i", strtotime($open) + (30 * 60))  // open + 30분
                            : $open;

                        $roomClose = ($room >= 4)
                            ? date("H:i", strtotime($close) - (30 * 60))  // close - 30분
                            : $close;

                        // 현재 슬롯의 시작/끝 시간
                        $slotStart = strtotime($time);
                        $slotEnd = $slotStart + (30 * 60);

                        // 오픈 전이거나 마감 이후면 색칠
                        if ($slotStart < strtotime($roomOpen) || $slotEnd > strtotime($roomClose)) {
                            $classes[] = 'bg-secondary';
                            $classes[] = 'text-white';
                            $classes[] = 'text-center';
                            $classes[] = 'pe-none'; // 클릭 방지도 포함 시
                        }

                        $classAttr = implode(' ', $classes);
                        echo "<td class='{$classAttr}' data-time='{$time}' data-room='{$room}'>{$text}</td>";
                    }
                    echo "</tr>";
                }
                ?>


                </tbody>
            </table>
        </div>
    </div>
    <div class="offcanvas offcanvas-end" style="width: 600px;" tabindex="-1" id="bookingCanvas" aria-labelledby="bookingCanvasLabel">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title text-center w-100 m-0 fs-2" id="bookingCanvasLabel">Booking Details</h5>
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
                            <?php foreach ($timeSlots as $time): ?>
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

                    <!-- Name + Email -->
                    <div class="row mb-3">
                        <div class="col-12 col-md-6 mb-2 mb-md-0">
                            <label for="name" class="form-label fw-semibold">Name:</label>
                            <input type="text" id="name" name="GB_name" class="form-control" placeholder="Enter your name" />
                            <div id="nameError" class="invalid-feedback">Please, Use English or Korean.</div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="email" class="form-label fw-semibold">Email:</label>
                            <input type="email" id="email" name="GB_email" class="form-control" placeholder="Enter your email address" />
                            <div id="emailError" class="invalid-feedback">Please, Enter valid email.</div>
                        </div>
                    </div>

                    <!-- Phone + OTP -->
                    <div class="row mb-3">
                        <div class="col-12 col-md-6 mb-2 mb-md-0">
                            <label for="phone" class="form-label fw-semibold">Phone number:</label>
                            <div class="d-flex flex-column flex-md-row gap-2">
                            <input type="text" id="phone" name="GB_phone" class="form-control" placeholder="ex. 1234567890">
                            <button type="button" class="btn btn-success" onclick="sendOTP()">Send</button>
                            </div>
                            <div id="phoneError" class="invalid-feedback">Please, use only numbers.</div>
                            <input type="hidden" id="isVerified" name="isVerified" value="0">
                        </div>

                        <div class="col-12 col-md-6 d-none" id="otpSection">
                            <label for="otpCode" class="form-label fw-semibold">Verification Code:</label>
                            <div class="d-flex flex-column flex-md-row gap-2">
                            <input type="text" id="otpCode" class="form-control" placeholder="Code">
                            <button type="button" class="btn btn-success" onclick="verifyOTP()">Verify</button>
                            </div>
                            <div id="otpError" class="invalid-feedback d-none">Invalid code.</div>
                        </div>
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
                        <button type="submit" class="btn btn-primary px-4 fs-5" style="width: 150px;">Reserve</button>
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
                <img src="./images/price_table.png" id="priceTableImg" alt="price table" class="img-fluid rounded shadow" />
            </div>
        </div>
    </div>

    <!-- Bootstrap bundle (필수) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- ① PHP가 계산해 주는 30-분 타임슬롯 배열 전역 노출 -->


    <script>
    window.ALL_TIMES = <?= json_encode(generate_time_slots($open, date("H:i", strtotime($close) + 1800))); ?>;
    </script>


    <!-- ② 메인 로직 -->
    <script src="assets/share.js" defer></script>
    <script src="assets/booking.js" defer></script>
</main>
    <?php include __DIR__.'/includes/footer.php'; ?>
</body>
</html>

