<?php
function generate_time_slots($start="09:00",$end="21:00",$interval=30){
    $slots=[]; $cur=strtotime($start); $endT=strtotime($end);
    while($cur<=$endT){ $slots[]=date("H:i",$cur); $cur+=$interval*60; }
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
                <li><strong>Date: </strong>{$date}</li>
                <li><strong>Time: </strong>{$startTime} - {$endTime}</li>
                <li><strong>Room: </strong>{$roomNo}</li>
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