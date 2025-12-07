<?php
// post.php
declare(strict_types=1);
session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once "database.php";

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function redirect(string $url): never {
    header("Location: $url");
    exit;
}

function avatar_url(?string $id): ?string {
    if ($id === null) return null;
    $id = trim($id);
    if ($id === '') return null;

    // already a full URL
    if (str_starts_with($id, 'http://') || str_starts_with($id, 'https://')) {
        return $id;
    }
    // already a doodles/ path
    if (str_starts_with($id, 'doodles/')) {
        return $id;
    }
    // just a bare name or filename
    if (strpos($id, '.') === false) {
        $id .= '.jpg';
    }
    return 'doodles/' . $id;
}

function load_lexicon(string $path): array {
    if (!is_file($path) || !is_readable($path)) return [];
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ln) {
        $t = trim($ln);
        if ($t !== '' && $t[0] !== '#') {
            $out[] = mb_strtolower($t, 'UTF-8');
        }
    }
    return $out;
}

function scan_lexicon(array $terms, string $text): array {
    if (!$terms) return [0, null];
    $hay   = ' ' . mb_strtolower($text, 'UTF-8') . ' ';
    $hits  = 0;
    $first = null;

    foreach ($terms as $raw) {
        $term = mb_strtolower(trim($raw), 'UTF-8');
        if ($term === '') continue;

        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/u';
        if (preg_match($pattern, $hay)) {
            $hits++;
            if ($first === null) {
                $first = $term;
            }
        }
    }
    return [$hits, $first];
}

$conn = Database::dbConnect();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user      = $_SESSION['user'] ?? null;
$loggedIn  = (bool)$user;
$userId    = $loggedIn ? (int)$user['user_id'] : 0;
$userRole  = $loggedIn ? ($user['role'] ?? 'registered') : 'guest';
$isMod     = in_array($userRole, ['moderator', 'admin'], true);
$isAdmin   = ($userRole === 'admin');

$LEXICON = load_lexicon(__DIR__ . '/bad_words.txt');

$postId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($postId <= 0) {
    http_response_code(404);
    exit('Invalid post ID.');
}

$ps = $conn->prepare("
    SELECT p.*, u.username, u.display_name, u.avatar_id,
           mc.name AS main_name, mc.slug AS main_slug
    FROM post p
    JOIN users u ON u.user_id = p.user_id
    LEFT JOIN main_category mc ON mc.main_category_id = p.main_category_id
    WHERE p.post_id = :pid
");
$ps->execute([':pid' => $postId]);
$post = $ps->fetch(PDO::FETCH_ASSOC);
if (!$post) {
    http_response_code(404);
    exit('Post not found.');
}

$isPostOwner = $loggedIn && ((int)$post['user_id'] === $userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_comment' && $loggedIn) {
        $body = trim($_POST['comment_body'] ?? '');
        if ($body !== '') {
            [$hits, $first] = scan_lexicon($LEXICON, $body);
            $status = $hits > 0 ? 'flagged' : 'live';

            $ins = $conn->prepare("
                INSERT INTO comment (post_id, user_id, body, content_status)
                VALUES (:pid, :uid, :body, :status)
            ");
            $ins->execute([
                ':pid'    => $postId,
                ':uid'    => $userId,
                ':body'   => $body,
                ':status' => $status,
            ]);
            $commentId = (int)$conn->lastInsertId();

            if ($status === 'flagged') {
                $conn->prepare("
                    INSERT INTO flag (
                        post_id, trigger_source, flagged_by_id,
                        trigger_hits, trigger_word, status, notes
                    ) VALUES (
                        :pid, 'lexicon', :uid,
                        :hits, :word, 'flagged', :notes
                    )
                ")->execute([
                    ':pid'   => $postId,
                    ':uid'   => $userId,
                    ':hits'  => $hits,
                    ':word'  => $first,
                    ':notes' => 'comment#' . $commentId . ' auto-flagged',
                ]);
            }
        }
        redirect("post.php?id=$postId");
    }

    if ($action === 'delete_comment') {
        $cid = (int)($_POST['comment_id'] ?? 0);
        if ($cid > 0) {
            // only owner OR admin
            $own = $conn->prepare("
                SELECT user_id 
                FROM comment 
                WHERE comment_id = :cid AND post_id = :pid
            ");
            $own->execute([':cid' => $cid, ':pid' => $postId]);
            $row = $own->fetch(PDO::FETCH_ASSOC);
            if ($row && ($isAdmin || ($loggedIn && (int)$row['user_id'] === $userId))) {
                $conn->prepare("
                    UPDATE comment 
                    SET content_status = 'deleted' 
                    WHERE comment_id = :cid
                ")->execute([':cid' => $cid]);
            }
        }
        redirect("post.php?id=$postId");
    }

    if ($action === 'flag_comment' && ($isMod || $isAdmin)) {
        $cid    = (int)($_POST['comment_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if ($cid > 0) {
            $conn->prepare("
                UPDATE comment 
                SET content_status = 'flagged' 
                WHERE comment_id = :cid AND post_id = :pid
            ")->execute([':cid' => $cid, ':pid' => $postId]);

            $note = 'comment#' . $cid . ($reason !== '' ? (': ' . $reason) : '');
            $conn->prepare("
                INSERT INTO flag (
                    post_id, trigger_source, flagged_by_id,
                    trigger_hits, trigger_word, status, notes
                ) VALUES (
                    :pid, 'manual', :uid,
                    1, NULL, 'flagged', :notes
                )
            ")->execute([
                ':pid'   => $postId,
                ':uid'   => $userId ?: null,
                ':notes' => $note,
            ]);
        }
        redirect("post.php?id=$postId");
    }

    if ($action === 'report_comment' && $loggedIn) {
        $cid    = (int)($_POST['comment_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if ($cid > 0) {
            // verify the comment belongs to this post
            $chk = $conn->prepare("
                SELECT user_id 
                FROM comment 
                WHERE comment_id = :cid AND post_id = :pid
            ");
            $chk->execute([':cid' => $cid, ':pid' => $postId]);
            $row = $chk->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                // comment stays 'live'
                $note = 'user report: comment#' . $cid;
                if ($reason !== '') {
                    $note .= ' - ' . $reason;
                }

                $insFlag = $conn->prepare("
                    INSERT INTO flag (
                        post_id, trigger_source, flagged_by_id,
                        trigger_hits, trigger_word, status, notes
                    ) VALUES (
                        :pid, 'manual', :uid,
                        1, NULL, 'flagged', :notes
                    )
                ");
                $insFlag->execute([
                    ':pid'   => $postId,
                    ':uid'   => $userId,
                    ':notes' => $note,
                ]);
            }
        }
        redirect("post.php?id=$postId");
    }

    if ($action === 'delete_post') {
        if ($isAdmin || $isPostOwner) {
            $conn->prepare("
                DELETE FROM post 
                WHERE post_id = :pid
            ")->execute([':pid' => $postId]);
        }
        redirect('./index.php');
    }

    if ($action === 'report_post' && $loggedIn) {
        $reason = trim($_POST['reason'] ?? '');

        $note = 'user report: post';
        if ($reason !== '') {
            $note .= ' - ' . $reason;
        }

        $conn->prepare("
            INSERT INTO flag (
                post_id, trigger_source, flagged_by_id,
                trigger_hits, trigger_word, status, notes
            ) VALUES (
                :pid, 'manual', :uid,
                1, NULL, 'flagged', :notes
            )
        ")->execute([
            ':pid'   => $postId,
            ':uid'   => $userId,
            ':notes' => $note,
        ]);

        redirect("post.php?id=$postId");
    }

    if ($action === 'flag_post' && ($isMod || $isAdmin)) {
        $reason = trim($_POST['reason'] ?? '');
        $conn->prepare("
            UPDATE post 
            SET content_status = 'flagged' 
            WHERE post_id = :pid
        ")->execute([':pid' => $postId]);

        $conn->prepare("
            INSERT INTO flag (
                post_id, trigger_source, flagged_by_id,
                trigger_hits, trigger_word, status, notes
            ) VALUES (
                :pid, 'manual', :uid,
                1, NULL, 'flagged', :notes
            )
        ")->execute([
            ':pid'   => $postId,
            ':uid'   => $userId ?: null,
            ':notes' => $reason !== '' ? $reason : null,
        ]);

        redirect("post.php?id=$postId");
    }
}

$scq = $conn->prepare("
    SELECT s.subcategory_id, s.name, s.slug
    FROM post_subcategory ps
    JOIN subcategory s ON s.subcategory_id = ps.subcategory_id
    WHERE ps.post_id = :pid
    ORDER BY s.name
");
$scq->execute([':pid' => $postId]);
$subcats = $scq->fetchAll(PDO::FETCH_ASSOC) ?: [];

$mediaSql = "
    SELECT media_id, filename, type, moderation_status 
    FROM media 
    WHERE post_id = :pid
";
$params   = [':pid' => $postId];
if (!($isMod || $isAdmin)) {
    $mediaSql .= " AND moderation_status = 'approved'";
}
$mediaSql .= " ORDER BY media_id ASC";

$ms = $conn->prepare($mediaSql);
$ms->execute($params);
$mediaItems = $ms->fetchAll(PDO::FETCH_ASSOC) ?: [];

$cmt = $conn->prepare("
    SELECT c.*, u.username, u.display_name, u.avatar_id
    FROM comment c
    JOIN users u ON u.user_id = c.user_id
    WHERE c.post_id = :pid
      AND c.content_status = 'live'
    ORDER BY c.created_at ASC
");
$cmt->execute([':pid' => $postId]);
$comments = $cmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= e($post['title']) ?> — Cat Corner</title>
  <link rel="stylesheet" href="css/style.css">
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
        <a class="pill" href="profile.php?id=<?= (int)$user['user_id'] ?>">
          <?= e($user['display_name'] ?? $user['username']) ?> (<?= e($userRole) ?>)
        </a>
        <a class="btn-outline" href="logout.php">Log out</a>
      <?php else: ?>
        <a class="btn-outline" href="login.php">Sign in</a>
      <?php endif; ?>
      <a href="about_us.php" class="nav-link">About Us</a>
    </div>
  </nav>

  <main class="container narrow post-page">
    <article class="card full-post">
      <header class="post-header">
        <div class="post-title-wrap">
          <div class="post-titleline">
            <h1 style="margin:0"><?= e($post['title']) ?></h1>
            <?php if ($subcats): ?>
              <div class="title-chips">
                <?php foreach ($subcats as $sc): ?>
                  <span class="title-chip <?= strcasecmp($sc['name'], 'Trigger warning') === 0 ? 'title-chip-trigger' : '' ?>">
                    <?= e($sc['name']) ?>
                  </span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <?php
            $authorName      = $post['display_name'] ?? $post['username'] ?? 'anonymous';
            $authorInitial   = strtoupper(mb_substr($authorName, 0, 1, 'UTF-8'));
            $authorAvatarUrl = avatar_url($post['avatar_id'] ?? null);
          ?>
          <div class="meta muted">
            <span class="author-meta-row">
              <?php if ($authorAvatarUrl): ?>
                <img
                  class="post-author-avatar"
                  src="<?= e($authorAvatarUrl) ?>"
                  alt="Profile picture of <?= e($authorName) ?>"
                >
              <?php else: ?>
                <span class="avatar-fallback"><?= e($authorInitial) ?></span>
              <?php endif; ?>

              <span>
                by
                <a href="profile.php?id=<?= (int)$post['user_id'] ?>">
                  <?= e($authorName) ?>
                </a>
                · <?= e(date('M j, Y g:i a', strtotime($post['created_at']))) ?>
                <?php if (!empty($post['main_slug'])): ?>
                  · in <a href="index.php?main=<?= e($post['main_slug']) ?>"><?= e($post['main_name'] ?? 'Category') ?></a>
                <?php endif; ?>
              </span>
            </span>
          </div>
        </div>

        <div class="post-actions-top">
          <?php if ($isPostOwner || $isAdmin): ?>
            <form method="post" class="inline" onsubmit="return confirm('Delete this post?');">
              <input type="hidden" name="action" value="delete_post">
              <button class="btn-quiet">Delete</button>
            </form>
          <?php endif; ?>

          <?php if ($loggedIn && !$isPostOwner): ?>
            <form method="post" class="inline" onsubmit="return reportPostPrompt(this);">
              <input type="hidden" name="action" value="report_post">
              <input type="hidden" name="reason" value="">
              <button class="btn-quiet">Report</button>
            </form>
          <?php endif; ?>

          <?php if ($isMod || $isAdmin): ?>
            <form method="post" class="inline" onsubmit="return flagPostPrompt(this);">
              <input type="hidden" name="action" value="flag_post">
              <input type="hidden" name="reason" value="">
              <button class="btn-quiet">Flag &amp; queue</button>
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
            <?php
              $canDelete   = ($loggedIn && (int)$c['user_id'] === $userId) || $isAdmin;
              $canFlag     = $isMod || $isAdmin;
              $cName       = $c['display_name'] ?? $c['username'] ?? 'user';
              $cInitial    = strtoupper(mb_substr($cName, 0, 1, 'UTF-8'));
              $cAvatarUrl  = avatar_url($c['avatar_id'] ?? null);
            ?>
            <li class="card comment-item" id="comment-<?= (int)$c['comment_id'] ?>">
              <div class="meta author-meta-row">
                <?php if ($cAvatarUrl): ?>
                  <img
                    class="comment-avatar"
                    src="<?= e($cAvatarUrl) ?>"
                    alt="Profile picture of <?= e($cName) ?>"
                  >
                <?php else: ?>
                  <span class="avatar-fallback"><?= e($cInitial) ?></span>
                <?php endif; ?>

                <span>
                  <strong>
                    <a href="profile.php?id=<?= (int)$c['user_id'] ?>">
                      <?= e($cName) ?>
                    </a>
                  </strong>
                  · <?= e(date('M j, Y g:i a', strtotime($c['created_at']))) ?>
                </span>
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
                <?php elseif ($loggedIn && (int)$c['user_id'] !== $userId): ?>
                  <form method="post" class="inline" onsubmit="return reportCommentPrompt(this);">
                    <input type="hidden" name="action" value="report_comment">
                    <input type="hidden" name="comment_id" value="<?= (int)$c['comment_id'] ?>">
                    <input type="hidden" name="reason" value="">
                    <button class="btn-quiet">Report</button>
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
    function flagCommentPrompt(form) {
      var r = prompt('Reason for flag (optional):');
      if (r === null) return false;
      form.querySelector('input[name="reason"]').value = (r || '').trim();
      return true;
    }

    function flagPostPrompt(form) {
      var r = prompt('Reason for flag (optional):');
      if (r === null) return false;
      form.querySelector('input[name="reason"]').value = (r || '').trim();
      return true;
    }

    function reportPostPrompt(form) {
      var r = prompt('Tell us briefly why you are reporting this post (optional):');
      if (r === null) return false;
      form.querySelector('input[name="reason"]').value = (r || '').trim();
      return true;
    }

    function reportCommentPrompt(form) {
      var r = prompt('Tell us briefly why you are reporting this comment (optional):');
      if (r === null) return false;
      form.querySelector('input[name="reason"]').value = (r || '').trim();
      return true;
    }
  </script>
</body>
</html>
