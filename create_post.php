<?php
// create_post.php — create a new post with optional media (jpg/png/gif/mp4)
// Posts are auto-flagged if media is attached OR lexicon matches.
declare(strict_types=1);
session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once "database.php";
$conn = Database::dbConnect();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

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

$LEXICON = [];
$lexiconPath = __DIR__ . '/bad_words.txt';
if (is_file($lexiconPath)) {
  $lines = file($lexiconPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $ln) {
    $t = trim($ln);
    if ($t === '' || str_starts_with($t, '#')) continue;
    $LEXICON[] = mb_strtolower($t, 'UTF-8');
  }
}
function scanLexicon(array $terms, string $text): array {
  if (!$terms) return [0, null];
  $hay = ' ' . mb_strtolower($text, 'UTF-8') . ' ';
  $hits = 0; $first = null;
  foreach ($terms as $termRaw) {
    $term = mb_strtolower(trim($termRaw), 'UTF-8');
    if ($term === '') continue;
    $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/u';
    if (preg_match($pattern, $hay)) {
      $hits++;
      if ($first === null) $first = $term;
    }
  }
  return [$hits, $first];
}

// messages for PHP upload errors
function uploadErrorMessage(int $code): string {
  return match ($code) {
    UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the server limit (upload_max_filesize).',
    UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the form limit (MAX_FILE_SIZE).',
    UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded.',
    UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
    UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder.',
    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
    UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
    default               => 'Unknown upload error.',
  };
}

$errors = [];
$subByMain = [];
foreach ($allSubcats as $sc) {
  $m = (int)$sc['main_category_id'];
  $subByMain[$m][] = ['id' => (int)$sc['subcategory_id'], 'name' => $sc['name']];
}

$defaultMainId = (int)($_POST['main_category_id'] ?? 0);
if ($defaultMainId <= 0 && $prefillMainId) $defaultMainId = $prefillMainId;
if ($defaultMainId <= 0 && $mainCategories) $defaultMainId = (int)$mainCategories[0]['main_category_id'];
$postedSubs = array_map('intval', $_POST['subcategories'] ?? []);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title   = trim($_POST['title'] ?? '');
  $body    = trim($_POST['body'] ?? '');
  $mainId  = (int)($_POST['main_category_id'] ?? 0);
  $subIds  = array_map('intval', $_POST['subcategories'] ?? []);

  if ($mainId <= 0 && $prefillMainId) $mainId = $prefillMainId;

  if ($title === '') $errors[] = 'Please enter a title.';
  if ($body === '')  $errors[] = 'Please enter some content.';
  if ($mainId <= 0)  $errors[] = 'Please pick a main category.';

  if ($mainId > 0) {
    $chkMain = $conn->prepare("SELECT 1 FROM main_category WHERE main_category_id = :mid");
    $chkMain->execute([':mid' => $mainId]);
    if (!$chkMain->fetchColumn()) $errors[] = 'Selected main category not found.';
  }

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

  [$lexHits, $firstHit] = scanLexicon($LEXICON, $title . "\n" . $body);
  $mediaProvided = isset($_FILES['media']) && is_array($_FILES['media']) && ($_FILES['media']['error'] !== UPLOAD_ERR_NO_FILE);
  $postStatus = ($lexHits > 0 || $mediaProvided) ? 'flagged' : 'live';

  $MAX_BYTES = 30 * 1024 * 1024; // 30 MB
  $incomingMime = '';

  if ($mediaProvided) {
    $f = $_FILES['media'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
      $errors[] = 'Media upload failed: ' . uploadErrorMessage((int)$f['error']);
    } elseif ($f['size'] > $MAX_BYTES) {
      $errors[] = 'Media exceeds 30 MB limit.';
    } elseif (!is_uploaded_file($f['tmp_name'])) {
      $errors[] = 'Suspicious upload: temp file is invalid.';
    } else {
      $fi = new finfo(FILEINFO_MIME_TYPE);
      $incomingMime = $fi->file($f['tmp_name']) ?: '';
      $allowed = ['image/jpeg', 'image/png', 'image/gif', 'video/mp4'];
      if (!in_array($incomingMime, $allowed, true)) {
        $errors[] = 'Only JPG, PNG, GIF, and MP4 files are allowed.';
      }
    }
  }

  if (!$errors) {
    try {
      $conn->beginTransaction();

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

      if ($subIds) {
        $ps = $conn->prepare("INSERT IGNORE INTO post_subcategory (post_id, subcategory_id) VALUES (:p, :s)");
        foreach ($subIds as $sid) {
          $ps->execute([':p' => $postId, ':s' => $sid]);
        }
      }

      // Flag row if lexicon triggered
      if ($postStatus === 'flagged' && $lexHits > 0) {
        $flag = $conn->prepare("
          INSERT INTO flag (post_id, trigger_source, trigger_hits, trigger_word, status)
          VALUES (:pid, 'lexicon', :hits, :word, 'flagged')
        ");
        $flag->execute([
          ':pid'  => $postId,
          ':hits' => $lexHits,
          ':word' => $firstHit,
        ]);
      }

      if ($mediaProvided && $incomingMime) {
        $uploadsDir = __DIR__ . '/uploads';

        if (!is_dir($uploadsDir)) {
          @mkdir($uploadsDir, 0757, true);  
        }

        if (is_dir($uploadsDir) && !is_writable($uploadsDir)) {
          @chmod($uploadsDir, 0757);
        }

        if (!is_dir($uploadsDir) || !is_writable($uploadsDir)) {
          $errors[] = 'not writable';
        } else {
          $ext = match ($incomingMime) {
            'image/jpeg' => '.jpg',
            'image/png'  => '.png',
            'image/gif'  => '.gif',
            'video/mp4'  => '.mp4',
            default      => '',
          };
          $mediaType = (str_starts_with($incomingMime, 'video/')) ? 'video' : (($incomingMime === 'image/gif') ? 'gif' : 'image');

          $safeName = bin2hex(random_bytes(16)) . $ext;
          $destPath = $uploadsDir . '/' . $safeName;

          if (!@move_uploaded_file($_FILES['media']['tmp_name'], $destPath)) {
            $errors[] = 'cant move to folder';
          } else {
            @chmod($destPath, 0644);
            $stm = $conn->prepare("
              INSERT INTO media (post_id, filename, type, moderation_status)
              VALUES (:pid, :fn, :type, 'pending')
            ");
            $stm->execute([
              ':pid'  => $postId,
              ':fn'   => 'uploads/' . $safeName, 
              ':type' => $mediaType,
            ]);
          }
        }
      }

      if ($errors) {
        $conn->rollBack();
      } else {
        $conn->commit();
        // Redirect
        $mainSlug = '';
        foreach ($mainCategories as $mc) {
          if ((int)$mc['main_category_id'] === $mainId) { $mainSlug = $mc['slug']; break; }
        }
        header('Location: ' . ($mainSlug ? './index.php?main=' . urlencode($mainSlug) : './index.php'));
        exit;
      }

    } catch (Throwable $t) {
      if ($conn->inTransaction()) $conn->rollBack();
      $errors[] = $t->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Create Post — Cat Corner</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
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
      <?php if (in_array($role, ['registered', 'moderator', 'admin'], true)): ?>
        <a href="my_reviews.php" class="nav-link">My Reviews</a>
      <?php endif; ?>
      <?php if (in_array($role, ['moderator','admin'], true)): ?>
        <a href="mod_flags.php" class="nav-link">Moderation Queue</a>
      <?php endif; ?>
      <?php if ($role === 'admin'): ?>
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
    </div>
  </nav>

  <main class="container">  
    <h1>Create Post</h1>
    <p class="sub">Add images, GIFs, or short MP4 videos. All uploads go through moderation.</p>

    <?php if ($errors): ?>
      <ul class="error-list"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
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

      <div class="form-group">
        <label for="media">Media (optional, up to 30 MB)</label>
        <input id="media" name="media" type="file" accept="image/jpeg,image/png,image/gif,video/mp4">
        <small class="muted">Allowed: JPG, PNG, GIF, MP4 • Max 30 MB • Reviewed before appearing.</small>
      </div>

      <button type="submit" class="btn">Publish Post</button>
    </form>
  </main>

<script>
  const SUB_BY_MAIN = <?= json_encode($subByMain, JSON_UNESCAPED_UNICODE) ?>;
  const PREV_SUBS   = new Set(<?= json_encode($postedSubs) ?>);
  const mainSelect = document.getElementById('main_category_id');
  const subBox = document.getElementById('subcatBox');

  function renderSubcats(mainId) {
    subBox.innerHTML = '';
    const list = SUB_BY_MAIN[String(mainId)] || [];
    if (!list.length) {
      subBox.innerHTML = '<div class="muted">No subcategories available.</div>';
      return;
    }
    list.forEach(sc => {
      const row = document.createElement('div');
      row.className = 'subcat-row';
      const cb = document.createElement('input');
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


