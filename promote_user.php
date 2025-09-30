<?php
// promote_user.php — admin-only: set a user's role to registered/moderator
// visit while logged in as 'admin' role, submit form.

declare(strict_types=1);
session_start();

require_once "database.php";

// must be logged in and be admin
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'admin') {
  header('Location: ./index.php');
  exit;
}

$conn = Database::dbConnect();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$info = ''; $errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $role     = trim($_POST['role'] ?? '');

  // only allow these roles from this page
  $allowed = ['registered','moderator'];

  if ($username === '')      $errors[] = 'please enter a username.';
  if (!in_array($role, $allowed, true)) $errors[] = 'invalid role.';

  if (!$errors) {
    try {
      $stmt = $conn->prepare("UPDATE users SET role = :r WHERE username = :u");
      $stmt->execute([':r' => $role, ':u' => $username]);
      if ($stmt->rowCount() > 0) {
        $info = "updated @{$username} to role: {$role}.";
      } else {
        $errors[] = 'no user with that username.';
      }
    } catch (PDOException $e) {
      $errors[] = 'update failed.';
    }
  }
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Set User Role — Cat Corner</title>
  <link rel="stylesheet" href="css/style.css" type="text/css">
</head>
<body>
  <nav class="nav" role="navigation" aria-label="Main">
    <div class="nav-left">
      <a class="brand" href="index.php">
        <img src="doodles/cat_corner_logo.jpg" alt="Cat Corner logo">
        <span>Cat Corner</span>
      </a>
    </div>
    <div class="nav-right">
      <span class="pill">admin: <?= e($user['username'] ?? 'admin') ?></span>
      <a class="btn-outline" href="logout.php">Log out</a>
    </div>
  </nav>

  <main class="container">
    <h1>Set User Role</h1>
    <p class="sub">type a username and choose a role.</p>

    <?php if ($info): ?>
      <div class="card" style="margin-bottom:1rem;"><?= e($info) ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <ul class="error-list"><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>

    <form method="post" action="promote_user.php" class="form" autocomplete="off">
      <div class="form-group">
        <label for="username">username</label>
        <input id="username" name="username" required placeholder="exact username">
      </div>
      <div class="form-group">
        <label for="role">role</label>
        <select id="role" name="role" required>
          <option value="registered">registered</option>
          <option value="moderator">moderator</option>
        </select>
      </div>
      <button class="btn" type="submit">update role</button>
    </form>
  </main>
</body>
</html>

