<?php
// mod_flags.php — moderator queue (approve / remove)
declare(strict_types=1);
session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once "database.php";
$conn = Database::dbConnect();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------------------------
// auth: must be logged in and moderator/admin
// -----------------------------------------------------------------------------
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: ./login.php'); exit; }

$role   = $user['role']   ?? 'registered';
$status = $user['status'] ?? 'active';
if ($status !== 'active' || !in_array($role, ['moderator','admin'], true)) {
  http_response_code(403);
  echo "<h2>Not allowed</h2><p>Moderator access required.</p>";
  exit;
}

$errors = [];
$notices = [];

// -----------------------------------------------------------------------------
// handle approve / remove actions
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $postId = (int)($_POST['post_id'] ?? 0);
  $reason = trim($_POST['reason'] ?? '');

  if ($postId <= 0) {
    $errors[] = 'Invalid post ID.';
  } else {
    try {
      $conn->beginTransaction();

      $chk = $conn->prepare("SELECT content_status FROM post WHERE post_id = :pid FOR UPDATE");
      $chk->execute([':pid' => $postId]);
      $row = $chk->fetch(PDO::FETCH_ASSOC);
      if (!$row) throw new RuntimeException('Post not found.');
      if ($row['content_status'] !== 'flagged') throw new RuntimeException('Post is not flagged.');
      // APPROVE
      if ($action === 'approve') {
        $conn->prepare("UPDATE post SET content_status = 'live' WHERE post_id = :pid")
             ->execute([':pid' => $postId]);

        $conn->prepare("
          UPDATE flag
          SET status = 'approved',
              moderator_id = :mod,
              decided_at = NOW(),
              notes = CASE
                        WHEN COALESCE(TRIM(notes), '') = '' AND :notes <> '' THEN :notes
                        WHEN :notes <> '' THEN CONCAT(notes, ' | ', :notes)
                        ELSE notes
                      END
          WHERE post_id = :pid AND status = 'flagged'
        ")->execute([
          ':mod'   => (int)$user['user_id'],
          ':pid'   => $postId,
          ':notes' => $reason,
        ]);

        // ✅ Log moderator action
        $conn->prepare("
          INSERT INTO moderation_log (moderator_id, post_id, action, reason)
          VALUES (:mod, :pid, 'approved', :reason)
        ")->execute([
          ':mod'    => (int)$user['user_id'],
          ':pid'    => $postId,
          ':reason' => $reason !== '' ? $reason : 'approved by moderator/admin'
        ]);

        $notices[] = 'Post approved.';
      }

      // REMOVE
      elseif ($action === 'remove') {
        $conn->prepare("UPDATE post SET content_status = 'rejected' WHERE post_id = :pid")
             ->execute([':pid' => $postId]);

        $conn->prepare("
          UPDATE flag
          SET status = 'rejected',
              moderator_id = :mod,
              decided_at = NOW(),
              notes = CASE
                        WHEN COALESCE(TRIM(notes), '') = '' AND :notes <> '' THEN :notes
                        WHEN :notes <> '' THEN CONCAT(notes, ' | ', :notes)
                        ELSE notes
                      END
          WHERE post_id = :pid AND status = 'flagged'
        ")->execute([
          ':mod'   => (int)$user['user_id'],
          ':pid'   => $postId,
          ':notes' => $reason !== '' ? $reason : 'removed by moderator',
        ]);

        // Log moderator rejection
        $conn->prepare("
        INSERT INTO moderation_log (moderator_id, post_id, action, reason)
        VALUES (:mod, :pid, 'rejected', :reason)
      ")->execute([
        ':mod'    => (int)$user['user_id'],
        ':pid'    => $postId,
        ':reason' => $reason !== '' ? $reason : 'removed by moderator/admin'
      ]);

        $notices[] = 'Post removed.';
      }



      else {
        throw new RuntimeException('Unknown action.');
      }

      $conn->commit();
    } catch (Throwable $t) {
      if ($conn->inTransaction()) $conn->rollBack();
      $errors[] = 'Action failed: ' . e($t->getMessage());
    }
  }
}

// -----------------------------------------------------------------------------
// load flagged posts
// -----------------------------------------------------------------------------
$flagged = [];
try {
  $q = $conn->query("
  SELECT
    p.post_id, p.title, p.body, p.created_at, p.content_status,
    u.username, u.display_name,
    mc.name AS main_name, mc.slug AS main_slug,
    f.flag_id, f.trigger_source, f.trigger_hits, f.trigger_word, f.created_at AS flagged_at
  FROM post p
  JOIN users u ON u.user_id = p.user_id
  LEFT JOIN main_category mc ON mc.main_category_id = p.main_category_id
  LEFT JOIN flag f ON f.post_id = p.post_id AND f.status = 'flagged'
  WHERE p.content_status = 'flagged'
  ORDER BY COALESCE(f.created_at, p.created_at) DESC
  LIMIT 100
");
  $flagged = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  $errors[] = 'Failed to load flagged posts.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Moderation Queue — Cat Corner</title>
    <link href="css/style.css" rel="stylesheet" type="text/css">
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

      <?php if (in_array($user['role'] ?? '', ['moderator', 'admin'])): ?>
        <a href="mod_flags.php" class="nav-link">Moderation Queue</a>
      <?php endif; ?>

      <?php if (($user['role'] ?? '') === 'admin'): ?>
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
  <main class="container mod-wrap">
    <div class="mod-head">
      <h1>Moderation Queue</h1>
      <span class="badge badge-flag"><?= count($flagged) ?> flagged</span>
    </div>
    <p class="sub">Review flagged posts. Approve to publish, or Remove to hide permanently.</p>

    <?php if ($errors): ?>
      <div class="card error-card">
        <strong>Errors</strong>
        <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <?php if ($notices): ?>
      <div class="card notice-card">
        <?php foreach ($notices as $n): ?><p><?= e($n) ?></p><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!$flagged): ?>
      <div class="card flag-card"><strong>No flagged posts.</strong><p>All clear!</p></div>
    <?php else: ?>
      <div class="queue">
        <?php foreach ($flagged as $row): ?>
          <?php
            $author = $row['display_name'] ?: $row['username'] ?: 'anonymous';
            $created = date('M j, Y g:i a', strtotime($row['created_at']));
            $flaggedAt = $row['flagged_at'] ? date('M j, Y g:i a', strtotime($row['flagged_at'])) : '—';
            $excerpt = mb_strimwidth((string)$row['body'], 0, 360, '…');
          ?>
          <article class="card flag-card">
            <h3><a href="post.php?id=<?= (int)$row['post_id'] ?>" target="_blank"><?= e($row['title'] ?: '[no title]') ?></a></h3>
            <div class="meta">
              by <?= e($author) ?> · <?= e($created) ?>
              <?php if (!empty($row['main_slug'])): ?>
                · in <a href="index.php?main=<?= e($row['main_slug']) ?>"><?= e($row['main_name'] ?? 'category') ?></a>
              <?php endif; ?>
              <?php if (!empty($row['trigger_word'])): ?>
                · trigger: “<?= e($row['trigger_word']) ?>”
              <?php endif; ?>
            </div>
            <p><?= e($excerpt) ?></p>
            <form method="post" class="actions">
              <input type="hidden" name="post_id" value="<?= (int)$row['post_id'] ?>">
              <input type="text" name="reason" placeholder="Optional note">
              <button name="action" value="approve" class="btn">Approve</button>
              <button name="action" value="remove" class="btn-outline">Remove</button>
            </form>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>
