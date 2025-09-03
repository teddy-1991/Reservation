<?php
session_start();
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header("Location: includes/admin_login.php");
    exit;
}

date_default_timezone_set('America/Edmonton');
// ✅ 오늘 날짜를 쿼리로 붙여서 강제 이동
if (!isset($_GET['date'])) {
    $today = date("Y-m-d");
    header("Location: " . $_SERVER['PHP_SELF'] . "?date=$today");
    exit;
}


require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/functions.php';
$date = $_GET['date'];
$businessHours = fetch_business_hours_for_php($pdo, $date);

$open  = $businessHours['open_time']  ?? null;
$close = $businessHours['close_time'] ?? null;
$clientIp = get_client_ip(); 

// ✅ 닫힘 판정: DB 플래그 or 00:00~00:00
$closed = (!empty($businessHours['is_closed']) || !empty($businessHours['closed'])
           || ($open === '00:00' && $close === '00:00'));

$timeSlots = $closed ? [] : generate_time_slots($open, $close);
?>

<?php
/* 관리자 페이지 */

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
                    (GB_date, GB_room_no, GB_start_time, GB_end_time, GB_name, GB_email, GB_phone, GB_consent, GB_ip)
                    VALUES 
                    (?, ?, ?, ?, ?, ?, ?, ?, ?)";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $GB_date,
                    $room_no,
                    $GB_start_time,
                    $GB_end_time,
                    $GB_name,
                    $GB_email,
                    $GB_phone,
                    $GB_consent,
                    $clientIp
                ]);
            }

            header("Location: ". $_SERVER['PHP_SELF'] . "?success=true");
            exit();
        } 
    }

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
    <script>
        window.IS_ADMIN = <?= isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true ? 'true' : 'false' ?>;
        window.ALL_TIMES = <?= json_encode(generate_time_slots($open, date("H:i", strtotime($close) + 1800))); ?>;
    </script>
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>

    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <link rel="stylesheet" href="./assets/index.css">

    <style>
        td { height: 35px; }
        .past-slot{ background:#6c757d!important; color:#fff!important; text-align:center; }
    </style>
</head>
<body class="admin-mode">
    <div class="container-fluid mt-4 header-container">
        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">

            <div class="d-flex align-items-center gap-1">
                <button id="prevDateBtn" class="btn btn-outline-secondary">&laquo;</button>
                <!-- date picker -->
                <input type="text" id="date-picker" class="flat-date form-control text-center fw-bold" 
                min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+4 weeks')) ?>"
                value="<?= isset($_GET['date']) ? htmlspecialchars($_GET['date']) : date('Y-m-d') ?>" />
                <button id="nextDateBtn" class="btn btn-outline-secondary">&raquo;</button>
            </div>
            <div class="logo-area text-center">
                <a href="https://sportechgolf.com/" target="_blank">
                    <img src="./images/logo.png" alt="Sportech Logo" class="img-fluid site-logo" />
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
                    <?php if (empty($timeSlots)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-danger fw-bold py-4">
                        We're closed on this day.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($timeSlots as $i => $time): ?>
                        <?php
                        $isHourStart = substr($time, -2) === "00";
                        $hourLabel = substr($time, 0, 2) . ":00";

                        if ($isHourStart) {
                            echo "<tr><td rowspan='2' class='align-middle fw-bold'>{$hourLabel}</td>";
                        } else {
                            echo "<tr>";
                        }

                        foreach (range(1, 5) as $room) {
                            $classes = ['time-slot'];
                            // ✅ 제한 없이 출력, 클릭도 가능
                            $classAttr = implode(' ', $classes);
                            echo "<td class='{$classAttr}' data-time='{$time}' data-room='{$room}'></td>";
                        }

                        echo "</tr>";
                        ?>
                    <?php endforeach; ?>
                    <?php endif; ?>
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

                        <!-- ✅ 관리자용 보이는 날짜 입력(달력 붙일 대상) -->
                        <input type="text"
                                id="adm_date"
                                class="form-control form-control-sm me-2"
                                placeholder="Select date"
                                autocomplete="off"
                                required
                                style="max-width: 160px;">

                        <!-- 제출용 hidden (서버로 넘어가는 값) -->
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
                            <?php foreach ($timeSlots as $time): ?>
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
                        <button id="reserveBtn" type="button" class="btn btn-primary px-4 fs-5" style="width: 150px;">Reserve</button>
                        <button id="updateBtn" type="button" class="btn btn-warning d-none" style="width: 150px;">Update</button>
                        <button type="button" class="btn btn-secondary px-4 fs-5" style="width: 150px;" data-bs-dismiss="offcanvas">Cancel</button>
                    </div>
                    <input type="hidden" id="GB_id" name="GB_id" value="">      
                    <input type="hidden" name="Group_id" id="Group_id">
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
                    <hr class="my-3">

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-2"><strong>Customer Note</strong></h6>
                        <button type="button" class="btn btn-sm btn-warning" id="openNoteEditorBtn">
                            Edit
                        </button>
                    </div>
                    <div id="customerNoteBox" class="border rounded p-2 bg-light small">
                        <span id="customerNoteSpinner" class="d-none">Loading…</span>
                        <span id="customerNoteText" class="text-muted">—</span>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" id="deleteReservationBtn">Delete</button>
                    <button type="button" class="btn btn-primary" id="editReservationBtn">Edit</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 관리자 설정 패널 (오른쪽 슬라이드) -->
    <div class="offcanvas offcanvas-end" style="width: 600px;" tabindex="-1" id="adminSettings">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title">Settings</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <div id="adminMainList">
                <ul class="list-group">
                    <li class="list-group-item" role="button" onclick="showBusinessHours()">
                        <strong>Business Hours</strong><br>
                        <small class="text-muted">Set start and end time</small>
                    </li>
                    <li class="list-group-item" role="button" data-bs-toggle="modal" data-bs-target="#priceModal">
                        <strong>Price Table</strong><br>
                        <small class="text-muted">Edit price table image</small>
                    </li>
                    <li class="list-group-item" role="button" data-bs-toggle="modal" data-bs-target="#menuModal">
                    <strong>Menu</strong><br>
                    <small class="text-muted">Upload menu images</small>
                    </li>
                    <li class="list-group-item" role="button" onclick="showNoticeEditor()">
                        <strong>Notices</strong><br>
                        <small class="text-muted">Update public announcement</small>
                    </li>
                    <li class="list-group-item" role="button" onclick="openCustomerSearchModal()">
                    <strong>Search Customer</strong><br>
                    <small class="text-muted">Find customer by name, phone, or email</small>
                    </li>
                    <li class="list-group-item" role="button" onclick="openWeeklyOverviewModal()">
                    <strong>Weekly Overview</strong><br>
                    <small class="text-muted">View weekly reservation</small>
                    </li>
                </ul>
            </div>

            <form id="businessHoursForm" class="mt-4 d-none">
            <!-- Weekly Business Hours 헤더 + Save 버튼 -->
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="backToAdminList()">← Back</button>
            <hr>
            <div class="d-flex justify-content-between align-items-center mt-4 mb-3">
                <h5 class="fw-bold mb-0">📅 Weekly Business Hours</h5>
                <button id="saveWeeklyBtn" class="btn btn-primary btn-sm">Save</button>
            </div>

            <!-- 시간 입력 폼 -->
            <div id="weeklyHoursContainer">
            <?php
            $days = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];

            foreach ($days as $key => $label):
                $open   = $weeklyHours[$key]['open_time'] ?? '';
                $close  = $weeklyHours[$key]['close_time'] ?? '';
                $closed = $weeklyHours[$key]['is_closed'] ?? 0;
            ?>
            <div class="row align-items-center mb-2">
                <div class="col-1 fw-semibold"><?= $label ?></div>
                <div class="col-4">
                <input type="time" step="3600" class="form-control form-control-sm" id="<?= $key ?>_open" name="<?= $key ?>_open" value="<?= $open ?>" <?= $closed ? 'disabled' : '' ?>>
                </div>
                <div class="col-1 text-center">~</div>
                <div class="col-4">
                <input type="time" step="3600" class="form-control form-control-sm" id="<?= $key ?>_close" name="<?= $key ?>_close" value="<?= $close ?>" <?= $closed ? 'disabled' : '' ?>>
                </div>
                <div class="col-2">
                <div class="form-check">
                    <input class="form-check-input closed-checkbox" type="checkbox" id="<?= $key ?>_closed" name="<?= $key ?>_closed" <?= $closed ? 'checked' : '' ?>>
                    <label class="form-check-label" for="<?= $key ?>_closed">Closed</label>
                </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>

            <hr>

            <!-- Special Business Hours Section -->
            <div class="mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">📅 Special Hours for Specific Date</h5>
                <button type="submit" class="btn btn-warning btn-sm" id="saveSpecialBtn">Save</button>
            </div>

            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                <label for="special_date" class="form-label">Date</label>
                <input type="date" id="special_date" name="special_date" class="form-control">
                </div>
                <div class="col-md-4">
                <label for="special_open" class="form-label">Open Time</label>
                <input type="time" id="special_open" name="special_open" class="form-control">
                </div>
                <div class="col-md-4">
                <label for="special_close" class="form-label">Close Time</label>
                <input type="time" id="special_close" name="special_close" class="form-control">
                </div>
            </div>
            </div>
        </form>
        <!-- 📢 공지사항 에디터 (처음엔 숨김) -->
        <form id="noticeEditorForm" class="mt-4 d-none">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="backToAdminList()">← Back</button>
            <hr>
            <h5 class="fw-bold mb-2">📢 Important Notice Editor</h5>
            <div id="editor-container" style="height: 300px; background: #fff;"></div>
            <button type="submit" class="btn btn-primary mt-3" id="saveNoticeBtn">Save</button>
        </form>
        </div>
    </div>
    
    <!-- 고객 검색 모달 -->
    <div class="modal fade" id="customerSearchModal" tabindex="-1" aria-hidden="true" style="background-color: rgba(0, 0, 0, 0.5);">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title">Search Customer</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body" style="height: 75vh; overflow-y: auto;">
            <!-- 🔍 검색 입력 -->
            <div class="row g-2 mb-3">
                <div class="col">
                    <input type="text" class="form-control" id="searchName" placeholder="Name">
                </div>
                <div class="col">
                    <input type="text" class="form-control" id="searchPhone" placeholder="Phone">
                </div>
                <div class="col">
                    <input type="text" class="form-control" id="searchEmail" placeholder="Email">
                </div>
                <div class="col-auto">
                    <button class="btn btn-primary" onclick="searchCustomer()">Search</button>
                    <button type="button" class="btn btn-primary" id="showAllCustomersBtn">Show All</button>
                </div>
            </div>

            <!-- 📋 검색 결과 테이블 -->
            <div class="table-responsive">
            <table class="table table-bordered align-middle text-center" id="customerResultTable">
                <colgroup>
                    <col style="width: 17%;">
                    <col style="width: 13%;">
                    <col style="width: 20%;">
                    <col style="width: 6%;">
                    <col style="width: 6%;">
                    <col style="width: 25%;">
                    <col style="width: 10%;">
                </colgroup>
                <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Visit Count</th>
                    <th>Usage Time</th>
                    <th>Memo</th>
                    <th>IP</th>
                </tr>
                </thead>
                <tbody>
                <!-- JS로 결과 삽입 예정 -->
                </tbody>
            </table>
            </div>
        </div>
        </div>
    </div>
    </div>

    <!-- Customer Memo Modal -->
    <div class="modal fade" id="memoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Customer Memo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div id="memoWho" class="small text-muted mb-2"></div>
                <textarea id="memoText" class="form-control" rows="6" placeholder="메모 입력..."></textarea>

                <!-- 선택된 고객 키 보관용 -->
                <input type="hidden" id="memoName">
                <input type="hidden" id="memoPhone">
                <input type="hidden" id="memoEmail">
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button id="saveMemoBtn" type="button" class="btn btn-primary">Save</button>
            </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="weeklyOverviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen-lg-down modal-xl">
            <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Weekly Overview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <!-- 왼쪽: Prev/Range/Next 묶음 -->
                    <div class="d-flex align-items-center gap-2 weekly-toolbar">
                        <button type="button" id="weeklyPrevBtn" class="btn btn-outline-secondary btn-sm">‹ Prev</button>
                        <span id="weeklyRangeLabel" class="fw-semibold text-nowrap"></span>
                        <button type="button" id="weeklyNextBtn" class="btn btn-outline-secondary btn-sm">Next ›</button>
                    </div>

                    <!-- 오른쪽: 설명 -->
                    <div class="text-muted small">
                        열=요일, 행=시간 (값: 예약된 룸 수 / 전체)
                    </div>
                    </div>

                    <div id="weeklyGrid" class="weekly-grid"></div>
                    <div id="weekly-overview-counts" class="mt-3"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Menu Images Modal -->
    <div class="modal fade" id="menuModal" tabindex="-1" aria-labelledby="menuModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
            <div class="modal-header">
                <h5 id="menuModalLabel" class="modal-title">Menu Images</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <!-- 안내 -->
                <div class="alert alert-secondary small">
                <div>• 슬롯은 <b>3개 고정</b>입니다. 업로드 시 동일 이름으로 <b>덮어쓰기</b>됩니다.</div>
                <div>• 1~2개만 올리면 올린 개수만 노출됩니다.</div>
                <div>• 권장 포맷: JPG/PNG/WEBP, 긴 변 1600px 내외</div>
                </div>

                <!-- 3 슬롯 카드 -->
                <div class="row g-3" id="menuSlotCards">
                <!-- Slot 1 -->
                <div class="col-md-4">
                    <div class="card h-100 shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span class="fw-bold">Slot 1</span>
                        <span id="menu1Status" class="badge bg-secondary">No image</span>
                    </div>
                    <div class="card-body">
                        <div class="ratio ratio-4x3 mb-3">
                        <img id="menu1Preview" alt="menu_1 preview" class="rounded border" style="object-fit:cover; width:100%; height:100%;">
                        </div>
                        <input class="form-control mb-2" type="file" accept=".jpg,.jpeg,.png,.webp" id="menu1File">
                        <div class="d-flex justify-content-center gap-2">
                        <button class="btn btn-primary" id="menu1UploadBtn">Upload</button>
                        <button class="btn btn-outline-danger" id="menu1DeleteBtn">Delete</button>
                        </div>
                    </div>
                    </div>
                </div>
                <!-- Slot 2 -->
                <div class="col-md-4">
                    <div class="card h-100 shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span class="fw-bold">Slot 2</span>
                        <span id="menu2Status" class="badge bg-secondary">No image</span>
                    </div>
                    <div class="card-body">
                        <div class="ratio ratio-4x3 mb-3">
                        <img id="menu2Preview" alt="menu_2 preview" class="rounded border" style="object-fit:cover; width:100%; height:100%;">
                        </div>
                        <input class="form-control mb-2" type="file" accept=".jpg,.jpeg,.png,.webp" id="menu2File">
                        <div class="d-flex justify-content-center gap-2">
                        <button class="btn btn-primary" id="menu2UploadBtn">Upload</button>
                        <button class="btn btn-outline-danger" id="menu2DeleteBtn">Delete</button>
                        </div>
                    </div>
                    </div>
                </div>
                <!-- Slot 3 -->
                <div class="col-md-4">
                    <div class="card h-100 shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span class="fw-bold">Slot 3</span>
                        <span id="menu3Status" class="badge bg-secondary">No image</span>
                    </div>
                    <div class="card-body">
                        <div class="ratio ratio-4x3 mb-3">
                        <img id="menu3Preview" alt="menu_3 preview" class="rounded border" style="object-fit:cover; width:100%; height:100%;">
                        </div>
                        <input class="form-control mb-2" type="file" accept=".jpg,.jpeg,.png,.webp" id="menu3File">
                        <div class="d-flex justify-content-center gap-2">
                        <button class="btn btn-primary" id="menu3UploadBtn">Upload</button>
                        <button class="btn btn-outline-danger" id="menu3DeleteBtn">Delete</button>
                        </div>
                    </div>
                    </div>
                </div>
                </div>
                <!-- /3 slots -->
            </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap bundle (필수) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- ② 메인 로직 -->
    <script src="assets/share.js" defer></script>
    <script src="assets/admin.js" defer></script>
    <?php include __DIR__.'/includes/footer.php'; ?>
</body>
</html>

