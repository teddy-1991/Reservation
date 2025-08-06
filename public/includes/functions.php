
<?php
function fetch_business_hours_for_php($pdo, $date) {
    $weekday = strtolower(date('D', strtotime($date)));

    // 우선 스페셜부터 확인
    $stmt = $pdo->prepare("SELECT open_time, close_time FROM business_hours_special WHERE date = :date");
    $stmt->execute([':date' => $date]);
    $special = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($special) {
        return [
            'open_time' => $special['open_time'],
            'close_time' => $special['close_time'],
            'closed' => false  // 스페셜은 closed 체크박스 없음
        ];
    }

    // weekly 조회 (closed 포함)
    $stmt = $pdo->prepare("SELECT open_time, close_time, is_closed FROM business_hours_weekly WHERE weekday = :weekday");
    $stmt->execute([':weekday' => $weekday]);
    $weekly = $stmt->fetch(PDO::FETCH_ASSOC);

    return $weekly ?: [
        'open_time' => '09:00:00',
        'close_time' => '21:00:00',
        'closed' => false
    ];
}

function generate_time_slots($start_time, $end_time, $interval = '30 mins') {
    $start = new DateTime($start_time);
    $end = new DateTime($end_time);
    $slots = [];

    while ($start < $end) {
        $slots[] = $start->format('H:i');
        $start->modify($interval);
    }

    return $slots;
}
?>

<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

    function sendReservationEmail ($toEmail, $toName, $date, $startTime, $endTime, $roomNo) {
        require_once __DIR__ . '/PHPMailer/Exception.php';
        require_once __DIR__ . '/PHPMailer/PHPMailer.php';
        require_once __DIR__ . '/PHPMailer/SMTP.php';
        $noticePath = __DIR__ . '/../data/notice.html';
        $noticeHtml = file_exists($noticePath) ? file_get_contents($noticePath) : '';
        $mail = new PHPMailer(true);

        try {
            // SMTP 기본 설정
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'booking.sportech@gmail.com';
            $mail->Password = 'trmj pwpb asmx gpwb';
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;

            // 보내는 사람 & 받는 사람
            $mail->setFrom('booking.sportech@gmail.com', 'reservation');
            $mail->addAddress($toEmail, $toName);
            // 관리자 메일로 예약 내용 받기
            // $mail->addAddress('email address', 'name');

            

            // 메일 내용
            $mail->isHTML(true);
            $mail->Subject = "Sportech Indoor Golf Reservation Confirmation";
            $mail->Body = "
            Hello, <strong>{$toName}</strong>!!<br><br>
            Thank you for your reservation at Sportech golf.<br>
            We look forward to your arrival on time.<br>
            If you need to cancel or modify your reservation, please don't hesitate to contact us by phone (403-455-4952) or email (sportechgolf@gmail.com).<br><br>
            <h3>Reservation Details</h3>
            <p><strong>Date:</strong> {$date}</p>
            <p><strong>Room:</strong> {$roomNo}</p>
            <p><strong>Time:</strong> {$startTime} ~ {$endTime}</p>
            <hr>
            Before you arrive, please take a moment to review our important notice below:<br>
            <h4 style='color:#d9534f;'>Important Notice</h4>
            <div style='font-size: 14px; color: #333;'>{$noticeHtml}</div>
            <br> Thank you for choosing Sportech Indoor Golf!<br>
            Regards, <br>
            Sportech Indoor Golf
            ";
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Failed to send an email: {$mail->ErrorInfo}");
            return false;
        }
    }
?>