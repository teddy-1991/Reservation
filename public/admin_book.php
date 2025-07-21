<?php
/* 관리자 페이지 */

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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <link rel="stylesheet" href="./assets/index.css">

    <style>
        td { height: 35px; }
        .past-slot{ background:#6c757d!important; color:#fff!important; text-align:center; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
            
            <div class="d-flex align-items-center gap-1">
                <button class="btn btn-outline-secondary" onclick="prevDate()">&laquo;</button>
                <!-- date picker -->
                <input type="text" id="date-picker" class="flat-date form-control text-center fw-bold" style="width: 150px;"
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
    <script src="assets/admin.js" defer></script>
    <?php include __DIR__.'/includes/footer.php'; ?>
</body>
</html>

