<?php
// create_post.php — create a new post, with optional media and lexicon flagging
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
// Auth check
// -----------------------------------------------------------------------------
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: ./login.php'); exit; }

$role   = $user['role']   ?? 'registered';
$status = $user['status'] ?? 'active';
$allowedRoles = ['registered', 'moderator', 'admin'];
if ($status !== 'active' || !in_array($role, $allowedRoles, true)) {
  http_response_code(403);
  echo "<h2>Not allowed</h2><p>Your account can’t create posts.</p>";
  exit;
}

$enableUploads = true;

// -----------------------------------------------------------------------------
// Load categories
// -----------------------------------------------------------------------------
$mainCategories = [];
try {
  $stmt = $conn->query("SELECT main_category_id, name, slug FROM main_category ORDER BY name");
  $mainCategories = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {}

$allSubcats = [];
try {
  $stmt = $conn->query("
    SELECT s.subcategory_id, s.name, s.slug, s.main_category_id
    FROM subcategory s
    ORDER BY s.name
  ");
  $allSubcats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {}

// Prefill main category (if user clicked “create” from a category page)
$prefillMainId = null;
if (isset($_GET['main_id']) && ctype_digit($_GET['main_id'])) {
  $cand = (int)$_GET['main_id'];
  $chk  = $conn->prepare("SELECT main_category_id FROM main_category WHERE main_category_id = :id");
  $chk->execute([':id' => $cand]);
  if ($chk->fetchColumn()) $prefillMainId = $cand;
} elseif (!empty($_GET['main'])) {
  $chk = $conn->prepare("SELECT main_category_id FROM main_category WHERE slug = :slug");
  $chk->execute([':slug' => $_GET['main']]);
  if ($row = $chk->fetch(PDO::FETCH_ASSOC)) $prefillMainId = (int)$row['main_category_id'];
}

// -----------------------------------------------------------------------------
// Lexicon (bad words)
// -----------------------------------------------------------------------------
$LEXICON = [];
$lexiconPath = __DIR__ . '/bad_words.txt';
if (is_file($lexiconPath)) {
  $lines = file($lexiconPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $ln) {
    $t = trim($ln);
    if ($t === '' || str_starts_with($t, '#')) continue;
    $LEXICON[] = $t;
  }
}

function scanLexiconSimple(array $terms, string $title, string $body): array {
  if (!$terms) return [0, null];
  $hay = $title . ' ' . $body;
  $hits = 0; $first = null;
  foreach ($terms as $term) {
    if ($term === '') continue;
    if (stripos($hay, $term) !== false) {
      $hits++;
      if ($first === null) $first = $term;
    }
  }
  return [$hits, $first];
}

// -----------------------------------------------------------------------------
// Handle post submission
// -----------------------------------------------------------------------------
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title   = trim($_POST['title'] ?? '');
  $body    = trim($_POST['body'] ?? '');
  $mainId  = (int)($_POST['main_category_id'] ?? 0);
  $subIds  = array_map('intval', $_POST['subcategories'] ?? []);

  if ($mainId <= 0 && $prefillMainId) $mainId = $prefillMainId;

  // validation
  if ($title === '') $errors[] = 'Please enter a title.';
  if ($body === '')  $errors[] = 'Please enter some content.';
  if ($mainId <= 0)  $errors[] = 'Please pick a main category.';

  // verify main exists
  if ($mainId > 0) {
    $chkMain = $conn->prepare("SELECT 1 FROM main_category WHERE main_category_id = :mid");
    $chkMain->execute([':mid' => $mainId]);
    if (!$chkMain->fetchColumn()) $errors[] = 'Selected main category not found.';
  }

  // clean up subcategory list
  if ($subIds) {
    $map = [];
    foreach ($allSubcats as $sc) {
      $map[(int)$sc['subcategory_id']] = (int)$sc['main_category_id'];
    }
    $subIds = array_values(array_unique(array_filter(
      $subIds,
      fn($sid) => ($map[$sid] ?? 0) === $mainId
    )));
  }

  // lexicon scan
  [$lexiconHits, $firstHit] = scanLexiconSimple($LEXICON, $title, $body);
  $postStatus = $lexiconHits > 0 ? 'flagged' : 'live';

  if (!$errors) {
    try {
      $conn->beginTransaction();

      // ✅ FIX: ensure main_category_id is inserted properly
      $ins = $conn->prepare("
        INSERT INTO post (user_id, main_category_id, title, body, content_status)
        VALUES (:uid, :mid, :title, :body, :status)
      ");
      $ins->execute([
        ':uid'    => (int)$user['user_id'],
        ':mid'    => $mainId,
        ':title'  => $title,
        ':body'   => $body,
        ':status' => $postStatus,
      ]);
      $postId = (int)$conn->lastInsertId();

      // link subcategories (optional)
      if ($subIds) {
        $ps = $conn->prepare("INSERT IGNORE INTO post_subcategory (post_id, subcategory_id) VALUES (:p, :s)");
        foreach ($subIds as $sid) {
          $ps->execute([':p' => $postId, ':s' => $sid]);
        }
      }

      // record flag if needed
      if ($postStatus === 'flagged') {
        $flag = $conn->prepare("
          INSERT INTO flag (post_id, trigger_source, trigger_hits, trigger_word, status)
          VALUES (:pid, 'lexicon', :hits, :word, 'flagged')
        ");
        $flag->execute([
          ':pid'  => $postId,
          ':hits' => $lexiconHits,
          ':word' => $firstHit,
        ]);
      }

      $conn->commit();

      // ✅ FIX: redirect back to correct main slug (so it shows properly)
      $mainSlug = '';
      foreach ($mainCategories as $mc) {
        if ((int)$mc['main_category_id'] === $mainId) {
          $mainSlug = $mc['slug'];
          break;
        }
      }
      header('Location: ' . ($mainSlug ? './index.php?main=' . urlencode($mainSlug) : './index.php'));
      exit;

    } catch (Throwable $t) {
      if ($conn->inTransaction()) $conn->rollBack();
      $errors[] = 'Could not create the post.';
    }
  }
}

// -----------------------------------------------------------------------------
// Prepare form data
// -----------------------------------------------------------------------------
$subByMain = [];
foreach ($allSubcats as $sc) {
  $m = (int)$sc['main_category_id'];
  $subByMain[$m][] = ['id' => (int)$sc['subcategory_id'], 'name' => $sc['name']];
}

$defaultMainId = (int)($_POST['main_category_id'] ?? 0);
if ($defaultMainId <= 0 && $prefillMainId) $defaultMainId = $prefillMainId;
if ($defaultMainId <= 0 && $mainCategories) $defaultMainId = (int)$mainCategories[0]['main_category_id'];
$postedSubs = array_map('intval', $_POST['subcategories'] ?? []);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Create Post — Cat Corner</title>
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
    <div class="nav-right">
      <span class="pill">
        <?= e($user['display_name'] ?? $user['username']) ?> (<?= e($role) ?>)
      </span>
      <a class="btn-outline" href="logout.php">Log out</a>
    </div>
  </nav>

  <main class="container">
    <div class="logo">
      <img src="doodles/create_user_logo.jpg" alt="create post">
    </div>
    <h1>Create Post</h1>
    <p class="sub">Share something paw-some! Add images, gifs, or videos.</p>

    <?php if ($errors): ?>
      <ul class="error-list">
        <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="form" autocomplete="off">
      <div class="form-group">
        <label for="title">Title</label>
        <input id="title" name="title" required maxlength="200" value="<?= e($_POST['title'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label for="body">Body</label>
        <textarea id="body" name="body" rows="6" required><?= e($_POST['body'] ?? '') ?></textarea>
      </div>

      <div class="form-group">
        <label for="main_category_id">Main Category</label>
        <select id="main_category_id" name="main_category_id" required>
          <?php foreach ($mainCategories as $mc): ?>
            <option value="<?= (int)$mc['main_category_id'] ?>" <?= ((int)$mc['main_category_id'] === $defaultMainId) ? 'selected' : '' ?>>
              <?= e($mc['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Subcategories (optional)</label>
        <div id="subcatBox" class="subcat-list"></div>
      </div>

      <button type="submit" class="btn">Publish Post</button>
    </form>
  </main>

<script>
  const SUB_BY_MAIN = <?= json_encode($subByMain, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const PREV_SUBS   = new Set(<?= json_encode($postedSubs) ?>);

  const mainSelect = document.getElementById('main_category_id');
  const subBox     = document.getElementById('subcatBox');

  function renderSubcats(mainId) {
    subBox.innerHTML = '';
    const list = SUB_BY_MAIN[String(mainId)] || [];
    if (!list.length) {
      subBox.innerHTML = '<div class="muted">No subcategories available.</div>';
      return;
    }
    list.forEach(sc => {
      const row = document.createElement('div');
      const cb  = document.createElement('input');
      cb.type = 'checkbox';
      cb.name = 'subcategories[]';
      cb.id = 'sub_' + sc.id;
      cb.value = sc.id;
      if (PREV_SUBS.has(sc.id)) cb.checked = true;

      const lab = document.createElement('label');
      lab.htmlFor = cb.id;
      lab.textContent = sc.name;

      row.appendChild(cb);
      row.appendChild(lab);
      subBox.appendChild(row);
    });
  }

  renderSubcats(<?= (int)$defaultMainId ?>);
  mainSelect.addEventListener('change', () => renderSubcats(parseInt(mainSelect.value, 10)));
</script>
</body>
</html>

