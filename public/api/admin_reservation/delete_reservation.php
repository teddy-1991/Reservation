<?php
// /public/api/delete_reservation.php
declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';     // $pdo
require_once __DIR__ . '/../../includes/functions.php';  // upsert_edit_token_for_group()

if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit;
}

// DELETE only (필요하면 POST도 허용 가능)
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Only DELETE method is allowed']); exit;
}

// 입력 파싱: querystring + body 둘 다 지원
parse_str(file_get_contents('php://input') ?: '', $body);
$id      = $_GET['id']          ?? $body['id']          ?? null;         // GB_id (int)
$groupId = $_GET['group_id']    ?? $_GET['Group_id']
         ?? $body['group_id']   ?? $body['Group_id']    ?? null;         // Group_id (string)

// 정합성 체크
$id = $id !== null ? (int)$id : null;
$groupId = $groupId !== null ? (string)$groupId : null;

if ($groupId === null && $id === null) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Missing reservation id or group_id']); exit;
}

try {
    // 삭제 대상의 customer_id 수집(고아정리용), 토큰 대상 그룹도 확보
    $maybeOrphans = [];
    $tokenGroup   = null;

    if ($groupId !== null) {
        $q = $pdo->prepare("SELECT DISTINCT customer_id FROM GB_Reservation WHERE Group_id = :gid AND customer_id IS NOT NULL");
        $q->execute([':gid'=>$groupId]);
        $maybeOrphans = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        $tokenGroup = $groupId;
    } else {
        $q = $pdo->prepare("SELECT Group_id, customer_id FROM GB_Reservation WHERE GB_id = :id LIMIT 1");
        $q->execute([':id'=>$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['ok'=>false,'error'=>'Reservation not found']); exit;
        }
        if (!empty($row['customer_id'])) $maybeOrphans = [(int)$row['customer_id']];
        $tokenGroup = (string)$row['Group_id'];
    }

    $pdo->beginTransaction();

    // 실제 삭제
    if ($groupId !== null) {
        $stmt = $pdo->prepare("DELETE FROM GB_Reservation WHERE Group_id = :gid");
        $stmt->execute([':gid'=>$groupId]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM GB_Reservation WHERE GB_id = :id");
        $stmt->execute([':id'=>$id]);
    }
    $deleted = $stmt->rowCount();

    if ($deleted === 0) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok'=>false,'error'=>'Reservation not found']); exit;
    }

    // 그룹의 나머지 존재 여부 확인 (전체 삭제되었거나 0건이면 토큰 무효화)
    $tokenInvalidated = false;
    if ($tokenGroup !== null && $tokenGroup !== '') {
        $c = $pdo->prepare("SELECT COUNT(*) FROM GB_Reservation WHERE Group_id = :gid");
        $c->execute([':gid'=>$tokenGroup]);
        if ((int)$c->fetchColumn() === 0) {
            // 그룹이 비었으므로 토큰 무효화
            $expires = new DateTime('now -1 minute');
            upsert_edit_token_for_group($pdo, $tokenGroup, $expires);
            $tokenInvalidated = true;
        }
    }

    $pdo->commit();

    // 커밋 후: 고아 customers_info 정리 (GB_Reservation + event_registrations 둘 다 미참조일 때만)
    $orphansDeleted = 0;
    if (!empty($maybeOrphans)) {
        // 여전히 참조되는지 집계
        $ph = implode(',', array_fill(0, count($maybeOrphans), '?'));

        // 예약 참조
        $st1 = $pdo->prepare("SELECT customer_id, COUNT(*) AS c FROM GB_Reservation WHERE customer_id IN ($ph) GROUP BY customer_id");
        $st1->execute($maybeOrphans);
        $resRefs = $st1->fetchAll(PDO::FETCH_KEY_PAIR); // [cid => count]

        // 이벤트(있을 경우) 참조
        $evtRefs = [];
        try {
            $st2 = $pdo->prepare("SELECT customer_id, COUNT(*) AS c FROM event_registrations WHERE customer_id IN ($ph) GROUP BY customer_id");
            $st2->execute($maybeOrphans);
            $evtRefs = $st2->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $ignore) {
            // 테이블 없으면 무시
        }

        $toDelete = [];
        foreach ($maybeOrphans as $cid) {
            $c1 = (int)($resRefs[$cid] ?? 0);
            $c2 = (int)($evtRefs[$cid] ?? 0);
            if (($c1 + $c2) === 0) $toDelete[] = $cid;
        }

        if ($toDelete) {
            $ph2 = implode(',', array_fill(0, count($toDelete), '?'));
            $del = $pdo->prepare("DELETE FROM customers_info WHERE id IN ($ph2)");
            $del->execute($toDelete);
            $orphansDeleted = $del->rowCount();
        }
    }

    echo json_encode([
        'ok'                => true,
        'deleted'           => $deleted,
        'orphans_deleted'   => $orphansDeleted,
        'token_invalidated' => $tokenInvalidated,
        'group_id'          => $groupId ?? $tokenGroup,
        'id'                => $id,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'server','details'=>$e->getMessage()]);
}
