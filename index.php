<?php
// index.php 
declare(strict_types=1);
session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once "database.php";

$conn = Database::dbConnect();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* --------- Load sidebar categories --------- */
$mainCategories = [];
try {
  $q = $conn->query("SELECT main_category_id, name, slug FROM main_category ORDER BY name");
  $mainCategories = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $t) { $mainCategories = []; }

/* --------- Auth / view params --------- */
$user         = $_SESSION['user'] ?? null;
$userId       = (int)($user['user_id'] ?? 0);
$selectedMain = $_GET['main'] ?? 'home';
$sort         = $_GET['sort'] ?? 'recent'; // recent|liked|disliked

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

/* --------- Sorting --------- */
$orderBy = "p.created_at DESC";
if ($sort === 'liked')    $orderBy = "va.likes DESC, p.created_at DESC";
if ($sort === 'disliked') $orderBy = "va.dislikes DESC, p.created_at DESC";

/* --------- Where clause --------- */
$where  = "p.content_status = 'live'";
$params = [':uid' => $userId];
if ($selectedMain !== 'home') {
  $where .= " AND (p.main_category_id = :main_id
                OR EXISTS (
                    SELECT 1
                    FROM post_subcategory ps2
                    JOIN subcategory s2 ON s2.subcategory_id = ps2.subcategory_id
                    WHERE ps2.post_id = p.post_id
                      AND s2.main_category_id = :main_id
                ))";
  $params[':main_id'] = $mainId;
}

/* --------- Query: posts + first APPROVED media only + subcats + votes + my vote --------- */
$sql = "
SELECT
  p.post_id,
  p.title,
  p.body,
  p.created_at,
  u.user_id,
  u.display_name,
  u.username,
  COALESCE(sc.subcats_csv, '') AS subcats,
  ap.filename                  AS thumb_filename,   -- approved-only thumbnail (DB value as-is)
  COALESCE(va.likes, 0)        AS likes,
  COALESCE(va.dislikes, 0)     AS dislikes,
  COALESCE(uv.value, 0)        AS my_vote
FROM post p
JOIN users u
  ON u.user_id = p.user_id
 AND u.status  = 'active'
LEFT JOIN (
  SELECT ps.post_id,
         GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ',') AS subcats_csv
  FROM post_subcategory ps
  JOIN subcategory s ON s.subcategory_id = ps.subcategory_id
  GROUP BY ps.post_id
) sc ON sc.post_id = p.post_id

/* First APPROVED media per post (only approved!) */
LEFT JOIN (
  SELECT m1.post_id, m1.filename
  FROM media m1
  JOIN (
    SELECT post_id, MIN(media_id) AS min_id
    FROM media
    WHERE moderation_status = 'approved'
    GROUP BY post_id
  ) t ON t.post_id = m1.post_id AND t.min_id = m1.media_id
) ap ON ap.post_id = p.post_id

/* Vote aggregates */
LEFT JOIN (
  SELECT post_id,
         SUM(value = 1)  AS likes,
         SUM(value = -1) AS dislikes
  FROM post_vote
  GROUP BY post_id
) va ON va.post_id = p.post_id

/* Current user's vote */
LEFT JOIN post_vote uv
  ON uv.post_id = p.post_id AND uv.user_id = :uid

WHERE $where
ORDER BY $orderBy
LIMIT 40
";

$posts = [];
try {
  $stmt = $conn->prepare($sql);
  $stmt->execute($params);
  $posts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $t) {
  $posts = [];
}

/* --------- Create link --------- */
$createHref = 'create_post.php';
if ($selectedMain !== 'home' && $mainId > 0) {
  $createHref .= '?main_id=' . urlencode((string)$mainId);
}

/* --------- Helpers for client --------- */
$isLoggedIn = $user ? 'true' : 'false';
function sort_link(string $sort, string $main): string {
  $base = 'index.php';
  $qs = [];
  if ($main !== 'home') $qs['main'] = $main;
  if ($sort !== 'recent') $qs['sort'] = $sort;
  return $base . (empty($qs) ? '' : '?'.http_build_query($qs));
}
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
      <a href="index.php" class="nav-link <?= $selectedMain==='home'?'active':'' ?>">Home</a>

      <?php if ($user && in_array($user['role'], ['registered','moderator','admin'], true)): ?>
        <a href="my_reviews.php" class="nav-link">My Reviews</a>
      <?php endif; ?>

      <?php if ($user && in_array($user['role'], ['moderator','admin'], true)): ?>
        <a href="mod_flags.php" class="nav-link">Moderation Queue</a>
      <?php endif; ?>

      <?php if ($user && $user['role'] === 'admin'): ?>
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

  <div class="page">
    <div class="layout">
      <!-- Sidebar -->
      <aside class="sidebar" aria-label="Categories">
        <h2 class="sidebar-title">Categories</h2>
        <ul class="cat-list">
          <li><a class="cat-link <?= $selectedMain==='home' ? 'active' : '' ?>" href="index.php">All Posts</a></li>
          <?php foreach ($mainCategories as $mc): ?>
            <li>
              <a class="cat-link <?= $selectedMain===$mc['slug'] ? 'active' : '' ?>"
                 href="index.php?main=<?= e($mc['slug']) ?>"><?= e($mc['name']) ?></a>
            </li>
          <?php endforeach; ?>
        </ul>
      </aside>

      <!-- Feed -->
      <main class="feed">
        <h1><?= e($heading) ?></h1>
        <p class="sub">Readable posts with small thumbnails (shown only when approved media exists). Click a title to open the post.</p>

        <div class="feed-bar">
          <a class="btn" href="<?= e($createHref) ?>" data-requires-auth="true">Create a Post</a>

          <div class="sort">
            <label for="sort">Sort:</label>
            <select id="sort" onchange="location.href=this.value;">
              <option value="<?= e(sort_link('recent', $selectedMain)) ?>"   <?= $sort==='recent'?'selected':'' ?>>Most recent</option>
              <option value="<?= e(sort_link('liked', $selectedMain)) ?>"    <?= $sort==='liked'?'selected':'' ?>>Most liked</option>
              <option value="<?= e(sort_link('disliked', $selectedMain)) ?>" <?= $sort==='disliked'?'selected':'' ?>>Most disliked</option>
            </select>
          </div>
        </div>

        <?php if (!$posts): ?>
          <div class="card empty"><strong>No posts yet.</strong></div>
        <?php else: ?>
          <div class="post-list">
            <?php foreach ($posts as $p):
              $author   = $p['display_name'] ?: $p['username'] ?: 'anonymous';
              $authorId = (int)$p['user_id'];
              $created  = date('M j, Y g:i a', strtotime($p['created_at']));
              $chips    = [];
              if (!empty($p['subcats'])) $chips = array_filter(array_map('trim', explode(',', $p['subcats'])));
              $postId   = (int)$p['post_id'];
              $myVote   = (int)$p['my_vote']; // 1 / -1 / 0
              $hasThumb = (string)($p['thumb_filename'] ?? '') !== '';
            ?>
        <article class="post-card card">
          <div class="card-body">
            <h3 class="title">
              <a href="post.php?id=<?= $postId ?>"><?= e($p['title']) ?></a>
            </h3>

            <?php if ($hasThumb): ?>
              <a class="media-top" href="post.php?id=<?= $postId ?>">
                <img
                  loading="lazy"
                  src="<?= e($p['thumb_filename']) ?>"
                  alt="Post media"
                  onerror="this.closest('.media-top').style.display='none';"
                >
              </a>
            <?php endif; ?>

            <?php if ($chips): ?>
              <div class="chips">
                <?php foreach ($chips as $chip): ?><span class="chip"><?= e($chip) ?></span><?php endforeach; ?>
              </div>
            <?php endif; ?>

            <div class="snippet">
              <?= e(mb_strimwidth($p['body'] ?? '', 0, 220, '…')) ?>
            </div>

            <div class="meta">
              <span><?= e($created) ?></span>
              <span>by <a href="profile.php?id=<?= $authorId ?>"><?= e($author) ?></a></span>
            </div>

            <div class="actions">
              <div class="vote" data-post-id="<?= $postId ?>">
                <button class="btn-vote btn-like <?= $myVote===1?'active':'' ?>" <?= $isLoggedIn ? '' : 'disabled title="Sign in to like"' ?>>👍</button>
                <button class="btn-vote btn-dislike <?= $myVote===-1?'active':'' ?>" <?= $isLoggedIn ? '' : 'disabled title="Sign in to dislike"' ?>>👎</button>
                <span class="count">Likes: <strong><?= (int)$p['likes'] ?></strong></span>
                <span class="count">Dislikes: <strong><?= (int)$p['dislikes'] ?></strong></span>
              </div>
            </div>
          </div>
        </article>

            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </main>
    </div>
  </div>

  <script>
    // Gate create-post for unauthenticated users
    (function(){
      const isLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;
      document.querySelectorAll('[data-requires-auth="true"]').forEach(a => {
        a.addEventListener('click', e => {
          if (!isLoggedIn) {
            e.preventDefault();
            alert('Please sign in to create a post.');
            location.href = 'login.php';
          }
        });
      });
    })();

    // Voting (requires vote_post.php + post_vote table)
    (function(){
      const isLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;
      if (!isLoggedIn) return;

      document.querySelectorAll('.vote').forEach(box => {
        const postId = parseInt(box.getAttribute('data-post-id'), 10);
        const btnLike = box.querySelector('.btn-like');
        const btnDis  = box.querySelector('.btn-dislike');
        const counts  = box.querySelectorAll('.count strong');
        const likesStrong    = counts[0];
        const dislikesStrong = counts[1];
async function sendVote(val){
  try{
    const res = await fetch('vote_post.php', {
      method: 'POST',
      headers: { 'Content-Type':'application/json' },
      body: JSON.stringify({ post_id: postId, value: val })
    });

    if (!res.ok) {
      const text = await res.text();
      console.error('Vote HTTP error:', res.status, text);
      alert('Vote failed. Please try again.');
      return;
    }

    const text = await res.text();
    console.log('RAW vote_post.php response:', text);

    let j = null;

    // Try to parse "nice" JSON (exact)
    try {
      j = JSON.parse(text);
    } catch (err1) {
      // Fallback: try to grab the JSON object from inside extra noise
      const match = text.match(/\{[\s\S]*\}/);
      if (match) {
        try {
          j = JSON.parse(match[0]);
        } catch (err2) {
          console.error('JSON parse error (fallback):', err2);
          alert('Server returned invalid response: ' + text);
          return;
        }
      } else {
        console.error('JSON parse error:', err1);
        alert('Server returned invalid response: ' + text);
        return;
      }
    }

    if (j && j.ok){
      likesStrong.textContent    = j.likes;
      dislikesStrong.textContent = j.dislikes;

      if (val === 1){
        if (btnLike.classList.contains('active')) {
          btnLike.classList.remove('active');
        } else {
          btnLike.classList.add('active');
          btnDis.classList.remove('active');
        }
      } else if (val === -1){
        if (btnDis.classList.contains('active')) {
          btnDis.classList.remove('active');
        } else {
          btnDis.classList.add('active');
          btnLike.classList.remove('active');
        }
      }
    } else {
      console.error('Vote logical error payload:', j);
      alert('Vote failed. Please try again.');
    }
  } catch(e){
    console.error('Real network error:', e);
    alert('Network error.');
  }
}


        btnLike?.addEventListener('click', () => sendVote(1));
        btnDis?.addEventListener('click', () => sendVote(-1));
      });
    })();
  </script>
</body>
</html>

