<?php
// /api/move_reservation.php
require_once __DIR__ . '/../includes/config.php';
session_start();

header('Content-Type: application/json');

if (empty($_SESSION['is_admin'])) {
  http_response_code(401);
  echo json_encode(['success' => false, 'message' => 'Unauthorized']);
  exit;
}

$id        = $_POST['id']        ?? null;
$groupId   = $_POST['group_id']  ?? null;
$date      = $_POST['date']      ?? '';
$startTime = $_POST['start_time']?? '';
$endTime   = $_POST['end_time']  ?? '';
$firstRoom = isset($_POST['first_room']) ? (int)$_POST['first_room'] : null;

if (!$date || !$startTime || !$endTime || (!$id && !$groupId) || !$firstRoom) {
  http_response_code(400);
  echo json_encode(['success'=>false,'message'=>'Missing params']);
  exit;
}

try {
  $pdo->beginTransaction();

  if ($groupId) {
    // 1) 그룹의 기존 방 세트 가져오기(정렬)
    $stmt = $pdo->prepare("SELECT GB_id, GB_room_no FROM GB_Reservation WHERE Group_id = :g ORDER BY GB_room_no ASC");
    $stmt->execute([':g'=>$groupId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { throw new RuntimeException('Group not found'); }

    $baseRooms = array_map('intval', array_column($rows, 'GB_room_no'));
    sort($baseRooms);
    $baseFirst = $baseRooms[0];
    $delta     = $firstRoom - $baseFirst;
    $targetRooms = array_map(fn($r) => $r + $delta, $baseRooms);

    // 2) 각 타깃 방 충돌 검사(같은 그룹은 제외)
    $chk = $pdo->prepare("
      SELECT COUNT(*) FROM GB_Reservation
      WHERE GB_date = :d
        AND GB_room_no = :r
        AND (Group_id IS NULL OR Group_id <> :g)
        AND NOT (GB_end_time <= :s OR GB_start_time >= :e)
    ");
    foreach ($targetRooms as $r) {
      $chk->execute([':d'=>$date, ':r'=>$r, ':g'=>$groupId, ':s'=>$startTime, ':e'=>$endTime]);
      if ((int)$chk->fetchColumn() > 0) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>"Room {$r} still has a conflict."]); exit;
      }
    }

    // 3) 방/시간/날짜 업데이트 (행 수=그룹 크기)
    //    타깃 방을 행 순서대로 매핑
    $upd = $pdo->prepare("UPDATE GB_Reservation
                          SET GB_date = :d, GB_start_time = :s, GB_end_time = :e, GB_room_no = :r
                          WHERE GB_id = :id");
    foreach ($rows as $idx => $row) {
      $upd->execute([
        ':d'=>$date, ':s'=>$startTime, ':e'=>$endTime, ':r'=>$targetRooms[$idx], ':id'=>$row['GB_id']
      ]);
    }

  } else {
    // 단일 예약: 새 첫 방으로 변경
    $roomCheck = $pdo->prepare("
      SELECT COUNT(*) FROM GB_Reservation
      WHERE GB_date = :d AND GB_room_no = :r AND GB_id <> :id
        AND NOT (GB_end_time <= :s OR GB_start_time >= :e)
    ");
    $roomCheck->execute([':d'=>$date, ':r'=>$firstRoom, ':id'=>$id, ':s'=>$startTime, ':e'=>$endTime]);
    if ((int)$roomCheck->fetchColumn() > 0) {
      $pdo->rollBack();
      http_response_code(409);
      echo json_encode(['success'=>false,'message'=>'Selected time conflicts with another reservation.']); exit;
    }

    $upd = $pdo->prepare("UPDATE GB_Reservation
                          SET GB_date = :d, GB_start_time = :s, GB_end_time = :e, GB_room_no = :r
                          WHERE GB_id = :id");
    $upd->execute([':d'=>$date, ':s'=>$startTime, ':e'=>$endTime, ':r'=>$firstRoom, ':id'=>$id]);
  }

  $pdo->commit();
  echo json_encode(['success'=>true]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'Server error']);
}