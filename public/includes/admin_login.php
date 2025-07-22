<?php
session_start();

// 로그인 처리
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminPassword = 'golf123';  // 원하는 비밀번호
    if ($_POST['password'] === $adminPassword) {
        $_SESSION['is_admin'] = true;
        header("Location: ../admin.php");
        exit;
    } else {
        $error = "Invalid password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container d-flex align-items-center justify-content-center vh-100">
  <div class="card shadow p-4" style="min-width: 350px;">
  <img src="../images/logo.png" alt="Sportech Logo" style="width: 300px; height: 60px;" />
  <br>
    <?php if ($error): ?>
      <div class="alert alert-danger p-2 py-1 text-center" role="alert">
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <form method="post">
      <div class="mb-3">
        <label for="password" class="form-label">Password:</label>
        <input type="password" id="password" name="password" class="form-control" autofocus>
      </div>
      <button type="submit" class="btn btn-primary w-100">Login</button>
    </form>
  </div>
</div>

</body>
</html>
