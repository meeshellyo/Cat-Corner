<?php
declare(strict_types=1);
session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once "database.php";
$conn = Database::dbConnect();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: ./login.php'); exit; }
$role = $user['role'] ?? 'registered';
if ($role !== 'admin') {
  http_response_code(403);
  echo "<h2>Access denied</h2><p>You must be an admin to view this page.</p>";
  exit;
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$actFilter = isset($_GET['action']) && in_array($_GET['action'], ['approved','rejected'], true) ? $_GET['action'] : null;
$whereSql  = $actFilter ? "WHERE l.`action` = :act" : "";

$counts = ['approved' => 0, 'rejected' => 0, 'total' => 0];
try {
  $cstmt = $conn->query("SELECT `action`, COUNT(*) AS c FROM moderation_log GROUP BY `action`");
  foreach ($cstmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $a = strtolower($r['action']);
    if (isset($counts[$a])) $counts[$a] = (int)$r['c'];
    $counts['total'] += (int)$r['c'];
  }
} catch (Throwable $t) { /* ignore */ }

$sql = "
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
  $whereSql
  ORDER BY l.created_at DESC
  LIMIT 200
";
$stmt = $conn->prepare($sql);
if ($actFilter) $stmt->bindValue(':act', $actFilter);
$stmt->execute();
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
        <a href="admin_logs.php" class="nav-link active">Admin Logs</a>
        <a href="promote_user.php" class="nav-link">Promote Users</a>
      <?php endif; ?>
    </div>

    <div class="nav-right">
      <?php if ($user): ?>
        <a class="pill" href="profile.php?id=<?= (int)$user['user_id'] ?>">
          <?= e($user['display_name'] ?? $user['username']) ?> (<?= e($user['role']) ?>)
        </a>
        <a class="btn-outline" href="logout.php">Log out</a>
      <?php else: ?>
        <a class="btn-outline" href="login.php">Sign in</a>
      <?php endif; ?>
      <a href="about_us.php" class="nav-link">About Us</a>
    </div>
  </nav>

  <main class="container">
    <div class="mod-head">
      <h1 style="margin:0;">Moderator Activity Logs</h1>
      <span class="badge"><?= (int)$counts['total'] ?> total</span>
      <span class="kind-pill">Approved: <?= (int)$counts['approved'] ?></span>
      <span class="kind-pill">Rejected: <?= (int)$counts['rejected'] ?></span>
    </div>
    <p class="sub">Review all moderator approvals and rejections below.</p>

    <div class="filter-row">
      <div class="seg" role="tablist" aria-label="Filter logs">
        <?php
          $base = 'admin_logs.php';
          $linkAll = $base;
          $linkAppr = $base . '?action=approved';
          $linkRej  = $base . '?action=rejected';
        ?>
        <a href="<?= e($linkAll) ?>" class="<?= $actFilter === null ? 'active' : '' ?>">All</a>
        <a href="<?= e($linkAppr) ?>" class="<?= $actFilter === 'approved' ? 'active' : '' ?>">Approved</a>
        <a href="<?= e($linkRej)  ?>" class="<?= $actFilter === 'rejected' ? 'active' : '' ?>">Rejected</a>
      </div>
    </div>

    <?php if (!$logs): ?>
      <div class="card empty">
        <strong>Nothing here yet.</strong>
        No moderation actions<?= $actFilter ? " for “".e($actFilter)."”" : "" ?>.
      </div>
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
              <?php
                $name   = trim((string)($log['mod_name'] ?? ''));
                $mrole  = $log['mod_role'] ?? null;
                $initial = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : '•';
                $act    = strtolower((string)$log['act']);
              ?>
              <tr>
                <td>
                  <div style="display:flex;align-items:center;gap:.5rem;">
                    <span class="avatar"><?= e($initial) ?></span>
                    <div>
                      <?php if (!empty($log['moderator_id'])): ?>
                        <a href="profile.php?id=<?= (int)$log['moderator_id'] ?>">
                          <?= e($name ?: '[deleted user]') ?>
                        </a>
                      <?php else: ?>
                        <span class="muted">[deleted user]</span>
                      <?php endif; ?>
                      <div class="muted">
                        <?= $mrole ? 'role: '.e($mrole) : 'role: unknown' ?>
                      </div>
                    </div>
                  </div>
                </td>
                <td>
                  <?php if (!empty($log['post_id']) && !empty($log['post_title'])): ?>
                    <a href="post.php?id=<?= (int)$log['post_id'] ?>"><?= e($log['post_title']) ?></a>
                  <?php elseif (!empty($log['post_id'])): ?>
                    <span class="muted">[deleted post #<?= (int)$log['post_id'] ?>]</span>
                  <?php else: ?>
                    <span class="muted">[no post]</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="<?= $act === 'approved' ? 'act-approved' : ($act === 'rejected' ? 'act-rejected' : '') ?>">
                    <?= e($act) ?>
                  </span>
                </td>
                <td class="reason-col">
                  <?php if (!empty($log['reason'])): ?>
                    <span class="reason-badge"><?= e($log['reason']) ?></span>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
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


