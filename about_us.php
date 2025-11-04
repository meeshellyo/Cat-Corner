<?php
// about_us.php
declare(strict_types=1);
session_start();

require_once "database.php";

$user = $_SESSION['user'] ?? null;
$loggedIn = (bool)$user;
$userRole = $loggedIn ? ($user['role'] ?? 'registered') : 'guest';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>About Us — Cat Corner</title>
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

    <div class="nav-center">
      <a href="index.php" class="nav-link">Home</a>

      <?php if ($loggedIn && in_array($userRole, ['registered', 'moderator', 'admin'], true)): ?>
        <a href="my_reviews.php" class="nav-link">My Reviews</a>
      <?php endif; ?>

      <?php if (in_array($userRole, ['moderator','admin'], true)): ?>
        <a href="mod_flags.php" class="nav-link">Moderation Queue</a>
      <?php endif; ?>

      <?php if ($userRole === 'admin'): ?>
        <a href="admin_logs.php" class="nav-link">Admin Logs</a>
        <a href="promote_user.php" class="nav-link">Promote Users</a>
      <?php endif; ?>
    </div>

    <div class="nav-right">
      <?php if ($loggedIn): ?>
        <span class="pill"><?= htmlspecialchars($user['display_name'] ?? $user['username']) ?> (<?= htmlspecialchars($userRole) ?>)</span>
        <a class="btn-outline" href="logout.php">Log out</a>
      <?php else: ?>
        <a class="btn-outline" href="login.php">Sign in</a>
      <?php endif; ?>
      <a href="about_us.php" class="nav-link">About Us</a>
    </div>
  </nav>

  

  <main>
    <div class="about-container">
      <img src="doodles/cat_corner_logo.jpg" alt="Cat Corner logo" class="about-logo">
      <h1>CAT CORNER</h1>
      <p>
        Cat Corner was created to bridge the gap between general forum sites and cat lovers.
        It’s a friendly community where people can share photos, ask questions, exchange advice,
        and connect with others who love cats just as much as they do.
      </p>
      <div class="support">
        <strong>Support:</strong> Michelle — hmtra@go.olemiss.edu
      </div>
    </div>
  </main>
</body>
</html>
