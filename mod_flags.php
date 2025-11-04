<?php
// mod_flags.php — moderation queue for posts
declare(strict_types=1);
session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once "database.php";

function e(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// logged-in user
$user = $_SESSION['user'] ?? null;
if (!$user) {
  header('Location: ./login.php');
  exit;
}

$userId = (int)$user['user_id'];
$role   = $user['role'] ?? 'registered';

// only moderators/admins can access
$isMod   = in_array($role, ['moderator', 'admin'], true);
$isAdmin = ($role === 'admin');

if (!$isMod && !$isAdmin) {
  http_response_code(403);
  echo "<h2>Not allowed</h2><p>You do not have permission to view the moderation queue.</p>";
  exit;
}

$conn = Database::dbConnect();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$errors = [];
$info   = [];

// handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'approve_post' && ($isMod || $isAdmin)) {
    $pid = (int)($_POST['post_id'] ?? 0);

    if ($pid > 0) {
      // check owner to prevent self-approval
      $ownStmt = $conn->prepare("SELECT user_id FROM post WHERE post_id = :pid");
      $ownStmt->execute([':pid' => $pid]);
      $ownerId = (int)($ownStmt->fetchColumn() ?: 0);

      if ($ownerId === $userId) {
        $errors[] = "You can't approve your own post. Another moderator must review it.";
      } else {
        try {
          $conn->beginTransaction();

          // approve post
          $stmt = $conn->prepare("UPDATE post SET content_status = 'live' WHERE post_id = :pid");
          $stmt->execute([':pid' => $pid]);

          // mark flags as resolved
          $fs = $conn->prepare("UPDATE flag SET status = 'resolved' WHERE post_id = :pid AND status = 'flagged'");
          $fs->execute([':pid' => $pid]);

          // approve any pending media
          $ms = $conn->prepare("
            UPDATE media
            SET moderation_status = 'approved'
            WHERE post_id = :pid
              AND moderation_status IN ('pending','flagged')
          ");
          $ms->execute([':pid' => $pid]);

          $conn->commit();
          $info[] = "Post #{$pid} approved.";
        } catch (Throwable $t) {
          if ($conn->inTransaction()) $conn->rollBack();
          $errors[] = "Failed to approve post.";
        }
      }
    }
  }

  if ($action === 'reject_post' && ($isMod || $isAdmin)) {
    $pid = (int)($_POST['post_id'] ?? 0);

    if ($pid > 0) {
      try {
        $conn->beginTransaction();

        // mark post as deleted (or 'rejected' if you have that status)
        $stmt = $conn->prepare("UPDATE post SET content_status = 'deleted' WHERE post_id = :pid");
        $stmt->execute([':pid' => $pid]);

        // mark flags as resolved/rejected
        $fs = $conn->prepare("UPDATE flag SET status = 'rejected' WHERE post_id = :pid AND status = 'flagged'");
        $fs->execute([':pid' => $pid]);

        // reject any pending media
        $ms = $conn->prepare("
          UPDATE media
          SET moderation_status = 'rejected'
          WHERE post_id = :pid
            AND moderation_status IN ('pending','flagged')
        ");
        $ms->execute([':pid' => $pid]);

        $conn->commit();
        $info[] = "Post #{$pid} rejected and removed.";
      } catch (Throwable $t) {
        if ($conn->inTransaction()) $conn->rollBack();
        $errors[] = "Failed to reject post.";
      }
    }
  }
}

// load posts that need review (pending or flagged)
$rows = [];
try {
  $stmt = $conn->prepare("
    SELECT
      p.post_id,
      p.user_id,
      p.title,
      p.body,
      p.created_at,
      p.content_status,
      u.username,
      u.display_name,
      mc.name AS main_name,
      mc.slug AS main_slug,
      f.trigger_source,
      f.trigger_word,
      f.trigger_hits,
      f.created_at AS flagged_at
    FROM post p
    JOIN users u ON u.user_id = p.user_id
    LEFT JOIN main_category mc ON mc.main_category_id = p.main_category_id
    LEFT JOIN (
      SELECT f.*
      FROM flag f
      JOIN (
        SELECT post_id, MAX(created_at) AS max_created
        FROM flag
        WHERE status = 'flagged'
        GROUP BY post_id
      ) x ON x.post_id = f.post_id AND x.max_created = f.created_at
      WHERE f.status = 'flagged'
    ) f ON f.post_id = p.post_id
    WHERE p.content_status IN ('pending','flagged')
    ORDER BY p.created_at DESC
    LIMIT 200
  ");
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $t) {
  $errors[] = 'Failed to load moderation queue.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Moderation Queue — Cat Corner</title>
  <link rel="stylesheet" href="css/style.css" type="text/css">
  <style>
    .mod-head { display:flex; align-items:center; gap:.5rem; }
    .badge { background:#fee2e2; color:#7f1d1d; padding:.15rem .5rem; border-radius:999px; font-size:.85rem; border:1px solid #fecaca; }
    .review-list { display:grid; grid-template-columns:1fr; gap:.75rem; margin-top:.75rem; }
    @media (min-width: 1000px) { .review-list { grid-template-columns:1fr 1fr; } }
    .meta { color:#666; font-size:.9rem; margin:.25rem 0 .5rem; }
    .pill-status { padding:.05rem .45rem; border-radius:999px; border:1px solid #e5e7eb; font-size:.8rem; margin-left:.35rem; }
    .pill-flagged { background:#fee2e2; color:#7f1d1d; border-color:#fecaca; }
    .pill-pending { background:#e0f2fe; color:#075985; border-color:#bae6fd; }
    .mod-actions { display:flex; gap:.5rem; margin-top:.5rem; justify-content:flex-end; }
    .btn-soft { border-radius:999px; padding:.3rem .7rem; border:1px solid #e5e7eb; background:#f9fafb; font-size:.85rem; cursor:pointer; }
    .btn-approve { background:#ecfdf3; border-color:#bbf7d0; }
    .btn-reject { background:#fef2f2; border-color:#fecaca; }
    .muted-note { color:#6b7280; font-size:.85rem; margin-top:.4rem; }
  </style>
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

      <?php if ($isMod || $isAdmin): ?>
        <a href="mod_flags.php" class="nav-link active">Moderation Queue</a>
      <?php endif; ?>

      <?php if ($isAdmin): ?>
        <a href="admin_logs.php" class="nav-link">Admin Logs</a>
        <a href="promote_user.php" class="nav-link">Promote Users</a>
      <?php endif; ?>
    </div>

    <div class="nav-right">
      <?php if ($user): ?>
        <a class="pill" href="profile.php?id=<?= (int)$user['user_id'] ?>">
          <?= e($user['display_name'] ?? $user['username']) ?> (<?= e($role) ?>)
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
      <h1 style="margin:0;">Moderation Queue</h1>
      <span class="badge"><?= count($rows) ?> items</span>
    </div>
    <p class="sub">Review posts that are pending or flagged by the system or users.</p>

    <?php if ($info): ?>
      <div class="card" style="background:#ecfdf3;border:1px solid #bbf7d0;margin-bottom:.75rem;">
        <ul style="margin:.5rem 1rem;">
          <?php foreach ($info as $msg): ?>
            <li><?= e($msg) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="card error-card">
        <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <?php if (!$rows): ?>
      <div class="card"><strong>All clear!</strong> There are no posts waiting for review.</div>
    <?php else: ?>
      <div class="review-list">
        <?php foreach ($rows as $r): ?>
          <?php
            $created    = date('M j, Y g:i a', strtotime($r['created_at']));
            $excerpt    = mb_strimwidth((string)$r['body'], 0, 280, '…');
            $status     = $r['content_status'];
            $ownerId    = (int)$r['user_id'];
            $ownerName  = $r['display_name'] ?: $r['username'] ?: 'user';
            $canApprove = ($isMod || $isAdmin) && ($ownerId !== $userId);
          ?>
          <article class="card">
            <h3 style="margin-top:0;">
              <a href="post.php?id=<?= (int)$r['post_id'] ?>" target="_blank">
                <?= e($r['title'] ?: '[no title]') ?>
              </a>
              <?php if ($status === 'flagged'): ?>
                <span class="pill-status pill-flagged">flagged</span>
              <?php elseif ($status === 'pending'): ?>
                <span class="pill-status pill-pending">pending</span>
              <?php endif; ?>
            </h3>
            <div class="meta">
              by
              <a href="profile.php?id=<?= $ownerId ?>">
                <?= e($ownerName) ?>
              </a>
              · <?= e($created) ?>
              <?php if (!empty($r['main_slug'])): ?>
                · in <a href="index.php?main=<?= e($r['main_slug']) ?>"><?= e($r['main_name'] ?? 'Category') ?></a>
              <?php endif; ?>
              <?php if (!empty($r['trigger_word'])): ?>
                · trigger: “<?= e($r['trigger_word']) ?>”
              <?php elseif (!empty($r['trigger_source'])): ?>
                · source: <?= e($r['trigger_source']) ?>
              <?php endif; ?>
            </div>

            <p><?= e($excerpt) ?></p>

            <?php if ($r['flagged_at']): ?>
              <div class="meta">
                Flagged at: <?= e(date('M j, Y g:i a', strtotime($r['flagged_at']))) ?>
              </div>
            <?php endif; ?>

            <div class="mod-actions">
              <?php if ($canApprove): ?>
                <form method="post" class="inline" onsubmit="return confirm('Approve this post and make it live?');">
                  <input type="hidden" name="action" value="approve_post">
                  <input type="hidden" name="post_id" value="<?= (int)$r['post_id'] ?>">
                  <button type="submit" class="btn-soft btn-approve">Approve</button>
                </form>
              <?php else: ?>
                <div class="muted-note">
                  You can't approve your own post. Another moderator must review it.
                </div>
              <?php endif; ?>

              <form method="post" class="inline" onsubmit="return confirm('Reject this post and remove it?');">
                <input type="hidden" name="action" value="reject_post">
                <input type="hidden" name="post_id" value="<?= (int)$r['post_id'] ?>">
                <button type="submit" class="btn-soft btn-reject">Reject</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>


