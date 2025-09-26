<?php /* public/scoreboard/index.php */ ?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8" />
  <title>Golf Leaderboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="scoreboard.css">
</head>
<body>
  <main class="container">
    <h1>Full Leaderboard</h1>

    <section class="board" id="board">
      <!-- ✅ 오른쪽 상단 타임스탬프 바 -->
      <div class="board-top">
        <span id="statusBadge" class="status-badge">Live</span> <!-- ✅ 추가 -->
        <div id="updatedAt" class="updated">Last updated: —</div>
      </div>

      <div class="hdr">
        <div>POS</div>
        <div>ATHLETE</div>
        <div>TO PAR</div>
        <div class="num">STROKES</div>
      </div>
      <!-- JS가 행(row)을 주입합니다 -->
    </section>
  </main>

  <script>
    // 전역 설정 (필요시 서버 시각으로 바꿀 수 있음: updatedAt에 타임스탬프(ms) 전달)
    window.SCOREBOARD_CONFIG = {
      parTotal: 72
      //, updatedAt: <?= (int)(microtime(true)*1000) ?> // 원하면 서버 시각 사용
    };
  </script>
  <script src="leaderboard.js"></script>
</body>
</html>
