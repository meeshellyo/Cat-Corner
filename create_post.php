<?php
// create_post.php 
declare(strict_types=1);
session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once "database.php";
$conn = Database::dbConnect();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user = $_SESSION['user'] ?? null;
if (!$user) {
  header('Location: ./login.php');
  exit;
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// load mains
$mainCategories = [];
try {
  $stmt = $conn->query("SELECT main_category_id, name, slug FROM main_category ORDER BY name");
  $mainCategories = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  $mainCategories = [];
}

// load subcats
$allSubcats = [];
try {
  $stmt = $conn->query("
    SELECT s.subcategory_id, s.name, s.slug, s.main_category_id
    FROM subcategory s
    ORDER BY s.name
  ");
  $allSubcats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  $allSubcats = [];
}

// uploads
$maxMb     = 10;
$maxBytes  = $maxMb * 1024 * 1024;
$uploadDir = __DIR__ . '/uploads';
$uploadUrl = 'uploads';
if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }

$errors = [];

// handle submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title       = trim($_POST['title'] ?? '');
  $body        = trim($_POST['body'] ?? '');
  $mainId      = (int)($_POST['main_category_id'] ?? 0);
  $subIds      = array_map('intval', $_POST['subcategories'] ?? []);

  if ($title === '') $errors[] = 'please enter a title.';
  if ($body === '')  $errors[] = 'please enter some content.';
  if ($mainId <= 0)  $errors[] = 'please pick a main category.';

  // keep only subcats under the chosen main
  if ($subIds) {
    $map = [];
    foreach ($allSubcats as $sc) { $map[(int)$sc['subcategory_id']] = (int)$sc['main_category_id']; }
    $subIds = array_values(array_filter($subIds, fn($sid) => ($map[$sid] ?? 0) === $mainId));
  }

  if (!$errors) {
    try {
      // insert post (direct main id stored here)
      $ins = $conn->prepare("
        INSERT INTO post (user_id, main_category_id, title, body, content_status)
        VALUES (:uid, :mid, :title, :body, 'live')
      ");
      $ins->execute([
        ':uid'   => (int)$user['user_id'],
        ':mid'   => $mainId,
        ':title' => $title,
        ':body'  => $body,
      ]);
      $postId = (int)$conn->lastInsertId();

      // link subcats
      if ($postId && $subIds) {
        $ps = $conn->prepare("INSERT INTO post_subcategory (post_id, subcategory_id) VALUES (:p, :s)");
        foreach ($subIds as $sid) { $ps->execute([':p' => $postId, ':s' => $sid]); }
      }

      // handle media (optional)
      if (!empty($_FILES['media_files']) && is_array($_FILES['media_files']['name'])) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $count = count($_FILES['media_files']['name']);
        for ($i = 0; $i < $count; $i++) {
          $err  = $_FILES['media_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
          $size = $_FILES['media_files']['size'][$i]  ?? 0;
          $tmp  = $_FILES['media_files']['tmp_name'][$i] ?? '';
          $name = $_FILES['media_files']['name'][$i] ?? '';

          if ($err === UPLOAD_ERR_NO_FILE) continue;
          if ($err !== UPLOAD_ERR_OK) { $errors[] = 'a file failed to upload.'; continue; }
          if ($size > $maxBytes) { $errors[] = e($name) . ' is too large. max ' . $maxMb . ' MB.'; continue; }

          $mime = $tmp ? ($finfo->file($tmp) ?: '') : '';
          $type = 'other';
          if (strpos($mime, 'image/') === 0) $type = (stripos($mime, 'gif') !== false) ? 'gif' : 'image';
          elseif (strpos($mime, 'video/') === 0) $type = 'video';

          $ext = pathinfo($name, PATHINFO_EXTENSION);
          $base = 'media_' . $postId . '_' . bin2hex(random_bytes(6));
          $fileName = $base . ($ext ? ('.' . preg_replace('/[^A-Za-z0-9_.-]/', '', $ext)) : '');
          $dest = $uploadDir . '/' . $fileName;
          $rel  = $uploadUrl . '/' . $fileName;

          if (!move_uploaded_file($tmp, $dest)) { $errors[] = 'failed to save ' . e($name); continue; }

          $ms = $conn->prepare("INSERT INTO media (post_id, filename, type) VALUES (:p, :f, :t)");
          $ms->execute([':p' => $postId, ':f' => $rel, ':t' => $type]);
        }
      }

      // redirect to the selected main
      $mainSlug = '';
      foreach ($mainCategories as $mc) {
        if ((int)$mc['main_category_id'] === $mainId) { $mainSlug = $mc['slug']; break; }
      }
      $dest = $mainSlug ? ('./index.php?main=' . urlencode($mainSlug)) : './index.php';
      header('Location: ' . $dest);
      exit;

    } catch (Throwable $t) {
      $errors[] = 'could not create the post.';
    }
  }
}

// js payloads
$subByMain = [];
foreach ($allSubcats as $sc) {
  $m = (int)$sc['main_category_id'];
  if (!isset($subByMain[$m])) $subByMain[$m] = [];
  $subByMain[$m][] = ['id' => (int)$sc['subcategory_id'], 'name' => $sc['name']];
}

$defaultMainId = (int)($_POST['main_category_id'] ?? 0);
if ($defaultMainId <= 0 && $mainCategories) $defaultMainId = (int)$mainCategories[0]['main_category_id'];
$postedSubs = array_map('intval', $_POST['subcategories'] ?? []);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Create Post — Cat Corner</title>
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
    <div class="nav-right">
      <span class="pill">Signed in as <?= e($user['display_name'] ?? $user['username'] ?? 'user') ?></span>
      <a class="btn-outline" href="logout.php">Log out</a>
    </div>
  </nav>

  <main class="container">
    <div class="logo">
      <img src="doodles/create_user_logo.jpg" alt="create post">
    </div>
    <h1>Create Post</h1>
    <p class="sub">share something paw-some! add images, gifs, or videos (max <?= (int)$maxMb ?>mb each).</p>

    <?php if (!empty($errors)): ?>
      <ul class="error-list">
        <?php foreach ($errors as $e): ?>
          <li><?= e($e) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <form method="post" action="create_post.php" enctype="multipart/form-data" class="form" autocomplete="off">
      <div class="form-group">
        <label for="title">title</label>
        <input id="title" name="title" required placeholder="title" value="<?= e($_POST['title'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label for="body">body</label>
        <textarea id="body" name="body" rows="6" required placeholder="[text]"><?= e($_POST['body'] ?? '') ?></textarea>
      </div>

      <div class="form-group">
        <label for="main_category_id">main category</label>
        <select id="main_category_id" name="main_category_id" required>
          <?php foreach ($mainCategories as $mc): ?>
            <option value="<?= (int)$mc['main_category_id'] ?>" <?= ((int)$mc['main_category_id'] === $defaultMainId) ? 'selected' : '' ?>>
              <?= e($mc['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>subcategories (optional)</label>
        <div id="subcatBox" class="grid"></div>
      </div>

      <div class="form-group">
        <label for="media_files">media (images/gifs/videos) — max <?= (int)$maxMb ?>mb each</label>
        <input id="media_files" name="media_files[]" type="file" multiple accept="image/*,video/*,.gif">
        <small class="muted">you can attach multiple files.</small>
      </div>

      <button type="submit" class="btn">publish post</button>
    </form>
  </main>

  <script>
    const SUB_BY_MAIN = <?= json_encode($subByMain, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const PREV_SUBS   = new Set(<?= json_encode($postedSubs) ?>);

    const mainSelect = document.getElementById('main_category_id');
    const subBox     = document.getElementById('subcatBox');

    function renderSubcats(mainId) {
      subBox.innerHTML = '';
      const list = SUB_BY_MAIN[mainId] || [];
      if (!list.length) {
        subBox.innerHTML = '<div class="muted">no subcategories for this main (optional).</div>';
        return;
      }
      list.forEach(sc => {
        const id = 'sub_' + sc.id;
        const wrap = document.createElement('div');
        wrap.className = 'card';
        wrap.style.display = 'flex';
        wrap.style.alignItems = 'center';
        wrap.style.gap = '.5rem';

        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.id = id;
        cb.name = 'subcategories[]';
        cb.value = sc.id;
        if (PREV_SUBS.has(sc.id)) cb.checked = true;

        const label = document.createElement('label');
        label.htmlFor = id;
        label.textContent = sc.name;

        wrap.appendChild(cb);
        wrap.appendChild(label);
        subBox.appendChild(wrap);
      });
    }

    // init + change
    renderSubcats(parseInt(mainSelect.value, 10));
    mainSelect.addEventListener('change', () => {
      renderSubcats(parseInt(mainSelect.value, 10));
    });
  </script>
</body>
</html>

