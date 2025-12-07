<?php
// my_reviews.php 
declare(strict_types=1);
session_start();
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once "database.php";

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$user = $_SESSION['user'] ?? null;
if (!$user) {
  header('Location: ./login.php');
  exit;
}

$userId = (int)$user['user_id'];
$role   = $user['role'] ?? 'registered';

$errors   = [];
$info     = [];

$conn = Database::dbConnect();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'delete_comment') {
    $cid = (int)($_POST['comment_id'] ?? 0);

    if ($cid > 0) {
      try {
        // only delete if this comment belongs to the current user, is flagged
        $del = $conn->prepare("
          UPDATE comment
          SET content_status = 'deleted'
          WHERE comment_id = :cid
            AND user_id = :uid
            AND content_status = 'flagged'
        ");
        $del->execute([
          ':cid' => $cid,
          ':uid' => $userId
        ]);

        if ($del->rowCount() > 0) {
          $info[] = "Comment #{$cid} deleted.";
        } else {
          $errors[] = "You can only delete your own flagged comments.";
        }
      } catch (Throwable $t) {
        $errors[] = "Failed to delete that comment.";
      }
    }

    header('Location: my_reviews.php');
    exit;
  }
}


//loads the users posts in review
$posts = [];
try {
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
      f.created_at AS flagged_at,
      m.has_pending_media
    FROM post p
    LEFT JOIN main_category mc ON mc.main_category_id = p.main_category_id

    -- latest flag row per post (if any)
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

    -- any media needing review for this post
    LEFT JOIN (
      SELECT post_id, 1 AS has_pending_media
      FROM media
      WHERE moderation_status = 'pending'
      GROUP BY post_id
    ) m ON m.post_id = p.post_id

    WHERE p.user_id = :uid
      AND (
        p.content_status IN ('pending','flagged')
        OR m.has_pending_media IS NOT NULL
      )
    ORDER BY p.created_at DESC
    LIMIT 200
  ");
  $stmt->execute([':uid' => $userId]);
  $posts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $t) {
  $errors[] = 'Failed to load your in-review posts.';
}

// lods the users comment in review
$comments = [];
try {
  $cstmt = $conn->prepare("
    SELECT
      c.comment_id,
      c.post_id,
      c.body,
      c.created_at,
      c.content_status,
      p.title AS post_title,
      mc.name AS main_name,
      mc.slug AS main_slug
    FROM comment c
    JOIN post p ON p.post_id = c.post_id
    LEFT JOIN main_category mc ON mc.main_category_id = p.main_category_id
    WHERE c.user_id = :uid
      AND c.content_status = 'flagged'
    ORDER BY c.created_at DESC
    LIMIT 200
  ");
  $cstmt->execute([':uid' => $userId]);
  $comments = $cstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $t) {
  $errors[] = 'Failed to load your in-review comments.';
}

$totalItems = count($posts) + count($comments);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Currently in Review — Cat Corner</title>
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
        <a href="my_reviews.php" class="nav-link active">My Reviews</a>
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
      <span class="badge"><?= $totalItems ?> total</span>
      <span class="kind-pill">Posts: <?= count($posts) ?></span>
      <span class="kind-pill">Comments: <?= count($comments) ?></span>
    </div>
    <p class="sub">These are your posts and your comments that are awaiting review.</p>

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

    <!-- posts -->
    <div class="section-head">
      <h2 style="margin:0;">Your Posts</h2>
      <span class="badge"><?= count($posts) ?> in review</span>
    </div>

    <?php if (!count($posts)): ?>
      <div class="card"><strong>All clear!</strong> You have no posts in review.</div>
    <?php else: ?>
      <div class="review-list">
        <?php foreach ($posts as $r): ?>
          <?php
            $created  = date('M j, Y g:i a', strtotime($r['created_at']));
            $excerpt  = mb_strimwidth((string)$r['body'], 0, 280, '…');
            $status   = $r['content_status'];
            $hasMedia = !empty($r['has_pending_media']);
          ?>
          <article class="card">
            <h3 style="margin-top:0;">
              <a href="post.php?id=<?= (int)$r['post_id'] ?>" target="_blank">
                <?= e($r['title'] ?: '[no title]') ?>
              </a>
              <?php if ($status === 'flagged' || $status === 'pending' || $hasMedia): ?>
                <span class="pill-status pill-soft-review">In review</span>
              <?php endif; ?>
            </h3>
            <div class="meta">
              <?= e($created) ?>
              <?php if (!empty($r['main_slug'])): ?>
                · in <a href="index.php?main=<?= e($r['main_slug']) ?>"><?= e($r['main_name'] ?? 'Category') ?></a>
              <?php endif; ?>

              <?php if (!empty($r['trigger_word'])): ?>
                · trigger: “<?= e($r['trigger_word']) ?>”
              <?php elseif (!empty($r['trigger_source'])): ?>
                · source: <?= e($r['trigger_source']) ?>
              <?php endif; ?>

              <?php if ($hasMedia): ?>
                · media under review
              <?php endif; ?>
            </div>
            <p><?= e($excerpt) ?></p>
            <div class="meta">
              <?php if (!empty($r['flagged_at'])): ?>
                Last flagged: <?= e(date('M j, Y g:i a', strtotime($r['flagged_at']))) ?>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- comments -->
    <div class="section-head">
      <h2 style="margin:0;">Your Comments</h2>
      <span class="badge"><?= count($comments) ?> in review</span>
    </div>

    <?php if (!count($comments)): ?>
      <div class="card"><strong>All clear!</strong> You have no comments in review.</div>
    <?php else: ?>
      <div class="review-list">
        <?php foreach ($comments as $c): ?>
          <?php
            $cCreated = date('M j, Y g:i a', strtotime($c['created_at']));
            $cExcerpt = mb_strimwidth((string)$c['body'], 0, 280, '…');
            $cStatus  = $c['content_status'];
            $postId   = (int)$c['post_id'];
            $cid      = (int)$c['comment_id'];
          ?>
          <article class<?php echo "card"; ?>>
            <h3 style="margin-top:0;">
              <a href="post.php?id=<?= $postId ?>#comment-<?= $cid ?>" target="_blank">
                in: <?= e($c['post_title'] ?: 'post') ?>
              </a>
              <?php if ($cStatus === 'flagged'): ?>
                <span class="pill-status pill-soft-review">In review</span>
              <?php endif; ?>
            </h3>
            <div class="meta">
              <?= e($cCreated) ?>
              <?php if (!empty($c['main_slug'])): ?>
                · in <a href="index.php?main=<?= e($c['main_slug']) ?>"><?= e($c['main_name'] ?? 'Category') ?></a>
              <?php endif; ?>
            </div>
            <p><?= e($cExcerpt) ?></p>

            <div class="mod-actions">
              <form method="post" class="inline"
                    onsubmit="return confirm('Delete this comment? This cannot be undone.');">
                <input type="hidden" name="action" value="delete_comment">
                <input type="hidden" name="comment_id" value="<?= $cid ?>">
                <button type="submit" class="btn-soft btn-reject">Delete</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </main>
</body>
</html>
