<?php
// public/notice.php
declare(strict_types=1);

// 1) 원본 HTML 로드 (에디터에서 저장한 파일)
$path = __DIR__ . '/../data/notice.html';
$raw  = is_file($path) ? (string)file_get_contents($path) : '';
if ($raw === '') {
  $raw = '<p>Notice is being updated. Please check back shortly.</p>';
}

// 2) 가벼운 보안 정화: 스크립트/임베드/이벤트 핸들러 제거
$clean = preg_replace('#<\s*(script|iframe|object|embed)[^>]*>.*?<\s*/\s*\1\s*>#is', '', $raw);
$clean = preg_replace('/\son\w+\s*=\s*(\'[^\']*\'|"[^"]*"|[^\s>]+)/i', '', $clean);

// 3) 마지막 수정시각
$updatedAt = is_file($path) ? date('Y-m-d H:i', filemtime($path)) : null;

// 4) 보안 헤더 (스타일은 유지, 스크립트는 전부 차단)
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'none'; frame-ancestors 'none'; base-uri 'self';");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Sportech — Important Notice</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{
      --bg:#f6f8fb; --paper:#fff; --ink:#0f172a; --muted:#6b7280;
      --accent:#2563eb; --border:#e5e7eb;
      --brand1:#0ea5e9; --brand2:#38bdf8;
    }
    *{box-sizing:border-box}
    body{
      margin:0; background:var(--bg); color:var(--ink);
      font:16px/1.65 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
    }
    a{color:var(--accent); text-decoration:none}
    a:hover{text-decoration:underline}

    /* 상단 브랜드 바 */
    .brand{
      background:linear-gradient(90deg,var(--brand1),var(--brand2));
      color:#fff; padding:14px 0; border-bottom:1px solid rgba(255,255,255,.2);
      text-align:center; font-weight:700; letter-spacing:.2px;
    }

    /* ▶ 단일 컬럼: 본문만 가운데 넓게 */
    .wrap{
      max-width: 1100px;         /* 페이지 전체 폭 */
      margin: 24px auto;
      padding: 0 24px;
    }

    .board{
      background:var(--paper);
      border:1px solid var(--border);
      border-radius:16px;
      box-shadow:0 10px 20px rgba(0,0,0,.04);
      width:100%;
    }
    .board-header{padding:26px 32px; border-bottom:1px solid var(--border)}
    .board-header h1{margin:0; font-size:22px}
    .meta{margin-top:6px; color:var(--muted); font-size:14px}

    /* 내용 폭을 넓고 시원하게 */
    .board-body{padding:36px; max-width: 980px;}  /* 본문 폭 */
    .board-body{margin-left:auto; margin-right:auto;} /* 가운데 정렬 */

    /* 에디터에서 온 h2/h3/ul 등 기본 타이포 정리 */
    .board-body h1,.board-body h2,.board-body h3{line-height:1.3; font-weight:700}
    .board-body h2{margin:30px 0 12px; font-size:20px; padding-bottom:8px; border-bottom:1px dashed var(--border)}
    .board-body h3{margin:20px 0 10px; font-size:17px}
    .board-body p{margin:12px 0}
    .board-body ul{padding-left:1.4rem; margin:10px 0}
    .board-body li{margin:6px 0}

    .footer{
      color:var(--muted); font-size:13px; text-align:center;
      padding:24px; margin-top:12px;
    }

    @media (max-width: 640px){
      .board-body{padding:24px}
    }
  </style>
</head>
<body>
  <div class="brand">SPORTECH INDOOR GOLF</div>

  <div class="wrap">
    <main class="board">
      <div class="board-header">
        <h1>Important Notice</h1>
        <?php if ($updatedAt): ?>
          <div class="meta">Last updated: <?= htmlspecialchars($updatedAt) ?></div>
        <?php endif; ?>
      </div>
      <article class="board-body">
        <?= $clean /* 에디터에서 준 스타일/색상 그대로 출력 */ ?>
      </article>
    </main>
  </div>

  <div class="footer">
    SPORTECH INDOOR GOLF (SIMULATOR) · #120 1642 10th Avenue SW, Calgary, AB T3C0J5
  </div>
</body>
</html>
