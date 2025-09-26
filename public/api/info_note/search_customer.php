<?php
// /api/search_customer.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

/* 1) 모든 출력 가로채기 + 페이탈/경고를 JSON으로 */
ob_start();
set_error_handler(function($sev,$msg,$file,$line){
    throw new ErrorException($msg, 0, $sev, $file, $line);
});
register_shutdown_function(function(){
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) {
        http_response_code(500);
        $junk = ob_get_contents();
        while (ob_get_level() > 0) ob_end_clean();
        echo json_encode(['ok'=>false,'error'=>'fatal','detail'=>$e['message'],'junk'=>$junk], JSON_UNESCAPED_UNICODE);
    }
});

try {
    require_once __DIR__ . '/../../includes/config.php';
    session_start();

    if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
        http_response_code(401);
        while (ob_get_level() > 0) ob_end_clean();
        echo json_encode(['ok'=>false,'error'=>'unauthorized']);
        exit;
    }

    // ✅ 입력값
    $name  = $_GET['name']  ?? '';
    $phone = $_GET['phone'] ?? '';
    $email = $_GET['email'] ?? '';
    $all   = (isset($_GET['all']) && $_GET['all'] === '1');

    if (!$name && !$phone && !$email && !$all) {
        http_response_code(400);
        while (ob_get_level() > 0) ob_end_clean();
        echo json_encode(['ok'=>false,'error'=>'need_query']);
        exit;
    }

    // ✅ WHERE/파라미터 빌드
      $where  = [];
      $params = [];
      if ($name  !== '') { $where[] = "GB_name  LIKE :name";  $params[':name']  = "%{$name}%"; }
      if ($phone !== '') { $where[] = "GB_phone LIKE :phone"; $params[':phone'] = "%{$phone}%"; }
      if ($email !== '') { $where[] = "GB_email LIKE :email"; $params[':email'] = "%{$email}%"; }
      $whereSql = $where ? (" AND " . implode(" AND ", $where)) : "";


    // ✅ 쿼리
    $sql = "
          SELECT 
            g.name,
            g.phone,
            g.email,
            COUNT(*) AS visit_count,
            SUM(g.group_minutes * g.room_count) AS total_minutes,

            /* 메모는 기존 customer_notes 만 사용 */
            COALESCE(MAX(cn.note), '') AS memo,

            (
              SELECT r2.Group_id
              FROM GB_Reservation r2
              WHERE r2.GB_name  = g.name
                AND r2.GB_phone = g.phone
                AND r2.GB_email = g.email
              ORDER BY r2.GB_date DESC, r2.GB_start_time DESC
              LIMIT 1
            ) AS latest_group_id,

            /* 생일은 customers_info 에서 */
            DATE_FORMAT(ci.birthday, '%Y-%m-%d') AS birthday,

            /* 최근 IPv4 하나 */
            (
              SELECT r2.GB_ip
              FROM GB_Reservation r2
              WHERE r2.GB_ip REGEXP '^[0-9]{1,3}(\\.[0-9]{1,3}){3}$'
                AND (
                      (g.phone IS NOT NULL AND r2.GB_phone = g.phone)
                  OR (g.email IS NOT NULL AND r2.GB_email = g.email)
                )
              ORDER BY r2.GB_date DESC, r2.GB_start_time DESC
              LIMIT 1
            ) AS ips,

            /* 사용한 서로 다른 IPv4 개수 */
            (
              SELECT COUNT(DISTINCT r3.GB_ip)
              FROM GB_Reservation r3
              WHERE r3.GB_ip REGEXP '^[0-9]{1,3}(\\.[0-9]{1,3}){3}$'
                AND (
                      (g.phone IS NOT NULL AND r3.GB_phone = g.phone)
                  OR (g.email IS NOT NULL AND r3.GB_email = g.email)
                )
            ) AS ip_count

          FROM (
            /* === 예약 1건(Group_id)당 집계 === */
            SELECT
              COALESCE(
                Group_id,
                CONCAT(GB_date, '|', DATE_FORMAT(GB_start_time, '%H:%i'), '|', GB_phone, '|', GB_email)
              ) AS visit_key,
              MIN(GB_name)  AS name,
              MIN(GB_phone) AS phone,
              MIN(GB_email) AS email,
              GREATEST(
                (
                  (CASE WHEN TIME_TO_SEC(MAX(GB_end_time)) = 0 THEN 1440
                        ELSE TIME_TO_SEC(MAX(GB_end_time)) / 60 END)
                  - (TIME_TO_SEC(MIN(GB_start_time)) / 60)
                ),
                0
              ) AS group_minutes,
              COUNT(DISTINCT GB_room_no) AS room_count
            FROM GB_Reservation
            WHERE 1=1 {$whereSql}
            GROUP BY visit_key
          ) AS g

          /* 메모: 기존 customer_notes */
          LEFT JOIN customer_notes cn
            ON cn.name = g.name AND cn.phone <=> g.phone AND cn.email <=> g.email

          /* 생일: customers_info 에서만 */
          LEFT JOIN customers_info ci
            ON ci.full_name = g.name
          AND (ci.phone <=> g.phone OR (ci.phone IS NULL AND g.phone IS NULL))
          AND (ci.email <=> g.email OR (ci.email IS NULL AND g.email IS NULL))

          GROUP BY g.name, g.phone, g.email
          ORDER BY visit_count DESC, g.name ASC
        ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2) 버퍼에 ‘1’ 같은 쓰레기 출력이 있었는지 확인
    $junk = ob_get_clean();
    if ($junk !== '') {
        echo json_encode(['ok'=>false,'error'=>'junk_output','detail'=>$junk], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok'=>true,'rows'=>$rows], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode(['ok'=>false,'error'=>'server_error','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
