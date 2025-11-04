<?php
declare(strict_types=1);
session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once "database.php";
$conn = Database::dbConnect();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---- auth ---- */
$user = $_SESSION['user'] ?? null;
if (!$user) {
  header('Location: ./login.php');
  exit;
}
$role = $user['role'] ?? 'registered';
if ($role !== 'admin') {
  http_response_code(403);
  echo "<h2>Access denied</h2><p>You must be an admin to view this page.</p>";
  exit;
}

/* ---- helper ---- */
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* ---- get logs (tolerant joins) ---- */
$stmt = $conn->query("
  SELECT
    l.log_id,
    l.moderator_id,
    l.post_id,
    l.`action`   AS act,
    l.reason,
    l.created_at,
    COALESCE(u.display_name, u.username) AS mod_name,
    u.role       AS mod_role,
    p.title      AS post_title
  FROM moderation_log AS l
  LEFT JOIN users AS u ON l.moderator_id = u.user_id
  LEFT JOIN post  AS p ON l.post_id = p.post_id
  ORDER BY l.created_at DESC
  LIMIT 200
");
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Moderator Activity Logs — Cat Corner</title>
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

      <?php if (in_array($role, ['registered', 'moderator', 'admin'], true)): ?>
        <a href="my_reviews.php" class="nav-link">My Reviews</a>
      <?php endif; ?>

      <?php if (in_array($role, ['moderator','admin'], true)): ?>
        <a href="mod_flags.php" class="nav-link">Moderation Queue</a>
      <?php endif; ?>

      <?php if ($role === 'admin'): ?>
        <a href="admin_logs.php" class="nav-link">Admin Logs</a>
        <a href="promote_user.php" class="nav-link">Promote Users</a>
      <?php endif; ?>
    </div>



    <div class="nav-right">
      <?php if ($user): ?>
        <span class="pill">
          <?= e($user['display_name'] ?? $user['username']) ?> (<?= e($user['role']) ?>)
        </span>
        <a class="btn-outline" href="logout.php">Log out</a>
      <?php else: ?>
        <a class="btn-outline" href="login.php">Sign in</a>
      <?php endif; ?>
    </div>
  </nav>

  <main class="container">
    <div class="logo">
      <img src="doodles/create_user_logo.jpg" alt="Admin logs">
    </div>
    <h1>Moderator Activity Logs</h1>
    <p class="sub">Review all moderator approvals and rejections below.</p>

    <?php if (!$logs): ?>
      <div class="card empty">No moderation actions yet.</div>
    <?php else: ?>
      <div class="log-table-wrapper">
        <table class="mod-log-table">
          <thead>
            <tr>
              <th>Moderator</th>
              <th>Post</th>
              <th>Action</th>
              <th>Reason</th>
              <th class="nowrap">Date</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($logs as $log): ?>
              <tr>
                <td>
                  <?php
                    $name  = $log['mod_name'] ?? null;
                    $mrole = $log['mod_role'] ?? null;
                  ?>
                  <div><?= e($name ?: '[deleted user]') ?></div>
                  <?php if ($mrole): ?>
                    <div class="muted">role: <?= e($mrole) ?></div>
                  <?php else: ?>
                    <div class="muted">role: unknown</div>
                  <?php endif; ?>
                </td>

                <td>
                  <?php if (!empty($log['post_id']) && !empty($log['post_title'])): ?>
                    <a href="post.php?id=<?= (int)$log['post_id'] ?>"><?= e($log['post_title']) ?></a>
                  <?php elseif (!empty($log['post_id'])): ?>
                    [deleted post #<?= (int)$log['post_id'] ?>]
                  <?php else: ?>
                    [no post]
                  <?php endif; ?>
                </td>

                <td>
                  <?php $act = strtolower((string)$log['act']); ?>
                  <span class="<?= $act === 'approved' ? 'act-approved' : ($act === 'rejected' ? 'act-rejected' : '') ?>">
                    <?= e($act) ?>
                  </span>
                </td>

                <td class="reason-col">
                  <span class="muted"><?= e($log['reason'] ?? '') ?></span>
                </td>

                <td class="nowrap">
                  <?= e(date('M j, Y g:i a', strtotime($log['created_at']))) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>

