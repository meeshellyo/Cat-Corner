<?php
// profile.php — user profile with tabs for posts and comments + editable bio
declare(strict_types=1);
session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once "database.php";

function e(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// logged-in viewer
$user   = $_SESSION['user'] ?? null;
$role   = $user['role'] ?? 'guest';

$info = [];
$errors = [];

// which profile are we viewing?
$profileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($profileId <= 0) {
  http_response_code(404);
  exit('Invalid user profile.');
}

// connect
$conn = Database::dbConnect();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// helper: load a user by id
function loadProfileUser(PDO $conn, int $uid): ?array {
  $stmt = $conn->prepare("
    SELECT user_id, username, display_name, bio, created_at, status, role
    FROM users
    WHERE user_id = :uid
    LIMIT 1
  ");
  $stmt->execute([':uid' => $uid]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

$profileUser = loadProfileUser($conn, $profileId);
if (!$profileUser || $profileUser['status'] !== 'active') {
  http_response_code(404);
  exit('User not found.');
}

$isOwner = $user && ((int)$user['user_id'] === $profileId);

// handle bio update (only owner)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isOwner) {
  $bio = trim($_POST['bio'] ?? '');

  // optional: limit bio length
  if (mb_strlen($bio) > 1000) {
    $bio = mb_substr($bio, 0, 1000);
    $errors[] = 'Bio was too long and has been truncated to 1000 characters.';
  }

  try {
    $stmt = $conn->prepare("UPDATE users SET bio = :bio WHERE user_id = :uid");
    $stmt->execute([
      ':bio' => $bio,
      ':uid' => $profileId
    ]);
    $info[] = 'Your bio has been updated.';
    // refresh the profile data
    $profileUser = loadProfileUser($conn, $profileId);
  } catch (PDOException $e) {
    $errors[] = 'Failed to update bio. Please try again.';
  }
}

// whether the edit form should be visible on load (e.g., after submit)
$showEdit = $isOwner && ($_SERVER['REQUEST_METHOD'] === 'POST' || !empty($info) || !empty($errors));

// load this user's posts (live only)
$posts = [];
try {
  $ps = $conn->prepare("
    SELECT post_id, title, body, created_at, content_status
    FROM post
    WHERE user_id = :uid
      AND content_status = 'live'
    ORDER BY created_at DESC
  ");
  $ps->execute([':uid' => $profileId]);
  $posts = $ps->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  $posts = [];
}

// load this user's comments (live only), with post info
$comments = [];
try {
  $cs = $conn->prepare("
    SELECT
      c.comment_id,
      c.body,
      c.created_at,
      p.post_id,
      p.title AS post_title
    FROM comment c
    JOIN post p ON p.post_id = c.post_id
    WHERE c.user_id = :uid
      AND c.content_status = 'live'
      AND p.content_status = 'live'
    ORDER BY c.created_at DESC
  ");
  $cs->execute([':uid' => $profileId]);
  $comments = $cs->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  $comments = [];
}

// display name for profile
$profileDisplay = $profileUser['display_name'] ?: $profileUser['username'];
$joined = $profileUser['created_at'] ? date('M j, Y', strtotime($profileUser['created_at'])) : null;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= e($profileDisplay) ?> — Cat Corner Profile</title>
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

      <?php if ($user && in_array($role, ['registered', 'moderator', 'admin'], true)): ?>
        <a href="my_reviews.php" class="nav-link">My Reviews</a>
      <?php endif; ?>

      <?php if ($user && in_array($role, ['moderator','admin'], true)): ?>
        <a href="mod_flags.php" class="nav-link">Moderation Queue</a>
      <?php endif; ?>

      <?php if ($user && $role === 'admin'): ?>
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

  <main>
    <!-- Profile header + edit button -->
    <section class="profile-header">
      <div class="avatar-circle">
        <?= e(strtoupper(mb_substr($profileDisplay, 0, 1, 'UTF-8'))) ?>
      </div>
      <div class="profile-main">
        <h1><?= e($profileDisplay) ?></h1>
        <div class="profile-meta">
          @<?= e($profileUser['username']) ?>
          <?php if ($joined): ?> · member since <?= e($joined) ?><?php endif; ?>
          <?php if (!empty($profileUser['role'])): ?> · role: <?= e($profileUser['role']) ?><?php endif; ?>
        </div>
        <?php if (!empty($profileUser['bio'])): ?>
          <p class="profile-bio" id="bio-text"><?= nl2br(e($profileUser['bio'])) ?></p>
        <?php else: ?>
          <p class="profile-bio" id="bio-text"><em>This user hasn’t written a bio yet.</em></p>
        <?php endif; ?>

        <?php if ($isOwner): ?>
          <button id="editBioBtn" class="btn" style="<?= $showEdit ? 'display:none;' : 'margin-top:.75rem;' ?>">
            Click here to edit your bio
          </button>
        <?php endif; ?>
      </div>
    </section>

    <!-- Hidden/visible edit form (owner only) -->
    <?php if ($isOwner): ?>
      <section id="editBioForm"
               class="edit-bio-card card"
               style="<?= $showEdit ? '' : 'display:none;' ?>">
        <h2 style="margin-top:0;">Edit Your Bio</h2>

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
          <div class="card" style="background:#fef2f2;border:1px solid #fecaca;margin-bottom:.75rem;">
            <ul style="margin:.5rem 1rem;">
              <?php foreach ($errors as $er): ?>
                <li><?= e($er) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form method="post" class="form">
          <div class="form-group">
            <label for="bio">Your bio</label>
            <textarea id="bio" name="bio" rows="4" maxlength="1000"
                      placeholder="Write a short bio about you and your cats..."><?= e($profileUser['bio'] ?? '') ?></textarea>
          </div>
          <button class="btn" type="submit">Save Changes</button>
          <button type="button" id="cancelEditBtn" class="btn-outline">Cancel</button>
        </form>
      </section>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tabs">
      <button class="tab-link active" data-tab="posts">Posts (<?= count($posts) ?>)</button>
      <button class="tab-link" data-tab="comments">Comments (<?= count($comments) ?>)</button>
    </div>

    <!-- Posts tab -->
    <section id="tab-posts" class="tab-panel active">
      <?php if (!$posts): ?>
        <div class="card empty">
          <strong>No posts yet.</strong>
          <p class="muted">This user hasn’t posted anything… yet.</p>
        </div>
      <?php else: ?>
        <div class="post-list">
          <?php foreach ($posts as $p): ?>
            <article class="card">
              <h3 style="margin-top:0;">
                <a href="post.php?id=<?= (int)$p['post_id'] ?>">
                  <?= e($p['title'] ?: '[no title]') ?>
                </a>
              </h3>
              <div class="item-meta">
                <?= e(date('M j, Y g:i a', strtotime($p['created_at']))) ?>
              </div>
              <p><?= e(mb_strimwidth($p['body'] ?? '', 0, 260, '…')) ?></p>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <!-- Comments tab -->
    <section id="tab-comments" class="tab-panel">
      <?php if (!$comments): ?>
        <div class="card empty">
          <strong>No comments yet.</strong>
          <p class="muted">This user hasn’t commented on any posts.</p>
        </div>
      <?php else: ?>
        <div class="post-list">
          <?php foreach ($comments as $c): ?>
            <article class="card">
              <div class="item-meta">
                Commented on
                <a href="post.php?id=<?= (int)$c['post_id'] ?>">
                  <?= e($c['post_title'] ?: '[post]') ?>
                </a>
                · <?= e(date('M j, Y g:i a', strtotime($c['created_at']))) ?>
              </div>
              <p class="comment-body"><?= nl2br(e(mb_strimwidth($c['body'] ?? '', 0, 260, '…'))) ?></p>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </main>

  <script>
    // tab switching
    document.querySelectorAll('.tab-link').forEach(function(btn){
      btn.addEventListener('click', function(){
        var target = this.getAttribute('data-tab');
        // update buttons
        document.querySelectorAll('.tab-link').forEach(function(b){
          b.classList.toggle('active', b === btn);
        });
        // update panels
        document.querySelectorAll('.tab-panel').forEach(function(panel){
          panel.classList.toggle('active', panel.id === 'tab-' + target);
        });
      });
    });

    // Show/hide the bio edit form
    const editBtn = document.getElementById('editBioBtn');
    const editForm = document.getElementById('editBioForm');
    const cancelBtn = document.getElementById('cancelEditBtn');

    if (editBtn && editForm) {
      editBtn.addEventListener('click', () => {
        editForm.style.display = 'block';
        editBtn.style.display = 'none';
        const bioField = document.getElementById('bio');
        if (bioField) bioField.focus();
      });
    }

    if (cancelBtn && editForm && editBtn) {
      cancelBtn.addEventListener('click', () => {
        editForm.style.display = 'none';
        editBtn.style.display = 'inline-block';
      });
    }
  </script>
</body>
</html>

