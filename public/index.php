<?php

    require_once __DIR__.'/includes/config.php'; // $pdo
    require_once __DIR__.'/includes/functions.php'; // generate_time_slots()


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

    
    <!-- Bootstrap bundle (필수) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- ① PHP가 계산해 주는 30-분 타임슬롯 배열 전역 노출 -->
    <script>
       window.ALL_TIMES =
    <?php echo json_encode(generate_time_slots("09:00", "22:00")); ?>;
    </script>

    <!-- ② 메인 로직 -->
    <script src="assets/booking.js" defer></script>
    <?php include __DIR__.'/includes/footer.php'; ?>
</body>
</html>

