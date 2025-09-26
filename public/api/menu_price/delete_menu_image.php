<?php
// /delete_menu_image.php  (루트)
// 결과는 항상 JSON으로 반환
header('Content-Type: application/json');

try {
  // 1) 입력 검증
  if (!isset($_POST['slot']) || !in_array($_POST['slot'], ['1','2','3'], true)) {
    throw new Exception('Invalid slot');
  }
  $slot = $_POST['slot'];

  // 2) 실제 파일 경로
  $dir = __DIR__ . '/../../images/menu/';       // 루트 기준 images/menu
  if (!is_dir($dir)) {
    throw new Exception('Menu folder not found');
  }

  // 3) 허용 확장자 모두 삭제
  $exts = ['jpg','jpeg','png','webp'];
  $deleted = false;

  foreach ($exts as $ext) {
    $path = $dir . "menu_{$slot}.{$ext}";
    if (is_file($path)) {
      if (!unlink($path)) {
        throw new Exception('Delete failed');
      }
      $deleted = true;
    }
  }

  echo json_encode(['success' => true, 'deleted' => $deleted]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}