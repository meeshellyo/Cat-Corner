<?php
// index.php — public feed with left sidebar + simple auth-gated actions
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

// heading + mainId
$heading = 'Home';
$mainId  = 0;

if ($selectedMain !== 'home') {
  $sth = $conn->prepare("SELECT main_category_id, name FROM main_category WHERE slug = :slug LIMIT 1");
  $sth->execute([':slug' => $selectedMain]);
  if ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
    $mainId  = (int)$row['main_category_id'];
    $heading = $row['name'];
  } else {
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
      COALESCE(sc.subcats_csv, '') AS subcats
    FROM post p
    JOIN users u
      ON u.user_id = p.user_id
     AND u.status = 'active'
    LEFT JOIN (
      SELECT
        ps.post_id,
        GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ',') AS subcats_csv
      FROM post_subcategory ps
      JOIN subcategory s ON s.subcategory_id = ps.subcategory_id
      GROUP BY ps.post_id
    ) sc ON sc.post_id = p.post_id
    WHERE p.content_status = 'live'
      AND (
        p.main_category_id = :main_id
        OR EXISTS (
          SELECT 1
          FROM post_subcategory ps2
          JOIN subcategory s2 ON s2.subcategory_id = ps2.subcategory_id
          WHERE ps2.post_id = p.post_id
            AND s2.main_category_id = :main_id
        )
      )
    ORDER BY p.created_at DESC
    LIMIT 50
  ";
} else {
  // home: all live posts
  $sql = "
    SELECT
      p.post_id, p.title, p.body, p.created_at,
      u.display_name, u.username,
      COALESCE(sc.subcats_csv, '') AS subcats
    FROM post p
    JOIN users u
      ON u.user_id = p.user_id
     AND u.status = 'active'
    LEFT JOIN (
      SELECT
        ps.post_id,
        GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ',') AS subcats_csv
      FROM post_subcategory ps
      JOIN subcategory s ON s.subcategory_id = ps.subcategory_id
      GROUP BY ps.post_id
    ) sc ON sc.post_id = p.post_id
    WHERE p.content_status = 'live'
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

  <!-- layout: sidebar + feed -->
  <div class="layout">
    <!-- left: main categories -->
    <aside class="sidebar" aria-label="Categories">
      <h2 class="sidebar-title">Categories</h2>
      <ul class="cat-list">
        <li>
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

      <?php
        // builds href dynamically
        $createHref = 'create_post.php';
        if ($selectedMain !== 'home' && $mainId > 0) {
          $createHref .= '?main_id=' . urlencode((string)$mainId);
        }
      ?>
      <a class="btn center-cta" href="<?= e($createHref) ?>" data-requires-auth="true">Create a Post</a>

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
</body>
</html>
