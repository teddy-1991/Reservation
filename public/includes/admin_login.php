<?php
session_start();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminPassword = 'golf123';

    if ($_POST['password'] === $adminPassword) {
        $_SESSION['is_admin'] = true;
        header("Location: ../admin.php");  // ✅ 루트 기준으로 admin.php로 이동
        exit;
    } else {
        $error = "Invalid password.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Admin Login</title>
</head>
<body>
  <h2>Admin Login</h2>
  <?php if ($error): ?>
    <p style="color:red"><?= htmlspecialchars($error) ?></p>
  <?php endif; ?>
  <form method="post">
    <label>Password:
      <input type="password" name="password">
    </label>
    <button type="submit">Login</button>
  </form>
</body>
</html>
