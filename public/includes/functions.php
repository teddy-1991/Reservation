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
        FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
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

// 메일에 넣을 self-service 페이지 링크 생성
function build_edit_link(string $token, string $path = '/includes/customer_edit.php'): string {
    $qs = '?token=' . urlencode($token);
    return build_absolute_url($path . $qs);
}

// 대상(group_id 또는 reservation_id)으로 최신 토큰 1개 조회
function get_active_edit_token(PDO $pdo, array $target): ?array {
    $byGroup = isset($target['group_id']) && $target['group_id'] !== '';
    $where   = $byGroup ? 'group_id = :gid' : 'reservation_id = :rid';
    $stmt = $pdo->prepare("
        SELECT *
          FROM reservation_tokens
         WHERE {$where} AND action='edit'
         ORDER BY id DESC
         LIMIT 1
    ");
    $stmt->execute([
        ':gid' => $byGroup ? (string)$target['group_id'] : null,
        ':rid' => $byGroup ? null : (int)$target['reservation_id']
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * 그룹ID 기준 self-service 토큰을 upsert한다.
 * - PK: (group_id, action)
 * - token 은 매번 새로 갱신한다 (기존 링크 무효화)
 * 반환: ['token' => '...', 'expires_at' => 'YYYY-mm-dd HH:ii:ss']
 */
function upsert_edit_token_for_group(PDO $pdo, string $groupId, \DateTimeInterface $expiresAt, string $action = 'edit'): array
{
    // 토큰 생성 (32자 hex)
    $token = bin2hex(random_bytes(16));
    $expires = $expiresAt->format('Y-m-d H:i:s');

    // ⚠️ 플레이스홀더 이름을 INSERT/UPDATE에서 "각각" 명시해서 HY093 방지
    $sql = "
        INSERT INTO reservation_tokens (group_id, action, token, expires_at)
        VALUES (:group_id_i, :action_i, :token_i, :expires_i)
        ON DUPLICATE KEY UPDATE
            token      = :token_u,
            expires_at = :expires_u
    ";

    $stmt = $pdo->prepare($sql);
    $ok = $stmt->execute([
        // INSERT용
        ':group_id_i' => $groupId,
        ':action_i'   => $action,
        ':token_i'    => $token,
        ':expires_i'  => $expires,
        // UPDATE용
        ':token_u'    => $token,
        ':expires_u'  => $expires,
    ]);

    if (!$ok) {
        throw new RuntimeException('Token upsert failed');
    }
    return ['token' => $token, 'expires_at' => $expires];
}


function build_selfservice_block(PDO $pdo, array $tokenTarget, string $startDateTimeYmdHis): string
{
    // 24h 규칙 판단
    $start = new DateTime($startDateTimeYmdHis);
    $now   = new DateTime('now');
    $limit = (clone $start)->modify('-24 hours');

    if ($now >= $limit) {
        // 24시간 미만: 온라인 수정 불가-전화안내 블록
        return '<hr><p style="margin-top:16px"><strong>Within 24 hours:</strong> Online changes are unavailable. Please call 403-455-4951 and we will assist you.</p>';
    }

    // 24시간 초과: 토큰 생성/업서트
    $groupId = (string)($tokenTarget['group_id'] ?? '');
    if ($groupId === '') {
        throw new InvalidArgumentException('Missing group_id for self-service token');
    }

    // 만료 시간은 시작 24시간 전까지로(필요시 조정)
    $expiresAt = $limit;
    $up = upsert_edit_token_for_group($pdo, $groupId, $expiresAt, 'edit');

    // URL 구성
    $base = rtrim($_ENV['BASE_URL'] ?? 'https://sportechindoorgolf.com/booking', '/');
    $url  = $base . '/includes/customer_edit.php?token=' . urlencode($up['token']);

    // 블록 HTML
    $expireStr = $expiresAt->format('Y-m-d H:i');
    return <<<HTML
  <hr>
  <h4>Edit or Cancel your reservation</h4>
  <p>Online changes are available up to 24 hours before your start time. Use the link below to make updates.<br>
    <a href="{$url}">Open self-service link</a> (Link valid until: {$expireStr})</p>
  <p>We are not Haters, but we really dislike 'No Shows". <br>
    If your plans change, a quick heads-up helps us offer the spot to another golfer.  <br>
    Please call 403-455-4951 and email booking@sportechindoorgolf.com. We will assist you. Thank you!
  </p>
HTML;
}

function validate_edit_token(PDO $pdo, string $token): array {
    $token = trim((string)$token);
    if ($token === '') {
        return ['ok' => false, 'code' => 'invalid', 'group_id' => null, 'expires_at' => null];
    }

    // 1) 토큰 조회 (action='edit' 고정)
    $stmt = $pdo->prepare("
        SELECT id, token, reservation_id, group_id, action, expires_at
          FROM reservation_tokens
         WHERE token = :t AND action = 'edit'
         LIMIT 1
    ");
    $stmt->execute([':t' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'code' => 'not_found', 'group_id' => null, 'expires_at' => null];
    }

    // 2) 만료 확인 (만료되면 실패)
    $expiresAt = $row['expires_at'] ?? null;
    if ($expiresAt !== null) {
        $now = new DateTimeImmutable('now');
        if ($now >= new DateTimeImmutable($expiresAt)) {
            return ['ok' => false, 'code' => 'expired', 'group_id' => ($row['group_id'] ?? null), 'expires_at' => $expiresAt];
        }
    }

    // 3) group_id 확보 (없으면 reservation_id → group_id 역조회)
    $groupId = $row['group_id'] ?? null;
    if (!$groupId && !empty($row['reservation_id'])) {
        $g = $pdo->prepare("SELECT Group_id FROM GB_Reservation WHERE GB_id = :rid LIMIT 1");
        $g->execute([':rid' => (int)$row['reservation_id']]);
        $groupId = (string)($g->fetchColumn() ?: '');
    }
    if ($groupId === null || $groupId === '') {
        return ['ok' => false, 'code' => 'no_group', 'group_id' => null, 'expires_at' => $expiresAt];
    }

    return ['ok' => true, 'code' => 'ok', 'group_id' => $groupId, 'expires_at' => $expiresAt];
}

?>
<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;


function sendReservationEmail ($toEmail, $toName, $date, $startTime, $endTime, $roomNo, $subjectOverride = null, $introHtml = '', array $tokenTarget = null) {
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

        // ✅ 취소 메일 여부 플래그 (subject 또는 tokenTarget['canceled']로 판단)
        $isCanceled = (
            (isset($tokenTarget['canceled']) && $tokenTarget['canceled']) ||
            (stripos($subjectOverride ?? '', 'canceled') !== false)
        );

        // 제목 설정
        if ($isCanceled) {
            $mail->Subject = "Your reservation has been canceled";
        } elseif ($subjectOverride !== null && trim($subjectOverride) !== '') {
            $mail->Subject = $subjectOverride;
        } else {
            $mail->Subject = "Sportech Indoor Golf Reservation Confirmation";
        }

        // 로고(footer에서 cid 참조)
        $logoPath = __DIR__ . '/../images/logo.png';
        if (file_exists($logoPath)) {
            $mail->addEmbeddedImage($logoPath, 'logoCID');  // cid:logoCID
        }
        $footerPart = '
        <div style="text-align:center; margin:16px 0 0;">
            <div>SPORTECH INDOOR GOLF (SIMULATOR)</div>
            <img src="cid:logoCID"
                 alt="SPORTECH INDOOR GOLF"
                 width="280"
                 style="width:280px; height:auto; display:block; margin:0 auto 8px;">
            <div style="font-size:14px; line-height:1.4; color:#333;">
              <div>#120 1642 10th Avenue SW, Calgary, AB T3C0J5</div>
              <div>TEL. 403-455-4951</div>
              <div><a href="https://sportechgolf.com" style="color:#0d6efd; text-decoration:underline;">sportechgolf.com</a></div>
            </div>
        </div>';

        // ✅ 취소 메일 전용: 공지/셀프서비스 없이 간단 요약만 보내고 종료
        if ($isCanceled) {
            $introText = $introHtml && trim($introHtml) !== ''
                ? nl2br(trim($introHtml))
                : "Your reservation was canceled as requested.";

            $bodyHtml = "
                Hello, <strong>{$toName}</strong><br><br>
                {$introText}<br><br>
                <h3>Reservation (Canceled)</h3>
                <p><strong>Date:</strong> {$date}</p>
                <p><strong>Room:</strong> {$roomNo}</p>
                <p><strong>Time:</strong> {$startTime} ~ {$endTime}</p>
                <hr>
                {$footerPart}
            ";

            $mail->Body = $bodyHtml;

            $mail->send();
            return true;
        }

        // ====== 취소가 아닌 경우(신규/업데이트) ======

        // 제목/인트로
        $introHtmlClean = trim((string)$introHtml);
        $hasUpdateIntro = ($introHtmlClean !== '');
        if ($hasUpdateIntro) {
            $introPart = "
                Hello, <strong>{$toName}</strong><br><br>
                ".nl2br($introHtmlClean)."<br>
            ";
        } else {
            $introPart = "
                Hello, <strong>{$toName}</strong><br><br>
                Thank you for booking with Sportech Indoor Golf.<br>
                We look forward to welcoming you on time for your reservation.
            ";
        }

        // 공통 본문
        $commonPart = "
            <h3>Reservation Details</h3>
            <p><strong>Date:</strong> {$date}</p>
            <p><strong>Room:</strong> {$roomNo}</p>
            <p><strong>Time:</strong> {$startTime} ~ {$endTime}</p>
            <hr>
            Before your visit, please review the important notice below:<br>
            <h4 style='color:#d9534f;'>Important Notice</h4>
            <div style='font-size: 14px; color: #333;'>{$noticeHtml}</div>
        ";

        $tokenPart = '';
        if ($tokenTarget && !empty($tokenTarget)) {
            global $pdo;
            $startDateTime = $date . ' ' . substr($startTime, 0, 5) . ':00';
            $tokenPart = build_selfservice_block($pdo, $tokenTarget, $startDateTime) ?: '';
        }

        // 최종 본문 조립
        $mail->Body = $introPart . "<hr>" . $commonPart . $tokenPart . "<hr>" . $footerPart;

        $mail->send();
        return true;

    } catch (Exception $e) {
        $err = "PHPMailer Error: ".$mail->ErrorInfo;
        error_log($err);
        return false;
    }
}
