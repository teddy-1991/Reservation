<?php
// /public/api/create_reservation.php  (POST 전용)
declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=utf-8');


require_once __DIR__ . '/../includes/config.php';     // $pdo
require_once __DIR__ . '/../includes/functions.php';  // sendReservationEmail()

function norm_name($s){ $s = preg_replace('/\s+/u',' ', trim((string)$s)); return $s === '' ? null : $s; }
function norm_email($s){ $s = trim((string)$s); return $s === '' ? null : strtolower($s); }
function norm_phone($s){ $s = trim((string)$s); return $s === '' ? null : $s; }

// 1) POST 데이터 수집
$date      = $_POST['GB_date']       ?? null;
$rooms     = $_POST['GB_room_no']    ?? [];   // name="GB_room_no[]" → 배열
$startTime = $_POST['GB_start_time'] ?? null;
$endTime   = $_POST['GB_end_time']   ?? null;
$name      = $_POST['GB_name']       ?? null;
$email     = $_POST['GB_email']      ?? null;
$phone     = $_POST['GB_phone']      ?? null;
$consent   = isset($_POST['GB_consent']) ? 1 : 0;

// rooms 정규화
if (!is_array($rooms)) $rooms = [$rooms];
$rooms = array_values(array_filter(array_unique(array_map('intval', $rooms)), fn($v) => $v > 0));

// 2) 1차 검증 (필수값)
if (!$date || empty($rooms) || !$startTime || !$endTime || !$name || !$email) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'missing', 'fields' => [
        'date' => (bool)$date, 'rooms' => !empty($rooms), 'start' => (bool)$startTime,
        'end' => (bool)$endTime, 'name' => (bool)$name, 'email' => (bool)$email
    ]]);
    exit;
}

// 3) 시간 검증: 같은 날 예약만 허용 + 종료 00:00은 24:00으로 간주
$startTime = substr((string)$startTime, 0, 5);
$endTime   = substr((string)$endTime,   0, 5);

[$sh, $sm] = array_map('intval', explode(':', $startTime));
[$eh, $em] = array_map('intval', explode(':', $endTime));
$startMin  = $sh * 60 + $sm;

// 종료가 00:00이면 24:00으로 (단, 시작도 00:00이면 0분이라 금지)
if ($endTime === '00:00') {
    $endMin = ($startMin > 0) ? 1440 : 0;
} else {
    $endMin = $eh * 60 + $em;
}

// 같은 날 규칙: 종료가 시작보다 커야 함
if ($endMin <= $startMin) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'invalid_time_range']);
    exit;
}

// 초 단위(겹침 체크에 사용)
$sSec = $startMin * 60;
$eSec = $endMin   * 60;

try {
    $groupId  = uniqid('', true); // 그룹 ID
    $clientIp = get_client_ip();

    // (관리자 예외 제거) 이번 요청이 추가할 건수
    $numNew = (is_array($rooms) && count($rooms) > 0) ? count($rooms) : 1;

    if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
        // 최근 5분 내 동일 IP 건수
        $rlSQL = "SELECT COUNT(*)
                  FROM GB_Reservation
                  WHERE GB_ip = :ip
                    AND GB_created_at >= (NOW() - INTERVAL 5 MINUTE)";
        $rlStmt = $pdo->prepare($rlSQL);
        $rlStmt->execute([':ip' => $clientIp]);
        $recentCnt = (int)$rlStmt->fetchColumn();

        // 이번 요청까지 합쳐서 5건 이상이면 차단
        if (($recentCnt + $numNew) >= 5) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'error'   => 'rate_limited',
                'message' => 'Too many reservations from the same IP. Please call 403-455-4951 or email sportechgolf@gmail.com.'
            ]);
            exit;
        }
    }

    $pdo->beginTransaction();

    /* 정규화: update_info.php와 동일 규칙 */
    $normName  = norm_name($name);   // 다중 공백 → 1칸, 공백만이면 null
    $normEmail = norm_email($email); // 소문자, 공백/빈문자면 null
    $normPhone = norm_phone($phone); // 앞뒤 공백 제거, 빈문자면 null

    // NEW: 고객 찾기 (이름+이메일+전화 "정확 일치"만 같은 사람으로 간주; NULL-safe <=>)
    $findSql = "
        SELECT id
          FROM customers_info
         WHERE full_name <=> :name
           AND email     <=> :email
           AND phone     <=> :phone
         LIMIT 1
    ";
    $findStmt = $pdo->prepare($findSql);
    $findStmt->execute([
        ':name'  => $normName,
        ':email' => $normEmail,
        ':phone' => $normPhone,
    ]);
    $customerId = $findStmt->fetchColumn();

    // NEW: 없으면 customers_info에 새로 생성
    if (!$customerId) {
        $insCust = "
            INSERT INTO customers_info (full_name, email, phone, created_at, updated_at)
            VALUES (:name, :email, :phone, NOW(), NOW())
        ";
        $st = $pdo->prepare($insCust);
        $st->execute([
            ':name'  => $normName,
            ':email' => $normEmail,
            ':phone' => $normPhone,
        ]);
        $customerId = (int)$pdo->lastInsertId();
    } else {
        $customerId = (int)$customerId;
    }

    // ※ 문자열 비교 대신 "초 단위" 비교: DB의 HH:MM은 TIME_TO_SEC로 변환
    $checkSQL = "
        SELECT 1
          FROM GB_Reservation
         WHERE GB_date = :d
           AND GB_room_no = :room
           AND NOT (
                 (CASE WHEN GB_end_time   = '00:00' THEN 86400 ELSE TIME_TO_SEC(CONCAT(GB_end_time,   ':00')) END) <= :s
             OR  (CASE WHEN GB_start_time = '00:00' THEN 0     ELSE TIME_TO_SEC(CONCAT(GB_start_time, ':00')) END) >= :e
           )
         LIMIT 1
    ";

    // NEW: customer_id 컬럼 추가
    $insertSQL = "
        INSERT INTO GB_Reservation
            (customer_id, GB_date, GB_room_no, GB_start_time, GB_end_time,
             GB_name, GB_email, GB_phone, GB_consent, Group_id, GB_ip)
        VALUES
            (:customer_id, :d, :room, :s_time, :e_time,
             :name, :email, :phone, :consent, :gid, :ip)
    ";

    $chkStmt = $pdo->prepare($checkSQL);
    $insStmt = $pdo->prepare($insertSQL);

    foreach ($rooms as $room) {
        // 겹침 체크 (초 단위 비교)
        $chkStmt->execute([
            ':d'    => $date,
            ':room' => $room,
            ':s'    => $sSec,
            ':e'    => $eSec,
        ]);
        if ($chkStmt->fetch()) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error'   => 'conflict',
                'message' => "Room {$room} already booked in that time range"
            ]);
            exit;
        }

        // 삽입 (스냅샷은 그대로 저장, FK만 추가)
        $insStmt->execute([
            ':customer_id' => $customerId,     // NEW
            ':d'           => $date,
            ':room'        => $room,
            ':s_time'      => $startTime,
            ':e_time'      => $endTime,
            ':name'        => $name,
            ':email'       => $email,
            ':phone'       => $phone,
            ':consent'     => $consent,
            ':gid'         => $groupId,
            ':ip'          => $clientIp
        ]);
    }

    $pdo->commit();

    // 5) 메일 발송 (실패해도 예약은 성공)
    $subject    = 'Your Sportech Indoor Golf Reservation';
    $roomList   = implode(',', $rooms);
    $mailStatus = true;
    try {
        sendReservationEmail(
            $email, $name, $date,
            substr($startTime, 0, 5),
            substr($endTime,   0, 5),
            $roomList,
            $subject,
            '',
            ['group_id' => (string)$groupId]
        );
    } catch (Throwable $mailEx) {
        $mailStatus = false;
        $mailError  = $mailEx->getMessage();
        error_log('[create_reservation:mail] ' . $mailEx->getMessage());
    }

    // (선택) 토큰 디버그
    $token_debug = [];
    try {
        $stmt = $pdo->prepare("
            SELECT action, expires_at, used_at
            FROM reservation_tokens
            WHERE group_id = :g
            ORDER BY id DESC
            LIMIT 5
        ");
        $stmt->execute([':g' => (string)$groupId]);
        $token_debug = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $token_debug = [['error' => $e->getMessage()]];
    }

    echo json_encode([
        'success'     => true,
        'group_id'    => $groupId,
        'customer_id' => $customerId,       // NEW: 응답에 포함 (프론트에서 확인 용)
        'mail'        => $mailStatus,
        'mail_error'  => $mailError ?? null,
        'token_debug' => $token_debug
    ]);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[create_reservation] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server', 'details' => $e->getMessage()]);
    exit;
}
