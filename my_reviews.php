<?php
// my_reviews.php 
declare(strict_types=1);
session_start();

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once "database.php";
$conn = Database::dbConnect();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$user = $_SESSION['user'] ?? null;
if (!$user) {
  header('Location: ./login.php'); 
  exit;
}

$userId = (int)$user['user_id'];
$role   = $user['role'] ?? 'registered';

$errors = [];
$rows   = [];

try {
  // latest open flag per post (if any) + only user's posts that are pending/flagged
  $stmt = $conn->prepare("
    SELECT
      p.post_id,
      p.title,
      p.body,
      p.created_at,
      p.content_status,
      mc.name AS main_name,
      mc.slug AS main_slug,
      f.trigger_source,
      f.trigger_word,
      f.trigger_hits,
      f.created_at AS flagged_at
    FROM post p
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
    WHERE p.user_id = :uid
      AND p.content_status IN ('pending','flagged')
    ORDER BY p.created_at DESC
    LIMIT 200
  ");
  $stmt->execute([':uid' => $userId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $t) {
  $errors[] = 'Failed to load your in-review posts.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Currently in Review — Cat Corner</title>
  <link rel="stylesheet" href="css/style.css" type="text/css">
  <style>
    .review-head { display:flex; align-items:center; gap:.5rem; }
    .badge { background:#fff3cd; color:#7f5d00; padding:.15rem .5rem; border-radius:999px; font-size:.85rem; border:1px solid #ffe8a1; }
    .review-list { display:grid; grid-template-columns:1fr; gap:.75rem; margin-top:.75rem; }
    @media (min-width: 900px) { .review-list { grid-template-columns:1fr 1fr; } }
    .meta { color:#666; font-size:.9rem; margin:.25rem 0 .5rem; }
    .pill-status { padding:.05rem .45rem; border-radius:999px; border:1px solid #e0e0e0; font-size:.8rem; margin-left:.35rem; }
    .pill-flagged { background:#ffe3cf; color:#6b3d10; border-color:#ffd1b0; }
    .pill-pending { background:#e6f0ff; color:#0b3a7a; border-color:#cbddff; }
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
    <div class="review-head">
      <h1 style="margin:0;">Currently in Review</h1>
      <span class="badge"><?= count($rows) ?> pending</span>
    </div>
    <p class="sub">These are your posts that are awaiting review or are in the moderation queue.</p>

    <?php if ($errors): ?>
      <div class="card error-card">
        <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <?php if (!$rows): ?>
      <div class="card"><strong>All clear!</strong> You have no posts in review.</div>
    <?php else: ?>
      <div class="review-list">
        <?php foreach ($rows as $r): ?>
          <?php
            $created = date('M j, Y g:i a', strtotime($r['created_at']));
            $excerpt = mb_strimwidth((string)$r['body'], 0, 280, '…');
            $status  = $r['content_status'];
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
              <?= e($created) ?>
              <?php if (!empty($r['main_slug'])): ?>
                · in <a href="index.php?main=<?= e($r['main_slug']) ?>"><?= e($r['main_name'] ?? 'Category') ?></a>
              <?php endif; ?>
              <?php if (!empty($r['trigger_word'])): ?>
                · trigger: “<?= e($r['trigger_word']) ?>”
              <?php endif; ?>
            </div>
            <p><?= e($excerpt) ?></p>
            <div class="meta">
              <?php if ($r['flagged_at']): ?>
                Last reviewed: <?= e(date('M j, Y g:i a', strtotime($r['flagged_at']))) ?>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>
