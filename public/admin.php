<?php
session_start();
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header("Location: includes/admin_login.php");  // ✅ 정확한 상대경로로 수정
    exit;
}
?>

<?php
/* 관리자 페이지 */

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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    
    <link rel="stylesheet" href="./assets/index.css">

    <style>
        td { height: 35px; }
        .past-slot{ background:#6c757d!important; color:#fff!important; text-align:center; }
    </style>
</head>
<body class="admin-mode">
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
            
            <div class="d-flex align-items-center gap-1">
                <button id="prevDateBtn" class="btn btn-outline-secondary">&laquo;</button>
                <!-- date picker -->
                <input type="text" id="date-picker" class="flat-date form-control text-center fw-bold" 
                min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+4 weeks')) ?>"
                value="<?= isset($_GET['date']) ? htmlspecialchars($_GET['date']) : date('Y-m-d') ?>" />
                <button id="nextDateBtn" class="btn btn-outline-secondary">&raquo;</button>
            </div>
            <div>
                <a href="https://sportechgolf.com/" target="_blank">
                    <img src="./images/logo.png" alt="Sportech Logo" style="width: 350px; height: 60px;" />
                </a>
            </div>
            <!-- Right side Buttons -->
            <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary" data-bs-toggle="offcanvas" data-bs-target="#adminSettings" aria-label="Admin Settings">&#9776;</button>
            </div>
        </div>
    </div>

    <div class="container-fluid mb-5">
        <div class="table-responsive">
            <table class="table table-bordered text-center align-middle" style="table-layout: fixed; border-color: #adb5bd;">
                <colgroup>
                    <col style="width: 16.66%;">
                    <col style="width: 16.66%;">
                    <col style="width: 16.66%;">
                    <col style="width: 16.66%;">
                    <col style="width: 16.66%;">
                    <col style="width: 16.66%;">
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
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="email" class="form-label fw-semibold">Email:</label>
                            <input type="email" id="email" name="GB_email" class="form-control" placeholder="Enter your email address" />
                        </div>
                    </div>

                    <!-- Phone -->
                    <div class="row mb-3">
                        <div class="col-12 col-md-6 mb-2 mb-md-0">
                            <label for="phone" class="form-label fw-semibold">Phone number:</label>
                            <div class="d-flex flex-column flex-md-row gap-2">
                                <input type="text" id="phone" name="GB_phone" class="form-control" placeholder="ex. 1234567890">
                            </div>
                        </div>
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
                    <img id="priceTableImg" src="./images/price_table.png" alt="price table" class="img-fluid rounded shadow" />
                </div>
                <div class="modal-footer">
                    <button id="editPriceBtn" class="btn btn-secondary d-none">Edit Image</button>
                    <input type="file" id="priceImageInput" accept="image/*" class="form-control d-none mt-2">
                    <button id="savePriceBtn" class="btn btn-primary d-none mt-2">Save</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 예약 상세 모달 -->
    <div class="modal fade" id="reservationDetailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reservation Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <p><strong>Name:</strong> <span id="resvName"></span></p>
                    <p><strong>Email:</strong> <span id="resvEmail"></span></p>
                    <p><strong>Phone:</strong> <span id="resvPhone"></span></p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" id="deleteReservationBtn">Delete</button>
                    <button type="button" class="btn btn-primary" id="editReservationBtn">Edit</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 관리자 설정 패널 (오른쪽 슬라이드) -->
    <div class="offcanvas offcanvas-end" style="width: 500px;" tabindex="-1" id="adminSettings">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title">Settings</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">

            <div id="adminMainList">
                <ul class="list-group">
                    <li class="list-group-item" role="button" onclick="showBusinessHours()">
                        <strong>🕒 Business Hours</strong><br>
                        <small class="text-muted">Set start and end time</small>
                    </li>
                    <li class="list-group-item" role="button" data-bs-toggle="modal" data-bs-target="#priceModal">
                        <strong>🖼 Price Table</strong><br>
                        <small class="text-muted">Edit price table image</small>
                    </li>
                    <li class="list-group-item">
                        <strong>📢 Notices</strong><br>
                        <small class="text-muted">Update public announcement</small>
                    </li>
                </ul>
            </div>

            <div class="d-none" id="businessHoursForm">
                
                <div class="mt-4" id="businessHoursTableArea">
                    <div class="mt-4 d-flex justify-content-between align-items-center">
                        <button class="btn btn-outline-secondary mb-3" onclick="backToAdminList()">← Back</button>
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-clock"></i>
                            <h6 class="fw-bold mb-3">🕒 Business Hours</h6>
                        </div>
                        <button class="btn btn-primary mb-3" id="saveBusinessHoursBtn">Save</button>
                    </div>
                    <table class="table table-bordered align-middle text-center">
                        <thead>
                        <tr>
                            <th>Day</th>
                            <th>Open</th>
                            <th>Close</th>
                            <th>Closed</th>
                        </tr>
                        </thead>
                        <tbody>
                        <!-- 요일별 행 반복 -->
                        <?php
                            $days = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];
                            foreach ($days as $key => $label):
                        ?>
                        <tr>
                            <td><?= $label ?></td>
                            <td><input type="time" class="open-time" name="<?= $key ?>_open" data-day="<?= $key ?>"></td>
                            <td><input type="time" class="close-time" name="<?= $key ?>_close" data-day="<?= $key ?>"></td>
                            <td><input type="checkbox" name="<?= $key ?>_closed" class="closed-checkbox" data-day="<?= $key ?>"></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
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

    <script>
        window.IS_ADMIN = <?= isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true ? 'true' : 'false' ?>;
    </script>
    <!-- ② 메인 로직 -->
    <script src="assets/share.js" defer></script>
    <script src="assets/admin.js" defer></script>
    <?php include __DIR__.'/includes/footer.php'; ?>
</body>
</html>

