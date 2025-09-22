<?php
// /public/api/update_info.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/config.php';
session_start();

if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit;
}

function norm_email(?string $s): ?string {
  $s = trim((string)$s);
  return $s === '' ? null : strtolower($s);
}
function norm_name(?string $s): ?string {
  $s = preg_replace('/\s+/u',' ', trim((string)$s));
  return $s === '' ? null : $s;
}
function normalize_birthday(?string $input): ?string {
  $s = trim((string)$input);
  if ($s === '') return null;
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;               // YYYY-MM-DD
  if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) {        // MM/DD/YYYY
    [$all,$mm,$dd,$yy] = $m;
    if (!checkdate((int)$mm,(int)$dd,(int)$yy)) throw new RuntimeException('invalid birthday date');
    return sprintf('%04d-%02d-%02d', (int)$yy, (int)$mm, (int)$dd);
  }
  throw new RuntimeException('invalid birthday format');
}

try {
  $data = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
  $groupId   = trim((string)($data['group_id']   ?? ''));
  $newEmailI = (string)($data['new_email']  ?? '');
  $newNameI  = (string)($data['new_name']   ?? '');
  $birthdayI = (string)($data['birthday']   ?? '');

  if ($groupId === '') throw new RuntimeException('group_id is required');

  $newName  = norm_name($newNameI);
  $newEmail = norm_email($newEmailI);
  $birthday = $birthdayI !== '' ? normalize_birthday($birthdayI) : null;

  if ($newName === null && $newEmail === null && $birthday === null) {
    throw new RuntimeException('nothing to update');
  }

  // 현재 그룹의 기존 식별값 조회 (phone은 여기서 변경 안 함)
  $st = $pdo->prepare("
    SELECT MIN(GB_name)  AS old_name,
           MIN(GB_phone) AS phone,
           MIN(GB_email) AS old_email,
           MIN(customer_id) AS old_cid
    FROM GB_Reservation
    WHERE Group_id = :gid
    LIMIT 1
  ");
  $st->execute([':gid'=>$groupId]);
  $cur = $st->fetch(PDO::FETCH_ASSOC);
  if (!$cur) throw new RuntimeException('group not found');

  $phone     = $cur['phone'] !== null && $cur['phone'] !== '' ? (string)$cur['phone'] : null;
  $finalName = $newName  ?? norm_name($cur['old_name']);
  $finalMail = $newEmail ?? norm_email($cur['old_email']);

  /* ✅ 추가: oldCid 꼭 정의 */
  $oldCid = isset($cur['old_cid']) && $cur['old_cid'] !== null ? (int)$cur['old_cid'] : null;

  $pdo->beginTransaction();

  // 1) 예약 테이블 업데이트 (이름/이메일)
  if ($finalName !== null || $finalMail !== null) {
    $set = [];
    $params = [':gid'=>$groupId];
    if ($finalName !== null) { $set[]='GB_name = :n';   $params[':n']=$finalName; }
    if ($finalMail !== null) { $set[]='GB_email = :e';  $params[':e']=$finalMail; }
    $pdo->prepare('UPDATE GB_Reservation SET '.implode(', ',$set).' WHERE Group_id = :gid')
        ->execute($params);
  }

  // 2) 타겟 customers_info (정확히 3키 일치) 찾기/생성
  $find = $pdo->prepare("
    SELECT id FROM customers_info
    WHERE full_name <=> :n AND email <=> :e AND phone <=> :p
    ORDER BY updated_at DESC, id DESC
    LIMIT 1
  ");
  $find->execute([':n'=>$finalName, ':e'=>$finalMail, ':p'=>$phone]);
  $targetId = $find->fetchColumn();
  if (!$targetId) {
    $ins = $pdo->prepare("
      INSERT INTO customers_info (full_name, email, phone, birthday)
      VALUES (:n, :e, :p, :b)
    ");
    $ins->execute([':n'=>$finalName, ':e'=>$finalMail, ':p'=>$phone, ':b'=>$birthday]);
    $targetId = (int)$pdo->lastInsertId();
  } else {
    // 생일만 갱신(옵션)
    if ($birthday !== null) {
      $pdo->prepare("UPDATE customers_info SET birthday = :b, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
          ->execute([':b'=>$birthday, ':id'=>$targetId]);
    }
  }

  // 3) 해당 그룹 예약 customer_id 연결 (이미 있음)
    $pdo->prepare("UPDATE GB_Reservation SET customer_id = :cid WHERE Group_id = :gid")
        ->execute([':cid'=>$targetId, ':gid'=>$groupId]);

    // 3-1) ✅ 이전 고객행이 더 이상 참조되지 않으면 삭제
    if ($oldCid && $oldCid !== $targetId) {
      $st = $pdo->prepare("SELECT COUNT(*) FROM GB_Reservation WHERE customer_id = :id");
      $st->execute([':id' => $oldCid]);
      if ((int)$st->fetchColumn() === 0) {
        // 혹시 모를 경합 대비로 한 번 더 안전장치
        $pdo->prepare("
          DELETE FROM customers_info
          WHERE id = :id
            AND NOT EXISTS (SELECT 1 FROM GB_Reservation r WHERE r.customer_id = customers_info.id)
        ")->execute([':id' => $oldCid]);
      }
    }
  // 4) 같은 3키의 중복 customers_info 병합/정리 (정확히 동일한 3키만)
  $dups = $pdo->prepare("
    SELECT id FROM customers_info
    WHERE full_name <=> :n AND email <=> :e AND phone <=> :p AND id <> :keep
  ");
  $dups->execute([':n'=>$finalName, ':e'=>$finalMail, ':p'=>$phone, ':keep'=>$targetId]);
  $dupIds = $dups->fetchAll(PDO::FETCH_COLUMN, 0);

  if ($dupIds) {
    // 예약 참조 재지정
    $ph = implode(',', array_fill(0, count($dupIds), '?'));
    $rebind = $pdo->prepare("UPDATE GB_Reservation SET customer_id = ? WHERE customer_id IN ($ph)");
    $rebind->execute(array_merge([$targetId], array_map('intval',$dupIds)));
    // 중복 customers_info 삭제
    $del = $pdo->prepare("DELETE FROM customers_info WHERE id IN ($ph)");
    $del->execute(array_map('intval',$dupIds));
  }

  $pdo->commit();

  echo json_encode([
    'ok'=>true,
    'group_id'=>$groupId,
    'customer_id'=>$targetId,
    'full_name'=>$finalName,
    'email'=>$finalMail,
    'phone'=>$phone,
    'birthday'=>$birthday
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
