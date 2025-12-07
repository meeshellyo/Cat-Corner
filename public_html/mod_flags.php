<?php
// mod_flags.php
declare(strict_types=1);
session_start();
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once "database.php";

function e(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$user = $_SESSION['user'] ?? null;
if (!$user) {
  header('Location: ./login.php');
  exit;
}

$userId = (int)$user['user_id'];
$role   = $user['role'] ?? 'registered';

$isMod   = in_array($role, ['moderator', 'admin'], true);
$isAdmin = ($role === 'admin');

if (!$isMod && !$isAdmin) {
  http_response_code(403);
  echo "<h2>Not allowed</h2><p>You do not have permission to view the moderation queue.</p>";
  exit;
}

// decide whether posts or comments are being viewed on the queue
$kind = ($_GET['kind'] ?? 'posts') === 'comments' ? 'comments' : 'posts';

$allowedFilters = ['all','lexicon','media','user']; // for posts
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, $allowedFilters, true)) {
  $filter = 'all';
}

$allowedCommentFilters = ['all', 'lexicon', 'user']; // for comments
$commentFilter = $_GET['cfilter'] ?? 'all';
if (!in_array($commentFilter, $allowedCommentFilters, true)) {
  $commentFilter = 'all';
}

$conn = Database::dbConnect();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$errors = [];

// handles mod actions on posts
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $reason = trim((string)($_POST['reason'] ?? ''));

  if ($action === 'approve_post' && ($isMod || $isAdmin)) {
    $pid = (int)($_POST['post_id'] ?? 0);

    if ($pid > 0) {
      // prevent self-approval as post owner
      $ownStmt = $conn->prepare("SELECT user_id FROM post WHERE post_id = :pid");
      $ownStmt->execute([':pid' => $pid]);
      $ownerId = (int)($ownStmt->fetchColumn() ?: 0);

      if ($ownerId === $userId) {
        $errors[] = "You can't approve your own post. Another moderator must review it.";
      } else {
        // prevent approving a post you flagged yourself
        $selfFlagStmt = $conn->prepare("
          SELECT COUNT(*) 
          FROM flag
          WHERE post_id = :pid
            AND status = 'flagged'
            AND flagged_by_id = :mid
            AND (notes NOT LIKE '%comment#%' OR notes IS NULL)  -- only post-level flags
        ");
        $selfFlagStmt->execute([
          ':pid' => $pid,
          ':mid' => $userId,
        ]);
        $selfFlagCount = (int)$selfFlagStmt->fetchColumn();

        if ($selfFlagCount > 0) {
          $errors[] = "You can't approve a post you flagged. Another moderator must review it.";
        } else {
          try {
            $conn->beginTransaction();

            // make post live
            $stmt = $conn->prepare("UPDATE post SET content_status = 'live' WHERE post_id = :pid");
            $stmt->execute([':pid' => $pid]);

            // approve media
            $ms = $conn->prepare("
              UPDATE media
              SET moderation_status = 'approved', moderated_by = :mid, moderated_at = NOW()
              WHERE post_id = :pid AND moderation_status IN ('pending','rejected')
            ");
            $ms->execute([':pid' => $pid, ':mid' => $userId]);

            // mark active *post-level* flags as approved (do NOT touch comment flags)
            $fs = $conn->prepare("
              UPDATE flag
              SET status = 'approved', moderator_id = :mid, decided_at = NOW()
              WHERE post_id = :pid
                AND status = 'flagged'
                AND (notes NOT LIKE '%comment#%' OR notes IS NULL)
            ");
            $fs->execute([':pid' => $pid, ':mid' => $userId]);

            // log
            $log = $conn->prepare("
              INSERT INTO moderation_log (moderator_id, post_id, action, reason)
              VALUES (:mid, :pid, 'approved', :reason)
            ");
            $log->execute([
              ':mid'    => $userId,
              ':pid'    => $pid,
              ':reason' => $reason !== '' ? $reason : null
            ]);

            $conn->commit();
            header('Location: mod_flags.php?kind=posts&filter=' . urlencode($filter));
            exit;

          } catch (Throwable $t) {
            if ($conn->inTransaction()) $conn->rollBack();
            $errors[] = "Failed to approve post.";
          }
        }
      }
    }
  }

  if ($action === 'reject_post' && ($isMod || $isAdmin)) {
    $pid = (int)($_POST['post_id'] ?? 0);

    if ($pid > 0) {
      // prevent rejecting a post you flagged yourself
      $selfFlagStmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM flag
        WHERE post_id = :pid
          AND status = 'flagged'
          AND flagged_by_id = :mid
          AND (notes NOT LIKE '%comment#%' OR notes IS NULL)  -- only post-level flags
      ");
      $selfFlagStmt->execute([
        ':pid' => $pid,
        ':mid' => $userId,
      ]);
      $selfFlagCount = (int)$selfFlagStmt->fetchColumn();

      if ($selfFlagCount > 0) {
        $errors[] = "You can't reject a post you flagged. Another moderator must review it.";
      } else {
        try {
          $conn->beginTransaction();

          // mark post as rejected (effectively removed)
          $stmt = $conn->prepare("UPDATE post SET content_status = 'rejected' WHERE post_id = :pid");
          $stmt->execute([':pid' => $pid]);

          // reject media
          $ms = $conn->prepare("
            UPDATE media
            SET moderation_status = 'rejected', moderated_by = :mid, moderated_at = NOW()
            WHERE post_id = :pid AND moderation_status IN ('pending','approved')
          ");
          $ms->execute([':pid' => $pid, ':mid' => $userId]);

          // mark active *post-level* flags as rejected (do NOT touch comment flags)
          $fs = $conn->prepare("
            UPDATE flag
            SET status = 'rejected', moderator_id = :mid, decided_at = NOW()
            WHERE post_id = :pid
              AND status = 'flagged'
              AND (notes NOT LIKE '%comment#%' OR notes IS NULL)
          ");
          $fs->execute([':pid' => $pid, ':mid' => $userId]);

          // log
          $log = $conn->prepare("
            INSERT INTO moderation_log (moderator_id, post_id, action, reason)
            VALUES (:mid, :pid, 'rejected', :reason)
          ");
          $log->execute([
            ':mid'    => $userId,
            ':pid'    => $pid,
            ':reason' => $reason !== '' ? $reason : null
          ]);

          $conn->commit();
          header('Location: mod_flags.php?kind=posts&filter=' . urlencode($filter));
          exit;

        } catch (Throwable $t) {
          if ($conn->inTransaction()) $conn->rollBack();
          $errors[] = "Failed to reject post.";
        }
      }
    }
  }


//  ignore comment (makes it live)
  if ($action === 'ignore_comment' && ($isMod || $isAdmin)) {
    $cid = (int)($_POST['comment_id'] ?? 0);
    $pid = (int)($_POST['post_id'] ?? 0);

    if ($cid > 0 && $pid > 0) {
      // prevent self-ignore (comment owner)
      $ownStmt = $conn->prepare("SELECT user_id FROM comment WHERE comment_id = :cid");
      $ownStmt->execute([':cid' => $cid]);
      $ownerId = (int)($ownStmt->fetchColumn() ?: 0);

      if ($ownerId === $userId) {
        $errors[] = "You can't ignore your own comment. Another moderator must review it.";
      } else {
        // prevent resolving a comment you flagged yourself
        $selfFlagStmt = $conn->prepare("
          SELECT COUNT(*)
          FROM flag
          WHERE post_id = :pid
            AND status = 'flagged'
            AND flagged_by_id = :mid
            AND notes LIKE :pattern
        ");
        $selfFlagStmt->execute([
          ':pid'     => $pid,
          ':mid'     => $userId,
          ':pattern' => "%comment#{$cid}%",
        ]);
        $selfFlagCount = (int)$selfFlagStmt->fetchColumn();

        if ($selfFlagCount > 0) {
          $errors[] = "You can't resolve a comment you flagged. Another moderator must review it.";
        } else {
          try {
            $conn->beginTransaction();

            // comment goes live
            $stmt = $conn->prepare("UPDATE comment SET content_status = 'live' WHERE comment_id = :cid");
            $stmt->execute([':cid' => $cid]);

            // mark flags for this comment as approved
            $fs = $conn->prepare("
              UPDATE flag
              SET status = 'approved', moderator_id = :mid, decided_at = NOW()
              WHERE post_id = :pid
                AND status = 'flagged'
                AND notes LIKE :pattern
            ");
            $fs->execute([
              ':pid'     => $pid,
              ':mid'     => $userId,
              ':pattern' => "%comment#{$cid}%"
            ]);

            $conn->commit();
          } catch (Throwable $t) {
            if ($conn->inTransaction()) $conn->rollBack();
            $errors[] = "Failed to ignore comment.";
          }
        }
      }
    }

    header('Location: mod_flags.php?kind=comments&cfilter=' . urlencode($commentFilter));
    exit;
  }

  if ($action === 'delete_comment' && ($isMod || $isAdmin)) {
    $cid = (int)($_POST['comment_id'] ?? 0);
    $pid = (int)($_POST['post_id'] ?? 0);

    if ($cid > 0 && $pid > 0) {
      // prevent resolving a comment you flagged yourself
      $selfFlagStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM flag
        WHERE post_id = :pid
          AND status = 'flagged'
          AND flagged_by_id = :mid
          AND notes LIKE :pattern
      ");
      $selfFlagStmt->execute([
        ':pid'     => $pid,
        ':mid'     => $userId,
        ':pattern' => "%comment#{$cid}%",
      ]);
      $selfFlagCount = (int)$selfFlagStmt->fetchColumn();

      if ($selfFlagCount > 0) {
        $errors[] = "You can't resolve a comment you flagged. Another moderator must review it.";
      } else {
        try {
          $conn->beginTransaction();

          // mark comment as deleted
          $stmt = $conn->prepare("UPDATE comment SET content_status = 'deleted' WHERE comment_id = :cid");
          $stmt->execute([':cid' => $cid]);

          // mark flags for this comment as rejected
          $fs = $conn->prepare("
            UPDATE flag
            SET status = 'rejected', moderator_id = :mid, decided_at = NOW()
            WHERE post_id = :pid
              AND status = 'flagged'
              AND notes LIKE :pattern
          ");
          $fs->execute([
            ':pid'     => $pid,
            ':mid'     => $userId,
            ':pattern' => "%comment#{$cid}%"
          ]);

          $conn->commit();
        } catch (Throwable $t) {
          if ($conn->inTransaction()) $conn->rollBack();
          $errors[] = "Failed to delete comment.";
        }
      }
    }

    header('Location: mod_flags.php?kind=comments&cfilter=' . urlencode($commentFilter));
    exit;
  }
}

$posts = [];
if ($kind === 'posts') {
  try {
    $extraFilter = "";
    if ($filter === 'lexicon') {
      $extraFilter = " AND f.lexicon_count > 0 ";
    } elseif ($filter === 'user') {
      $extraFilter = " AND f.user_report_count > 0 ";
    } elseif ($filter === 'media') {
      $extraFilter = " AND m.has_pending_media IS NOT NULL ";
    }

 // main query that get posts that are pending/flagged or have flags or pending media
    $sql = "
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

        f.total_flags,
        f.lexicon_count,
        f.user_report_count,
        f.mod_flag_count,
        f.lexicon_words,
        f.last_flagged_at,
        f.flagged_by_self,
        m.has_pending_media

      FROM post p
      JOIN users u ON u.user_id = p.user_id
      LEFT JOIN main_category mc ON mc.main_category_id = p.main_category_id

      -- aggregate *post-level* flags (ignore comment flags)
      LEFT JOIN (
        SELECT
          post_id,
          COUNT(*) AS total_flags,
          SUM(CASE WHEN trigger_source = 'lexicon' THEN 1 ELSE 0 END) AS lexicon_count,
          SUM(
            CASE 
              WHEN trigger_source = 'manual' AND notes LIKE 'user report%' THEN 1 
              ELSE 0 
            END
          ) AS user_report_count,
          SUM(
            CASE 
              WHEN trigger_source = 'manual'
                   AND (notes NOT LIKE 'user report%' OR notes IS NULL)
              THEN 1 
              ELSE 0 
            END
          ) AS mod_flag_count,
          GROUP_CONCAT(
            DISTINCT CASE 
              WHEN trigger_source = 'lexicon' THEN trigger_word 
              ELSE NULL 
            END
          ) AS lexicon_words,
          MAX(created_at) AS last_flagged_at,
          MAX(
            CASE 
              WHEN flagged_by_id = {$userId} THEN 1 
              ELSE 0 
            END
          ) AS flagged_by_self
        FROM flag
        WHERE status = 'flagged'
          AND (notes NOT LIKE '%comment#%' OR notes IS NULL)
        GROUP BY post_id
      ) f ON f.post_id = p.post_id

      -- any media needing review
      LEFT JOIN (
        SELECT post_id, 1 AS has_pending_media
        FROM media
        WHERE moderation_status = 'pending'
        GROUP BY post_id
      ) m ON m.post_id = p.post_id

      WHERE
        (
          p.content_status IN ('pending','flagged')
          OR f.total_flags IS NOT NULL
          OR m.has_pending_media IS NOT NULL
        )
        $extraFilter
      ORDER BY p.created_at DESC
      LIMIT 200
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $t) {
    $errors[] = 'Failed to load moderation queue for posts.';
  }
}

$comments = [];
if ($kind === 'comments') {
  try {
    $commentExtraFilter = "";
    if ($commentFilter === 'lexicon') {
      $commentExtraFilter = " AND cf.lexicon_count > 0 ";
    } elseif ($commentFilter === 'user') {
      $commentExtraFilter = " AND cf.user_report_count > 0 ";
    }

    // query for comments with comment-level flags
    $cstmt = $conn->prepare("
      SELECT
        c.comment_id,
        c.post_id,
        c.user_id,
        c.body,
        c.created_at,
        c.content_status,
        u.username,
        u.display_name,
        p.title AS post_title,
        mc.name AS main_name,
        mc.slug AS main_slug,

        cf.total_flags,
        cf.lexicon_count,
        cf.user_report_count,
        cf.mod_flag_count,
        cf.last_flagged_at,
        cf.flagged_by_self

      FROM comment c
      JOIN users u ON u.user_id = c.user_id
      JOIN post p ON p.post_id = c.post_id
      LEFT JOIN main_category mc ON mc.main_category_id = p.main_category_id

      -- join comment flags derived from notes
      LEFT JOIN (
        SELECT
          f.post_id,
          CAST(
            SUBSTRING_INDEX(
              SUBSTRING_INDEX(f.notes, 'comment#', -1),
              ' ',
              1
            ) AS UNSIGNED
          ) AS comment_id,
          COUNT(*) AS total_flags,
          SUM(CASE WHEN f.trigger_source = 'lexicon' THEN 1 ELSE 0 END) AS lexicon_count,
          SUM(
            CASE 
              WHEN f.trigger_source = 'manual'
                   AND f.notes LIKE 'user report:%'
              THEN 1 ELSE 0
            END
          ) AS user_report_count,
          SUM(
            CASE 
              WHEN f.trigger_source = 'manual'
                   AND (f.notes NOT LIKE 'user report:%' OR f.notes IS NULL)
              THEN 1 ELSE 0
            END
          ) AS mod_flag_count,
          MAX(f.created_at) AS last_flagged_at,
          MAX(
            CASE 
              WHEN f.flagged_by_id = {$userId} THEN 1 
              ELSE 0 
            END
          ) AS flagged_by_self
        FROM flag f
        WHERE f.status = 'flagged'
          AND f.notes LIKE '%comment#%'
        GROUP BY f.post_id, comment_id
      ) cf ON cf.post_id = c.post_id AND cf.comment_id = c.comment_id

      WHERE
        (
          c.content_status = 'flagged'
          OR cf.total_flags IS NOT NULL
        )
        $commentExtraFilter
      ORDER BY c.created_at DESC
      LIMIT 200
    ");
    $cstmt->execute();
    $comments = $cstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $t) {
    $errors[] = 'Failed to load moderation queue for comments.';
  }
}

$totalItems = count($posts) + count($comments);

function filter_label(string $filter): string {
  return [
    'all'     => 'All',
    'lexicon' => 'Trigger words',
    'media'   => 'Media',
    'user'    => 'User reports',
  ][$filter] ?? 'All';
}

function comment_filter_label(string $filter): string {
  return [
    'all'     => 'All',
    'lexicon' => 'Trigger words',
    'user'    => 'User reports',
  ][$filter] ?? 'All';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Moderation Queue — Cat Corner</title>
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
      <span class="badge"><?= $totalItems ?> items</span>
    </div>

    <!-- big tabs for posts / comments -->
    <div class="mod-kind-tabs">
      <a
        href="mod_flags.php?kind=posts&filter=<?= e($filter) ?>"
        class="mod-kind-btn <?= $kind === 'posts' ? 'active' : '' ?>"
      >Posts</a>
      <a
        href="mod_flags.php?kind=comments&cfilter=<?= e($commentFilter) ?>"
        class="mod-kind-btn <?= $kind === 'comments' ? 'active' : '' ?>"
      >Comments</a>
    </div>

    <?php if ($kind === 'posts'): ?>
      <!-- filter dropdown (only for posts) -->
      <form method="get" class="mod-filter-form">
        <input type="hidden" name="kind" value="posts">
        <label for="filter" class="mod-filter-label">Filter</label>
        <select id="filter" name="filter" class="mod-filter-select" onchange="this.form.submit()">
          <option value="all"     <?= $filter === 'all'     ? 'selected' : '' ?>>All</option>
          <option value="lexicon" <?= $filter === 'lexicon' ? 'selected' : '' ?>>Trigger words</option>
          <option value="media"   <?= $filter === 'media'   ? 'selected' : '' ?>>Media</option>
          <option value="user"    <?= $filter === 'user'    ? 'selected' : '' ?>>User reports</option>
        </select>
      </form>
    <?php endif; ?>

    <?php if ($kind === 'comments'): ?>
      <!-- filter dropdown for comments -->
      <form method="get" class="mod-filter-form">
        <input type="hidden" name="kind" value="comments">
        <label for="cfilter" class="mod-filter-label">Filter</label>
        <select id="cfilter" name="cfilter" class="mod-filter-select" onchange="this.form.submit()">
          <option value="all"     <?= $commentFilter === 'all'     ? 'selected' : '' ?>>All</option>
          <option value="lexicon" <?= $commentFilter === 'lexicon' ? 'selected' : '' ?>>Trigger words</option>
          <option value="user"    <?= $commentFilter === 'user'    ? 'selected' : '' ?>>User reports</option>
        </select>
      </form>
    <?php endif; ?>

    <p class="sub">
      Viewing <strong><?= $kind === 'posts' ? 'posts' : 'comments' ?></strong>
      <?php if ($kind === 'posts'): ?>
        • Filter: <strong><?= e(filter_label($filter)) ?></strong>
      <?php else: ?>
        • Filter: <strong><?= e(comment_filter_label($commentFilter)) ?></strong>
      <?php endif; ?>
    </p>

    <?php if ($errors): ?>
      <div class="card error-card">
        <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <?php if ($kind === 'posts'): ?>

      <!-- posts section -->
      <div class="section-head">
        <h2 style="margin:0;">Posts Needing Review</h2>
        <span class="badge"><?= count($posts) ?></span>
      </div>

      <?php if (!count($posts)): ?>
        <div class="card"><strong>All clear!</strong> There are no posts waiting for review.</div>
      <?php else: ?>
        <div class="review-list">
          <?php foreach ($posts as $r): ?>
            <?php
              $created        = date('M j, Y g:i a', strtotime($r['created_at']));
              $excerpt        = mb_strimwidth((string)$r['body'], 0, 280, '…');
              $status         = $r['content_status'];
              $ownerId        = (int)$r['user_id'];
              $ownerName      = $r['display_name'] ?: $r['username'] ?: 'user';
              $flaggedBySelf  = (int)($r['flagged_by_self'] ?? 0);
              $canApprove     = ($isMod || $isAdmin)
                                && ($ownerId !== $userId)
                                && !$flaggedBySelf;

              $lexCount   = (int)($r['lexicon_count'] ?? 0);
              $userCount  = (int)($r['user_report_count'] ?? 0);
              $modCount   = (int)($r['mod_flag_count'] ?? 0);
              $hasMedia   = !empty($r['has_pending_media']);
              $hasFlags   = ((int)($r['total_flags'] ?? 0) > 0);

              // status pill
              $pillText  = '';
              $pillClass = '';
              if ($status === 'pending') {
                $pillText  = 'Pending approval';
                $pillClass = 'pill-pending';
              } elseif ($status === 'flagged') {
                $pillText  = 'Flagged (hidden)';
                $pillClass = 'pill-flagged';
              } elseif ($status === 'live' && ($hasFlags || $hasMedia)) {
                $pillText  = 'Live · under review';
                $pillClass = 'pill-underreview';
              }

              // signals line
              $signals = [];

              $lexWordsRaw  = (string)($r['lexicon_words'] ?? '');
              $lexWordsList = array_values(
                array_filter(
                  array_unique(
                    array_map('trim', explode(',', $lexWordsRaw))
                  ),
                  fn($w) => $w !== ''
                )
              );

              if ($lexCount > 0) {
                if ($lexWordsList) {
                  $firstWord = $lexWordsList[0];
                  $signals[] = 'Trigger word x1 = "' . $firstWord . '"';

                  if (count($lexWordsList) > 1) {
                    $remaining = array_slice($lexWordsList, 1);
                    $signals[] = 'Additional trigger words: ' . implode(', ', $remaining);
                  }
                } else {
                  $signals[] = "Trigger words ×{$lexCount}";
                }
              }

              if ($userCount > 0) $signals[] = "User reports ×{$userCount}";
              if ($modCount > 0)  $signals[] = "Moderator flags ×{$modCount}";
              if ($hasMedia)      $signals[] = "Media under review";
            ?>
            <article class="card">
              <h3 style="margin-top:0;">
                <a href="post.php?id=<?= (int)$r['post_id'] ?>" target="_blank">
                  <?= e($r['title'] ?: '[no title]') ?>
                </a>
                <?php if ($pillText): ?>
                  <span class="pill-status <?= e($pillClass) ?>"><?= e($pillText) ?></span>
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
              </div>

              <p><?= e($excerpt) ?></p>

              <?php if ($signals): ?>
                <div class="meta signal-line">
                  Signals: <?= e(implode(' · ', $signals)) ?>
                </div>
              <?php else: ?>
                <div class="meta signal-line">
                  Signals: none (status only)
                </div>
              <?php endif; ?>

              <?php if (!empty($r['last_flagged_at']) && $hasFlags): ?>
                <div class="meta">
                  Last flagged: <?= e(date('M j, Y g:i a', strtotime($r['last_flagged_at']))) ?>
                </div>
              <?php endif; ?>

              <div class="mod-actions">
                <?php if ($canApprove): ?>
                  <form method="post" class="inline" onsubmit="return approvePostPrompt(this);">
                    <input type="hidden" name="action" value="approve_post">
                    <input type="hidden" name="post_id" value="<?= (int)$r['post_id'] ?>">
                    <input type="hidden" name="reason" value="">
                    <button type="submit" class="btn-soft btn-approve">Approve</button>
                  </form>

                  <form method="post" class="inline" onsubmit="return rejectPostPrompt(this);">
                    <input type="hidden" name="action" value="reject_post">
                    <input type="hidden" name="post_id" value="<?= (int)$r['post_id'] ?>">
                    <input type="hidden" name="reason" value="">
                    <button type="submit" class="btn-soft btn-reject">Reject</button>
                  </form>
                <?php else: ?>
                  <div class="muted-note">
                    <?php if ($ownerId === $userId): ?>
                      You can't approve your own post. Another moderator must review it.
                    <?php elseif ($flaggedBySelf): ?>
                      You flagged this post. Another moderator must review it.
                    <?php else: ?>
                      You don't have permission to resolve this post.
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    <?php else: ?>

      <!-- comment section -->
      <div class="section-head">
        <h2 style="margin:0;">Comments Needing Review</h2>
        <span class="badge"><?= count($comments) ?></span>
      </div>

      <?php if (!count($comments)): ?>
        <div class="card"><strong>All clear!</strong> There are no comments waiting for review.</div>
      <?php else: ?>
        <div class="review-list">
          <?php foreach ($comments as $c): ?>
            <?php
              $cCreated       = date('M j, Y g:i a', strtotime($c['created_at']));
              $cExcerpt       = mb_strimwidth((string)$c['body'], 0, 280, '…');
              $cStatus        = $c['content_status'];
              $cOwnerId       = (int)$c['user_id'];
              $cOwnerName     = $c['display_name'] ?: $c['username'] ?: 'user';
              $postId         = (int)$c['post_id'];
              $cid            = (int)$c['comment_id'];
              $flaggedBySelf  = (int)($c['flagged_by_self'] ?? 0);

              $lexCount  = (int)($c['lexicon_count'] ?? 0);
              $userCount = (int)($c['user_report_count'] ?? 0);
              $modCount  = (int)($c['mod_flag_count'] ?? 0);

              $signals = [];
              if ($lexCount > 0)  $signals[] = "Trigger words ×{$lexCount}";
              if ($userCount > 0) $signals[] = "User reports ×{$userCount}";
              if ($modCount > 0)  $signals[] = "Moderator flags ×{$modCount}";

              $cCanResolve = ($isMod || $isAdmin)
                             && ($cOwnerId !== $userId)
                             && !$flaggedBySelf;
            ?>

            <article class="card">
              <h3 style="margin-top:0;">
                <a href="post.php?id=<?= $postId ?>#comment-<?= $cid ?>" target="_blank">
                  in: <?= e($c['post_title'] ?: 'post') ?>
                </a>
                <?php if ($cStatus === 'flagged'): ?>
                  <span class="pill-status pill-flagged">flagged</span>
                <?php endif; ?>
              </h3>

              <div class="meta">
                by
                <a href="profile.php?id=<?= $cOwnerId ?>">
                  <?= e($cOwnerName) ?>
                </a>
                · <?= e($cCreated) ?>
                <?php if (!empty($c['main_slug'])): ?>
                  · in <a href="index.php?main=<?= e($c['main_slug']) ?>"><?= e($c['main_name'] ?? 'Category') ?></a>
                <?php endif; ?>
              </div>
              <p><?= e($cExcerpt) ?></p>

              <?php if ($signals): ?>
                <div class="meta signal-line">
                  Signals: <?= e(implode(' · ', $signals)) ?>
                </div>
              <?php endif; ?>

              <div class="mod-actions">
                <?php if ($cCanResolve): ?>
                  <form method="post" class="inline" onsubmit="return confirm('Ignore this comment and keep it visible?');">
                    <input type="hidden" name="action" value="ignore_comment">
                    <input type="hidden" name="comment_id" value="<?= $cid ?>">
                    <input type="hidden" name="post_id" value="<?= $postId ?>">
                    <button type="submit" class="btn-soft btn-approve">Ignore</button>
                  </form>

                  <form method="post" class="inline" onsubmit="return confirm('Delete this comment and remove it?');">
                    <input type="hidden" name="action" value="delete_comment">
                    <input type="hidden" name="comment_id" value="<?= $cid ?>">
                    <input type="hidden" name="post_id" value="<?= $postId ?>">
                    <button type="submit" class="btn-soft btn-reject">Delete</button>
                  </form>
                <?php else: ?>
                  <div class="muted-note">
                    <?php if ($cOwnerId === $userId): ?>
                      You can't ignore or delete your own comment. Another moderator must review it.
                    <?php elseif ($flaggedBySelf): ?>
                      You flagged this comment. Another moderator must review it.
                    <?php else: ?>
                      You don't have permission to resolve this comment.
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>

            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    <?php endif; ?>
  </main>

  <script>
    // prompts for leaving a note when approving a post
    function approvePostPrompt(form) {
      const note = prompt('Optional note for log (Approve). Leave blank for none:');
      if (note === null) return false;
      form.querySelector('input[name="reason"]').value = (note || '').trim();
      return true;
    }
    // prompts for leaving a note when rejecting a post
    function rejectPostPrompt(form) {
      const note = prompt('Optional note for log (Reject). Leave blank for none:');
      if (note === null) return false;
      form.querySelector('input[name="reason"]').value = (note || '').trim();
      return true;
    }
  </script>
</body>
</html>
