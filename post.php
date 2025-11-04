<?php
declare(strict_types=1);
session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once "database.php";

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function redirect(string $url): never { header("Location: $url"); exit; }

function load_lexicon(string $path): array {
  if (!is_file($path) || !is_readable($path)) return [];
  $out = [];
  foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ln) {
    $t = trim($ln);
    if ($t !== '' && $t[0] !== '#') $out[] = mb_strtolower($t, 'UTF-8');
  }
  return $out;
}
function scan_lexicon(array $terms, string $text): array {
  if (!$terms) return [0, null];
  $hay = ' ' . mb_strtolower($text, 'UTF-8') . ' ';
  $hits = 0; $first = null;
  foreach ($terms as $raw) {
    $term = mb_strtolower(trim($raw), 'UTF-8');
    if ($term === '') continue;
    $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/u';
    if (preg_match($pattern, $hay)) { $hits++; if ($first === null) $first = $term; }
  }
  return [$hits, $first];
}

$conn = Database::dbConnect();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user      = $_SESSION['user'] ?? null;
$loggedIn  = (bool)$user;
$userId    = $loggedIn ? (int)$user['user_id'] : 0;
$userRole  = $loggedIn ? ($user['role'] ?? 'registered') : 'guest';
$isMod     = in_array($userRole, ['moderator','admin'], true);
$isAdmin   = ($userRole === 'admin');

$LEXICON = load_lexicon(__DIR__ . '/bad_words.txt');

/* ---------- load post ---------- */
$postId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($postId <= 0) { http_response_code(404); exit('Invalid post ID.'); }

$ps = $conn->prepare("
  SELECT p.*, u.username, u.display_name,
         mc.name AS main_name, mc.slug AS main_slug
  FROM post p
  JOIN users u ON u.user_id = p.user_id
  LEFT JOIN main_category mc ON mc.main_category_id = p.main_category_id
  WHERE p.post_id = :pid
");
$ps->execute([':pid'=>$postId]);
$post = $ps->fetch(PDO::FETCH_ASSOC);
if (!$post) { http_response_code(404); exit('Post not found.'); }

$isPostOwner = $loggedIn && ((int)$post['user_id'] === $userId);

/* ---------- actions (simple) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'add_comment' && $loggedIn) {
    $body = trim($_POST['comment_body'] ?? '');
    if ($body !== '') {
      [$hits, $first] = scan_lexicon($LEXICON, $body);
      $status = $hits > 0 ? 'flagged' : 'live';

      $conn->prepare("
        INSERT INTO comment (post_id, user_id, body, content_status)
        VALUES (:pid, :uid, :body, :status)
      ")->execute([
        ':pid'=>$postId, ':uid'=>$userId, ':body'=>$body, ':status'=>$status
      ]);

      if ($status === 'flagged') {
        // optional: record a flag row
        $conn->prepare("
          INSERT INTO flag (post_id, trigger_source, flagged_by_id, trigger_hits, trigger_word, status, notes)
          VALUES (:pid, 'lexicon', :uid, :hits, :word, 'flagged', 'comment auto-flagged')
        ")->execute([':pid'=>$postId, ':uid'=>$userId, ':hits'=>$hits, ':word'=>$first]);
      }
    }
    redirect("post.php?id=$postId");
  }

  if ($action === 'delete_comment') {
    $cid = (int)$_POST['comment_id'] ?? 0;
    if ($cid > 0) {
      // only owner OR admin
      $own = $conn->prepare("SELECT user_id FROM comment WHERE comment_id=:cid AND post_id=:pid");
      $own->execute([':cid'=>$cid, ':pid'=>$postId]);
      $row = $own->fetch(PDO::FETCH_ASSOC);
      if ($row && ($isAdmin || ($loggedIn && (int)$row['user_id'] === $userId))) {
        $conn->prepare("UPDATE comment SET content_status='deleted' WHERE comment_id=:cid")
             ->execute([':cid'=>$cid]);
      }
    }
    redirect("post.php?id=$postId");
  }

  if ($action === 'flag_comment' && ($isMod || $isAdmin)) {
    $cid    = (int)($_POST['comment_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    if ($cid > 0) {
      $conn->prepare("UPDATE comment SET content_status='flagged' WHERE comment_id=:cid AND post_id=:pid")
           ->execute([':cid'=>$cid, ':pid'=>$postId]);

      $note = 'comment#'.$cid . ($reason !== '' ? (': '.$reason) : '');
      $conn->prepare("
        INSERT INTO flag (post_id, trigger_source, flagged_by_id, trigger_hits, trigger_word, status, notes)
        VALUES (:pid,'manual',:uid,1,NULL,'flagged',:notes)
      ")->execute([':pid'=>$postId, ':uid'=>$userId ?: null, ':notes'=>$note]);
    }
    redirect("post.php?id=$postId");
  }

  if ($action === 'delete_post') {
    // only owner OR admin
    if ($isAdmin || $isPostOwner) {
      $conn->prepare("UPDATE post SET content_status='deleted' WHERE post_id=:pid")->execute([':pid'=>$postId]);
    }
    redirect('./index.php');
  }

  if ($action === 'flag_post' && ($isMod || $isAdmin)) {
    $reason = trim($_POST['reason'] ?? '');
    $conn->prepare("UPDATE post SET content_status='flagged' WHERE post_id=:pid")->execute([':pid'=>$postId]);
    $conn->prepare("
      INSERT INTO flag (post_id, trigger_source, flagged_by_id, trigger_hits, trigger_word, status, notes)
      VALUES (:pid,'manual',:uid,1,NULL,'flagged',:notes)
    ")->execute([':pid'=>$postId, ':uid'=>$userId ?: null, ':notes'=>$reason !== '' ? $reason : null]);
    redirect("post.php?id=$postId");
  }
}

/* ---------- data for view ---------- */
$scq = $conn->prepare("
  SELECT s.subcategory_id, s.name, s.slug
  FROM post_subcategory ps
  JOIN subcategory s ON s.subcategory_id = ps.subcategory_id
  WHERE ps.post_id = :pid
  ORDER BY s.name
");
$scq->execute([':pid'=>$postId]);
$subcats = $scq->fetchAll(PDO::FETCH_ASSOC) ?: [];

$mediaSql = "SELECT media_id, filename, type, moderation_status FROM media WHERE post_id=:pid";
$params   = [':pid'=>$postId];
if (!($isMod || $isAdmin)) { $mediaSql .= " AND moderation_status='approved'"; }
$ms = $conn->prepare($mediaSql." ORDER BY media_id ASC");
$ms->execute($params);
$mediaItems = $ms->fetchAll(PDO::FETCH_ASSOC) ?: [];

$cmt = $conn->prepare("
  SELECT c.*, u.username, u.display_name
  FROM comment c
  JOIN users u ON u.user_id = c.user_id
  WHERE c.post_id=:pid AND c.content_status='live'
  ORDER BY c.created_at ASC
");
$cmt->execute([':pid'=>$postId]);
$comments = $cmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= e($post['title']) ?> — Cat Corner</title>
  <link rel="stylesheet" href="css/style.css">
  <style>
    .comment-actions-row{display:flex;justify-content:flex-end;gap:.5rem;margin-top:.5rem}
    .btn-quiet{background:#eef2f0;border:1px solid #cfd7d3;padding:.35rem .7rem;border-radius:999px}
  </style>
</head>
<body>
  <!-- Nav -->
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

      <?php if ($isMod || $isAdmin): ?>
        <a href="mod_flags.php" class="nav-link">Moderation Queue</a>
      <?php endif; ?>
      <?php if ($isAdmin): ?>
        <a href="admin_logs.php" class="nav-link">Admin Logs</a>
        <a href="promote_user.php" class="nav-link">Promote Users</a>
      <?php endif; ?>
    </div>
    <div class="nav-right">
      <?php if ($loggedIn): ?>
        <span class="pill"><?= e($user['display_name'] ?? $user['username']) ?> (<?= e($userRole) ?>)</span>
        <a class="btn-outline" href="logout.php">Log out</a>
      <?php else: ?>
        <a class="btn-outline" href="login.php">Sign in</a>
      <?php endif; ?>
      <a href="about_us.php" class="nav-link">About Us</a>
    </div>
  </nav>

  <!-- Page -->
  <main class="container narrow post-page">
    <article class="card full-post">
      <header class="post-header">
        <div class="post-title-wrap">
          <div class="post-titleline">
            <h1 style="margin:0"><?= e($post['title']) ?></h1>
            <?php if ($subcats): ?>
              <div class="title-chips">
                <?php foreach ($subcats as $sc): ?>
                  <span class="title-chip"><?= e($sc['name']) ?></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="meta muted">
            by
            <a href="profile.php?id=<?= (int)$post['user_id'] ?>">
              <?= e($post['display_name'] ?? $post['username'] ?? 'anonymous') ?>
            </a>
            · <?= e(date('M j, Y g:i a', strtotime($post['created_at']))) ?>
            <?php if (!empty($post['main_slug'])): ?>
              · in <a href="index.php?main=<?= e($post['main_slug']) ?>"><?= e($post['main_name'] ?? 'Category') ?></a>
            <?php endif; ?>
          </div>
        </div>

        <div class="post-actions-top">
          <?php if ($isPostOwner || $isAdmin): ?>
            <form method="post" class="inline" onsubmit="return confirm('Delete this post?');">
              <input type="hidden" name="action" value="delete_post">
              <button class="btn-quiet">Delete</button>
            </form>
          <?php endif; ?>
          <?php if ($isMod || $isAdmin): ?>
            <form method="post" class="inline" onsubmit="return flagPostPrompt(this);">
              <input type="hidden" name="action" value="flag_post">
              <input type="hidden" name="reason" value="">
              <button class="btn-quiet">Flag</button>
            </form>
          <?php endif; ?>
        </div>
      </header>

      <div class="post-body prose">
        <p><?= nl2br(e($post['body'])) ?></p>

        <?php if ($mediaItems): ?>
          <div class="post-media" style="margin-top:1rem; display:grid; gap:1rem;">
            <?php foreach ($mediaItems as $m): ?>
              <div>
                <?php if ($m['type'] === 'video'): ?>
                  <video controls preload="metadata" style="max-width:100%;height:auto;border-radius:8px">
                    <source src="<?= e($m['filename']) ?>" type="video/mp4">
                  </video>
                <?php else: ?>
                  <img src="<?= e($m['filename']) ?>" alt="Post media" style="max-width:100%;height:auto;border-radius:8px">
                <?php endif; ?>
                <?php if (($isMod || $isAdmin) && $m['moderation_status'] !== 'approved'): ?>
                  <div class="muted" style="margin:.25rem 0">[<?= e($m['moderation_status']) ?>]</div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </article>

    <!-- Comments -->
    <section class="comments">
      <h2>Comments</h2>

      <?php if ($loggedIn): ?>
        <form method="post" class="form comment-form">
          <textarea name="comment_body" rows="4" required placeholder="Write your comment..."></textarea>
          <input type="hidden" name="action" value="add_comment">
          <button type="submit" class="btn">Post Comment</button>
        </form>
      <?php else: ?>
        <p class="muted">Sign in to leave a comment.</p>
      <?php endif; ?>

      <?php if (!$comments): ?>
        <div class="card empty">No comments yet. Be the first!</div>
      <?php else: ?>
        <ul class="comment-list">
          <?php foreach ($comments as $c): ?>
            <?php $canDelete = ($loggedIn && (int)$c['user_id'] === $userId) || $isAdmin; ?>
            <?php $canFlag   = $isMod || $isAdmin; ?>
            <li class="card comment-item">
              <div class="meta">
                <strong>
                  <a href="profile.php?id=<?= (int)$c['user_id'] ?>">
                    <?= e($c['display_name'] ?? $c['username'] ?? 'user') ?>
                  </a>
                </strong>
                · <?= e(date('M j, Y g:i a', strtotime($c['created_at']))) ?>
              </div>
              <p><?= nl2br(e($c['body'])) ?></p>

              <div class="comment-actions-row">
                <?php if ($canFlag): ?>
                  <form method="post" class="inline" onsubmit="return flagCommentPrompt(this);">
                    <input type="hidden" name="action" value="flag_comment">
                    <input type="hidden" name="comment_id" value="<?= (int)$c['comment_id'] ?>">
                    <input type="hidden" name="reason" value="">
                    <button class="btn-quiet">Flag</button>
                  </form>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                  <form method="post" class="inline" onsubmit="return confirm('Delete your comment?');">
                    <input type="hidden" name="action" value="delete_comment">
                    <input type="hidden" name="comment_id" value="<?= (int)$c['comment_id'] ?>">
                    <button class="btn-quiet">Delete</button>
                  </form>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </main>

  <script>
    function flagCommentPrompt(form){
      var r = prompt('Reason for flag (optional):');
      if (r === null) return false;
      form.querySelector('input[name="reason"]').value = (r || '').trim();
      return true;
    }
    function flagPostPrompt(form){
      var r = prompt('Reason for flag (optional):');
      if (r === null) return false;
      form.querySelector('input[name="reason"]').value = (r || '').trim();
      return true;
    }
  </script>
</body>
</html>

