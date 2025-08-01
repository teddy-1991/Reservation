
<?php
function fetch_business_hours_for_php($pdo, $date) {
    $weekday = strtolower(date('D', strtotime($date)));

    $stmt = $pdo->prepare("
        SELECT open_time, close_time
        FROM business_hours
        WHERE :date BETWEEN start_date AND end_date
          AND weekday = :weekday
        ORDER BY DATEDIFF(end_date, start_date) ASC, start_date DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':date' => $date,
        ':weekday' => $weekday
    ]);

    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: ['open_time' => '09:00', 'close_time' => '21:00'];
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

<?php foreach ($timeSlots as $slot): ?>
  <div class="time-slot"><?= htmlspecialchars($slot) ?></div>
<?php endforeach; ?>

<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

    function sendReservationEmail ($toEmail, $toName, $date, $startTime, $endTime, $roomNo) {
        require_once __DIR__ . '/PHPMailer/Exception.php';
        require_once __DIR__ . '/PHPMailer/PHPMailer.php';
        require_once __DIR__ . '/PHPMailer/SMTP.php';

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
            Your reservation is completed as below.<br><br>
            <ul>
                <li><strong>Room: </strong>{$roomNo}</li>
                <li><strong>Date: </strong>{$date}</li>
                <li><strong>Time: </strong>{$startTime} - {$endTime}</li>
            </ul>
            <br> Thank you! <br>
            - Sportech Indoor Golf
            ";

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Failed to send an email: {$mail->ErrorInfo}");
            return false;
        }
    }
?>