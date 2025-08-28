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
/**
 * Extract the best-guess client IP.
 * - Prefers Cloudflare header, then X-Forwarded-For (left-most public), then X-Real-IP, then REMOTE_ADDR
 * - Filters out private/reserved ranges
 */
function get_client_ip(): string {
    $server = $_SERVER;

    // 1) Cloudflare
    if (!empty($server['HTTP_CF_CONNECTING_IP']) && is_public_ip($server['HTTP_CF_CONNECTING_IP'])) {
        return $server['HTTP_CF_CONNECTING_IP'];
    }

    // 2) X-Forwarded-For: comma-separated; take first public
    if (!empty($server['HTTP_X_FORWARDED_FOR'])) {
        $parts = array_map('trim', explode(',', $server['HTTP_X_FORWARDED_FOR']));
        foreach ($parts as $ip) {
            if (is_public_ip($ip)) return $ip;
        }
    }

    // 3) X-Real-IP
    if (!empty($server['HTTP_X_REAL_IP']) && is_public_ip($server['HTTP_X_REAL_IP'])) {
        return $server['HTTP_X_REAL_IP'];
    }

    // 4) Fallback
    $fallback = $server['REMOTE_ADDR'] ?? '';
    return $fallback ?: '0.0.0.0';
}

/** Validate public IPv4/IPv6 (exclude private/reserved) */
function is_public_ip(string $ip): bool {
    if ($ip === '') return false;
    return (bool) filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
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

            // SMTP 기본 설정 (IONOS)
            $mail->isSMTP();
            $mail->Host       = $_ENV['MAIL_HOST'];           // smtp.ionos.com
            $mail->SMTPAuth   = true;
            $mail->Username   = trim($_ENV['MAIL_USERNAME'] ?? '');
            $mail->Password   = trim($_ENV['MAIL_PASSWORD'] ?? '');
            $mail->Port       = (int)($_ENV['MAIL_PORT'] ?? 465);
            // 465 → SMTPS(implicit SSL), 587 → STARTTLS
            $mail->SMTPSecure = ($mail->Port === 465)
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->AuthType   = 'LOGIN'; // IONOS가 AUTH LOGIN 지원

            $mail->CharSet    = 'UTF-8';
            $mail->Encoding   = PHPMailer::ENCODING_BASE64;

            // 보내는 사람 & 받는 사람 (Return-Path까지 정렬)
            $fromEmail = $_ENV['MAIL_FROM'] ?: $_ENV['MAIL_USERNAME'];
            $fromName  = $_ENV['MAIL_FROM_NAME'] ?? '';
            $mail->setFrom($fromEmail, $fromName);
            $mail->Sender = $fromEmail;               // Return-Path
            $mail->addAddress($toEmail, $toName);
            // 관리자 메일로 예약 내용 받기
            $mail->addAddress('booking@sportechindoorgolf.com', $fromName);

            
            // 메일 내용
            $mail->isHTML(true);
            $mail->Subject = "Sportech Indoor Golf Reservation Confirmation";
            $mail->Body = "
            Hello, <strong>{$toName}</strong><br><br>
            Thank you for booking with Sportech Indoor Golf.<br>
            We look forward to welcoming you on time for your reservation.<br>
            If you need to cancel or make any changes, please contact us by phone (403-455-4951) or email (sportechgolf@gmail.com).<br><br>
            
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
            Phone: 403-455-4951<br>
            Email: sportechgolf@gmail.com
            ";
            $mail->send();
            
            return true;

        } catch (Exception $e) {
            $err = "PHPMailer Error: ".$mail->ErrorInfo;
            error_log($err);
            return false;
        }
    }