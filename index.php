<?php
// index.php — public feed with left sidebar + simple auth-gated actions
// anyone can read posts; creating/commenting prompts login via small JS modal.

session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once "database.php";

// connect
$conn = Database::dbConnect();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// small escaper for html output
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// load main categories for sidebar
$mainCategories = [];
try {
  $q = $conn->query("SELECT main_category_id, name, slug FROM main_category ORDER BY name");
  $mainCategories = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  $mainCategories = [];
}

// who is logged in (if anyone)
$user = $_SESSION['user'] ?? null;
$displayName = $user['display_name'] ?? ($user['username'] ?? null);

// which view? home = all posts
$selectedMain = $_GET['main'] ?? 'home';

// resolve heading and main_id from db so slugs never mismatch
$heading = 'Home';
$mainId  = 0;

if ($selectedMain !== 'home') {
  $sth = $conn->prepare("SELECT main_category_id, name FROM main_category WHERE slug = :slug LIMIT 1");
  $sth->execute([':slug' => $selectedMain]);
  if ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
    $mainId  = (int)$row['main_category_id'];
    $heading = $row['name'];
  } else {
    // bad slug → back to home
    $selectedMain = 'home';
    $heading = 'Home';
  }
}

// build feed query
$params = [];
if ($selectedMain !== 'home') {
  // show posts directly under main OR via any of its subcategories
  $params[':main_id'] = $mainId;
  $sql = "
    SELECT
      p.post_id, p.title, p.body, p.created_at,
      u.display_name, u.username,
      GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ',') AS subcats
    FROM post p
    JOIN users u ON u.user_id = p.user_id
    LEFT JOIN post_subcategory ps ON ps.post_id = p.post_id
    LEFT JOIN subcategory s ON s.subcategory_id = ps.subcategory_id
    LEFT JOIN main_category m ON m.main_category_id = s.main_category_id
    WHERE p.content_status = 'live'
      AND (p.main_category_id = :main_id OR m.main_category_id = :main_id)
    GROUP BY p.post_id
    ORDER BY p.created_at DESC
    LIMIT 50
  ";
} else {
  // home: all live posts
  $sql = "
    SELECT
      p.post_id, p.title, p.body, p.created_at,
      u.display_name, u.username,
      GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ',') AS subcats
    FROM post p
    JOIN users u ON u.user_id = p.user_id
    LEFT JOIN post_subcategory ps ON ps.post_id = p.post_id
    LEFT JOIN subcategory s ON s.subcategory_id = ps.subcategory_id
    WHERE p.content_status = 'live'
    GROUP BY p.post_id
    ORDER BY p.created_at DESC
    LIMIT 50
  ";
}

// run
$posts = [];
try {
  $stmt = $conn->prepare($sql);
  $stmt->execute($params);
  $posts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  $posts = [];
}

// login state for JS gating
$isLoggedIn = $user ? 'true' : 'false';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Cat Corner</title>
  <link href="css/style.css" rel="stylesheet" type="text/css">
</head>
<body>
  <!-- top nav -->
  <nav class="nav" role="navigation" aria-label="Main">
    <div class="nav-left">
      <!-- brand goes home; bigger logo per request -->
      <a class="brand" href="index.php">
        <img src="doodles/cat_corner_logo.jpg" alt="Cat Corner logo">
        <span>Cat Corner</span>
      </a>
    </div>
    <div class="nav-right">
      <?php if ($user): ?>
        <span class="pill">Signed in as <?= e($displayName ?? 'user') ?></span>
        <a class="btn-outline" href="logout.php">Log out</a>
      <?php else: ?>
        <a class="btn-outline" href="login.php">Log in</a>
        <a class="btn" href="create_user.php">Create account</a>
      <?php endif; ?>
    </div>
  </nav>

  <!-- layout: sidebar + feed -->
  <div class="layout">
    <!-- left: main categories -->
    <aside class="sidebar" aria-label="Categories">
      <h2 class="sidebar-title">Categories</h2>
      <ul class="cat-list">
        <li>
          <!-- CHANGED: 'Home' → 'All Posts' -->
          <a class="cat-link <?= $selectedMain==='home' ? 'active' : '' ?>" href="index.php">All Posts</a>
        </li>
        <?php foreach ($mainCategories as $mc): ?>
          <li>
            <a class="cat-link <?= $selectedMain===$mc['slug'] ? 'active' : '' ?>"
               href="index.php?main=<?= e($mc['slug']) ?>">
              <?= e($mc['name']) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </aside>

    <!-- right: feed -->
    <main class="feed container home">
      <h1><?= e($heading) ?></h1>
      <p class="sub">Browse recent posts. Sign in to create posts or comment.</p>

      <!-- NEW: Mid-screen skinny/long Create Post CTA -->
      <a class="btn center-cta" href="create_post.php" data-requires-auth="true">Create a Post</a>

      <?php if (!$posts): ?>
        <div class="card empty">
          <strong>No posts yet.</strong>
          <p class="muted">under cat construction!</p>
        </div>
      <?php else: ?>
        <div class="post-list">
          <?php foreach ($posts as $p):
            $author  = $p['display_name'] ?: $p['username'] ?: 'anonymous';
            $created = date('M j, Y g:i a', strtotime($p['created_at']));
            $chips = [];
            if (!empty($p['subcats'])) {
              $chips = array_filter(array_map('trim', explode(',', $p['subcats'])));
            }
          ?>
          <article class="post-card card">
            <header class="post-head">
              <h3 class="post-title">
                <a class="link" href="post.php?id=<?= (int)$p['post_id'] ?>">
                  <?= e($p['title']) ?>
                </a>
              </h3>
              <div class="post-meta muted">by <?= e($author) ?> · <?= e($created) ?></div>
            </header>

            <div class="post-body">
              <p><?= e(mb_strimwidth($p['body'] ?? '', 0, 260, '…')) ?></p>
            </div>

            <?php if ($chips): ?>
              <ul class="subcat-chips">
                <?php foreach ($chips as $chip): ?>
                  <li class="chip"><?= e($chip) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </main>
  </div>
  
  <!-- login modal omitted for brevity -->
</body>
</html>
