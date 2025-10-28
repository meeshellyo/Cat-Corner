<?php
// mod_flags.php — Moderation queue (Content + Comments)
declare(strict_types=1);
session_start();

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . "/database.php";
$conn = Database::dbConnect();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$user   = $_SESSION['user'] ?? null;
if (!$user) { header('Location: ./login.php'); exit; }
$role   = $user['role']   ?? 'registered';
$status = $user['status'] ?? 'active';
if ($status !== 'active' || !in_array($role, ['moderator','admin'], true)) {
  http_response_code(403);
  echo "<h2>Access Denied</h2><p>Moderator privileges required.</p>";
  exit;
}

$view = ($_GET['view'] ?? 'content');
if (!in_array($view, ['content','comments'], true)) $view = 'content';

$errors = [];
$notices = [];

/* ---------------- Actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $reason = trim($_POST['reason'] ?? '');
  $mid    = (int)$user['user_id'];

  try {
    if ($view === 'content') {
      $pid = (int)($_POST['post_id'] ?? 0);
      if ($pid > 0) {
        $conn->beginTransaction();

        if ($action === 'approve') {
          $conn->prepare("UPDATE post SET content_status='live' WHERE post_id=:pid")->execute([':pid'=>$pid]);
          $conn->prepare("UPDATE media SET moderation_status='approved' WHERE post_id=:pid AND moderation_status='pending'")->execute([':pid'=>$pid]);
          // close only post-level flags (not comment flags)
          $conn->prepare("
            UPDATE flag
               SET status='approved', moderator_id=:mid, decided_at=NOW()
             WHERE post_id=:pid AND status='flagged' AND (notes IS NULL OR notes NOT LIKE 'comment%')
          ")->execute([':mid'=>$mid, ':pid'=>$pid]);
          $notices[] = 'Post approved.';
        }

        if ($action === 'remove') {
          $conn->prepare("UPDATE post SET content_status='rejected' WHERE post_id=:pid")->execute([':pid'=>$pid]);
          $conn->prepare("UPDATE media SET moderation_status='rejected' WHERE post_id=:pid")->execute([':pid'=>$pid]);
          $conn->prepare("
            UPDATE flag
               SET status='rejected', moderator_id=:mid, decided_at=NOW()
             WHERE post_id=:pid AND status='flagged' AND (notes IS NULL OR notes NOT LIKE 'comment%')
          ")->execute([':mid'=>$mid, ':pid'=>$pid]);
          $notices[] = 'Post removed.';
        }

        $conn->commit();
      }
    }

    if ($view === 'comments') {
      $cid = (int)($_POST['comment_id'] ?? 0);
      $pid = (int)($_POST['post_id'] ?? 0);
      if ($cid > 0 && $pid > 0) {
        $conn->beginTransaction();

        if ($action === 'approve') {
          $conn->prepare("UPDATE comment SET content_status='live' WHERE comment_id=:cid")->execute([':cid'=>$cid]);
          // close either note style
          $conn->prepare("
            UPDATE flag
               SET status='approved', moderator_id=:mid, decided_at=NOW()
             WHERE post_id=:pid AND status='flagged'
               AND (notes LIKE :tag OR notes='comment auto-flagged')
          ")->execute([':mid'=>$mid, ':pid'=>$pid, ':tag'=>'comment#'.$cid.'%']);
          $notices[] = 'Comment approved.';
        }

        if ($action === 'remove') {
          $conn->prepare("UPDATE comment SET content_status='deleted' WHERE comment_id=:cid")->execute([':cid'=>$cid]);
          $conn->prepare("
            UPDATE flag
               SET status='rejected', moderator_id=:mid, decided_at=NOW()
             WHERE post_id=:pid AND status='flagged'
               AND (notes LIKE :tag OR notes='comment auto-flagged')
          ")->execute([':mid'=>$mid, ':pid'=>$pid, ':tag'=>'comment#'.$cid.'%']);
          $notices[] = 'Comment removed.';
        }

        $conn->commit();
      }
    }
  } catch (Throwable $t) {
    if ($conn->inTransaction()) $conn->rollBack();
    $errors[] = 'Action failed: ' . e($t->getMessage());
  }
}

/* ---------------- Load queues ---------------- */
if ($view === 'content') {
  // Only post-level flags: (notes IS NULL OR NOT LIKE 'comment%')
  $stmt = $conn->query("
    SELECT DISTINCT
      p.post_id, p.title, p.body, p.created_at, p.content_status,
      u.username, u.display_name,
      f.trigger_hits, f.trigger_word
    FROM post p
    JOIN users u ON u.user_id = p.user_id
    LEFT JOIN flag f
      ON f.post_id = p.post_id
     AND f.status = 'flagged'
     AND (f.notes IS NULL OR f.notes NOT LIKE 'comment%')
    LEFT JOIN media m
      ON m.post_id = p.post_id
     AND m.moderation_status = 'pending'
    WHERE p.content_status = 'flagged'
       OR m.media_id IS NOT NULL
       OR f.flag_id IS NOT NULL
    ORDER BY p.created_at DESC
  ");
  $posts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // pending media (thumbnails) for each post
  $pendingByPost = [];
  if ($posts) {
    $ids = array_map(static fn($r)=>(int)$r['post_id'], $posts);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $ms  = $conn->prepare("
      SELECT media_id, post_id, filename, type
      FROM media
      WHERE moderation_status='pending' AND post_id IN ($in)
      ORDER BY media_id ASC
    ");
    $ms->execute($ids);
    foreach ($ms->fetchAll(PDO::FETCH_ASSOC) as $m) {
      $pendingByPost[(int)$m['post_id']][] = $m;
    }
  }
} else {
  // Flagged comments + look up their trigger info (supports both note styles)
  $stmt = $conn->query("
    SELECT
      c.comment_id, c.post_id, c.body, c.created_at,
      u.username, u.display_name,
      -- pull trigger info from matching flag row if present
      (
        SELECT f.trigger_hits
          FROM flag f
         WHERE f.post_id = c.post_id
           AND f.status = 'flagged'
           AND (f.notes LIKE CONCAT('comment#', c.comment_id, '%')
                OR f.notes = 'comment auto-flagged')
         ORDER BY f.flag_id DESC
         LIMIT 1
      ) AS trig_hits,
      (
        SELECT f.trigger_word
          FROM flag f
         WHERE f.post_id = c.post_id
           AND f.status = 'flagged'
           AND (f.notes LIKE CONCAT('comment#', c.comment_id, '%')
                OR f.notes = 'comment auto-flagged')
         ORDER BY f.flag_id DESC
         LIMIT 1
      ) AS trig_word
    FROM comment c
    JOIN users u ON u.user_id = c.user_id
    WHERE c.content_status='flagged'
    ORDER BY c.created_at DESC
  ");
  $comments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Moderation Queue — Cat Corner</title>
  <link rel="stylesheet" href="css/style.css">
  <style>
    .pending-media-grid{display:flex;flex-wrap:wrap;gap:.5rem;margin:.5rem 0}
    .pending-media-grid img,.pending-media-grid video{max-width:220px;border-radius:8px;display:block}
    .actions{display:flex;gap:.5rem;align-items:center}
    .muted{color:#6b7280}
    .trigger-info{color:#b03030;font-weight:bold;margin:.25rem 0}
    .subtabs a{margin-right:.75rem}
  </style>
</head>
<body>
  <nav class="nav">
    <div class="nav-left">
      <a class="brand" href="index.php">
        <img src="doodles/cat_corner_logo.jpg" alt="Cat Corner logo">
        <span>Cat Corner</span>
      </a>
    </div>
    <div class="nav-center">
      <a href="index.php" class="nav-link">Home</a>
      <a href="mod_flags.php" class="nav-link active">Moderation Queue</a>
      <?php if ($role === 'admin'): ?>
        <a href="admin_logs.php" class="nav-link">Admin Logs</a>
        <a href="promote_user.php" class="nav-link">Promote Users</a>
      <?php endif; ?>
    </div>
    <div class="nav-right">
      <span class="pill"><?= e($user['display_name'] ?? $user['username']) ?> (<?= e($role) ?>)</span>
      <a class="btn-outline" href="logout.php">Log out</a>
    </div>
  </nav>

  <main class="container mod-wrap">
    <div class="mod-head">
      <h1>Moderation Queue</h1>
      <div class="subtabs">
        <a href="mod_flags.php?view=content"  class="<?= $view==='content'?'active-tab':'' ?>">Content</a>
        <a href="mod_flags.php?view=comments" class="<?= $view==='comments'?'active-tab':'' ?>">Comments</a>
      </div>
    </div>
    <p class="sub">Review flagged items. Approve to publish, or Remove to hide permanently.</p>

    <?php if ($errors): ?>
      <div class="card error-card"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($notices): ?>
      <div class="card notice-card"><?php foreach ($notices as $n): ?><p><?= e($n) ?></p><?php endforeach; ?></div>
    <?php endif; ?>

    <?php if ($view === 'content'): ?>
      <?php if (empty($posts)): ?>
        <div class="card flag-card"><strong>No posts need review.</strong></div>
      <?php else: ?>
        <?php foreach ($posts as $row): ?>
          <?php $pid = (int)$row['post_id']; ?>
          <article class="card flag-card">
            <h3><a href="post.php?id=<?= $pid ?>" target="_blank"><?= e($row['title'] ?: '[no title]') ?></a></h3>

            <?php if (!empty($row['trigger_word'])): ?>
              <div class="trigger-info">TriggerHit: <?= (int)$row['trigger_hits'] ?> | TriggerWord: <?= e($row['trigger_word']) ?></div>
            <?php endif; ?>

            <p><?= e(mb_strimwidth($row['body'] ?? '', 0, 360, '…')) ?></p>

            <?php if (!empty($pendingByPost[$pid])): ?>
              <div class="pending-media-grid">
                <?php foreach ($pendingByPost[$pid] as $m): ?>
                  <?php if ($m['type'] === 'video'): ?>
                    <video controls preload="metadata"><source src="<?= e($m['filename']) ?>" type="video/mp4"></video>
                  <?php else: ?>
                    <img src="<?= e($m['filename']) ?>" alt="pending media">
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <form method="post" class="actions">
              <input type="hidden" name="post_id" value="<?= $pid ?>">
              <input type="text"   name="reason"  placeholder="Optional note">
              <button name="action" value="approve" class="btn">Approve</button>
              <button name="action" value="remove"  class="btn-outline">Remove</button>
            </form>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    <?php else: /* comments */ ?>
      <?php if (empty($comments)): ?>
        <div class="card flag-card"><strong>No flagged comments.</strong></div>
      <?php else: ?>
        <?php foreach ($comments as $c): ?>
          <article class="card flag-card">
            <h3>Comment on <a href="post.php?id=<?= (int)$c['post_id'] ?>" target="_blank">post #<?= (int)$c['post_id'] ?></a></h3>

            <?php if (!empty($c['trig_word'])): ?>
              <div class="trigger-info">TriggerHit: <?= (int)$c['trig_hits'] ?> | TriggerWord: <?= e($c['trig_word']) ?></div>
            <?php endif; ?>

            <p><?= e(mb_strimwidth($c['body'], 0, 360, '…')) ?></p>

            <form method="post" class="actions">
              <input type="hidden" name="comment_id" value="<?= (int)$c['comment_id'] ?>">
              <input type="hidden" name="post_id"    value="<?= (int)$c['post_id'] ?>">
              <input type="text"   name="reason"     placeholder="Optional note">
              <button name="action" value="approve" class="btn">Approve</button>
              <button name="action" value="remove"  class="btn-outline">Remove</button>
            </form>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</body>
</html>
