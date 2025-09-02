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
/**
 * 절대 URL 생성
 * - .env나 config.php에서 BASE_URL 설정을 최우선 사용
 * - 없으면 서버 변수를 기반으로 host/protocol 판단
 */
function build_absolute_url(string $path): string {
    // .env만 우선 사용 (상수 미사용)
    $base = $_ENV['BASE_URL'] ?? $_SERVER['BASE_URL'] ?? '';
    if ($base) return rtrim($base, '/') . $path;

    // 2) Fallback: 서버 환경에서 판단 (IONOS 프록시 포함)
    $host   = $_SERVER['HTTP_X_FORWARDED_HOST'] 
           ?? $_SERVER['HTTP_HOST'] 
           ?? 'localhost';
    $proto  = $_SERVER['HTTP_X_FORWARDED_PROTO'] 
           ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $port   = $_SERVER['HTTP_X_FORWARDED_PORT'] 
           ?? $_SERVER['SERVER_PORT'] 
           ?? null;

    // 표준포트는 생략
    $needPort = $port && !in_array((string)$port, ['80','443'], true);
    $portStr  = $needPort ? (':' . $port) : '';

    return $proto . '://' . $host . $portStr . $path;
}

/**
 * 예약 수정 토큰 생성
 * - $target: ['reservation_id' => int]  또는  ['group_id' => int] (둘 중 하나만)
 * - $startDateTime: 'YYYY-MM-DD HH:MM:SS' (예약 시작 일시)
 * - $maxUses: 0=만료까지 무제한, 1=1회용(운영 권장)
 * 반환: 토큰 문자열 (base64url)
 */
function create_edit_token(PDO $pdo, array $target, string $startDateTime, int $maxUses = 1): string
{
    // base64url 토큰 생성
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

    // 만료시간 = 예약 시작 - 24시간
    $expiresAt = (new DateTimeImmutable($startDateTime))
        ->sub(new DateInterval('P1D')) // 1 day
        ->format('Y-m-d H:i:s');

    $reservationId = $target['reservation_id'] ?? null;
    $groupId       = $target['group_id'] ?? null;

    // 생성자 IP (프록시 헤더 고려)
    $ip = inet_pton(
        $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '127.0.0.1'
    );

    $stmt = $pdo->prepare("
        INSERT INTO reservation_tokens
            (token, reservation_id, group_id, action, expires_at, max_uses, created_ip)
        VALUES
            (:token, :reservation_id, :group_id, 'edit', :expires_at, :max_uses, :created_ip)
    ");
    $stmt->execute([
        ':token'          => $token,
        ':reservation_id' => $reservationId,
        ':group_id'       => $groupId,
        ':expires_at'     => $expiresAt,
        ':max_uses'       => $maxUses,
        ':created_ip'     => $ip,
    ]);

    return $token;
}
/**
 * 고객용 예약 수정 링크 만들기
 * - $token: create_edit_token()으로 만든 토큰
 * - 기본 경로는 /reservation/edit.php (원하면 파일명 변경 가능)
 */
function build_edit_link(string $token, string $path = '/reservation/edit.php'): string {
    $qs = '?token=' . urlencode($token);
    return build_absolute_url($path . $qs);
}

?>
<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;


    function sendReservationEmail ($toEmail, $toName, $date, $startTime, $endTime, $roomNo, $subjectOverride = null, $introHtml = '') {
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

            // 제목 오버라이드(이미 넣어둔 로직 유지 권장)
            if ($subjectOverride !== null && trim($subjectOverride) !== '') {
                $mail->Subject = $subjectOverride;
            } else {
                $mail->Subject = "Sportech Indoor Golf Reservation Confirmation";
            }

            // 상단 인트로 파트 (reason 있는 재발송이면 introHtml 사용, 없으면 기본 문구)
            $introHtmlClean = trim((string)$introHtml);
            $hasUpdateIntro = ($introHtmlClean !== '');

            if ($hasUpdateIntro) {
                // 재발송(업데이트/이동)용 상단 인트로
                $introPart = "
                    Hello, <strong>{$toName}</strong><br><br>
                    ".nl2br($introHtmlClean)."<br>
                ";
            } else {
                // 기본(새 예약) 상단 인트로
                $introPart = "
                    Hello, <strong>{$toName}</strong><br><br>
                    Thank you for booking with Sportech Indoor Golf.<br>
                    We look forward to welcoming you on time for your reservation.<br>
                    If you need to cancel or make any changes, please contact us by phone (403-455-4951) or email (sportechgolf@gmail.com).<br><br>
                ";
            }

            // 공통 본문(예약 디테일~Notice~푸터) — 항상 동일하게 유지
            $commonPart = "
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

            // 최종 Body: 상단 인트로 + 구분선 + 공통 파트
            $mail->Body = $introPart . "<hr>" . $commonPart;

            $mail->send();
            
            return true;

        } catch (Exception $e) {
            $err = "PHPMailer Error: ".$mail->ErrorInfo;
            error_log($err);
            return false;
        }
    }