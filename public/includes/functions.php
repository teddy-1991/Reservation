<?php

require_once __DIR__ . '/config.php';

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
    // 기준 날짜를 임의로 고정
    $base = '1970-01-01 ';
    $start = new DateTime($base . $start_time);
    $end   = new DateTime($base . $end_time);

    // 종료가 자정(00:00)이거나 시작보다 같거나 이르면 → 다음날로 해석
    if ($end <= $start) {
        $end->modify('+1 day');
    }

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
use PHPMailer\PHPMailer\SMTP;


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
            $mail->Host       = $_ENV['MAIL_HOST'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $_ENV['MAIL_USERNAME'];
            $mail->Password   = $_ENV['MAIL_PASSWORD'];
            $mail->SMTPSecure = 'tls';
            $mail->Port       = $_ENV['MAIL_PORT'];
            $mail->CharSet  = 'UTF-8';                         // 문자셋
            $mail->Encoding = PHPMailer::ENCODING_BASE64;      // 인코딩 명시

            // 보내는 사람 & 받는 사람
            $mail->setFrom($_ENV['MAIL_USERNAME'], $_ENV['MAIL_FROM_NAME']);
            $mail->addAddress($toEmail, $toName);
            // 관리자 메일로 예약 내용 받기
            // $mail->addAddress('email address', 'name');

            
            // 메일 내용
            $mail->isHTML(true);
            $mail->Subject = "Sportech Indoor Golf Reservation Confirmation";
            $mail->Body = "
            Hello, <strong>{$toName}</strong><br><br>
            Thank you for booking with Sportech Indoor Golf.<br>
            We look forward to welcoming you on time for your reservation.<br>
            If you need to cancel or make any changes, please contact us by phone (403-455-4952) or email (sportechgolf@gmail.com).<br><br>
            
            <hr>
            <h3>Reservation Details</h3>
            <p><strong>Date:</strong> {$date}</p>
            <p><strong>Room:</strong> {$roomNo}</p>
            <p><strong>Time:</strong> {$startTime} ~ {$endTime}</p><br>
            <hr>

            Before your visit, please review the important notice below:<br>
            <h4 style='color:#d9534f;'>Important Notice</h4>
            <div style='font-size: 14px; color: #333;'>{$noticeHtml}</div>

            <br>
            Thank you again for choosing Sportech Indoor Golf.<br>
            We look forward to seeing you soon!<br><br>

            Best regards,<br>
            Sportech Indoor Golf<br>
            Phone: 403-455-4952<br>
            Email: sportechgolf@gmail.com
            ";
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Failed to send an email: {$mail->ErrorInfo}");
            return false;
        }
    }