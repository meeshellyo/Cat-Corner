<?php
// vote_post.php — handles like/dislike
declare(strict_types=1);
session_start();

header('Content-Type: application/json');

require_once "database.php";

$user = $_SESSION['user'] ?? null;
if (!$user) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'auth']);
  exit;
}
$userId = (int)$user['user_id'];

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$postId = isset($payload['post_id']) ? (int)$payload['post_id'] : 0;
$val    = isset($payload['value']) ? (int)$payload['value'] : 0; // +1 or -1

if ($postId <= 0 || !in_array($val, [1, -1], true)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'bad_request']);
  exit;
}

try {
  $conn = Database::dbConnect();
  $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Upsert: if same value, toggle off (remove); if different, update
  $stmt = $conn->prepare("SELECT value FROM post_vote WHERE post_id = :pid AND user_id = :uid");
  $stmt->execute([':pid' => $postId, ':uid' => $userId]);
  $cur = $stmt->fetchColumn();

  if ($cur !== false) {
    $cur = (int)$cur;
    if ($cur === $val) {
      // toggle off
      $del = $conn->prepare("DELETE FROM post_vote WHERE post_id = :pid AND user_id = :uid");
      $del->execute([':pid' => $postId, ':uid' => $userId]);
    } else {
      $upd = $conn->prepare("UPDATE post_vote SET value = :v WHERE post_id = :pid AND user_id = :uid");
      $upd->execute([':v' => $val, ':pid' => $postId, ':uid' => $userId]);
    }
  } else {
    $ins = $conn->prepare("INSERT INTO post_vote (post_id, user_id, value) VALUES (:pid, :uid, :v)");
    $ins->execute([':pid' => $postId, ':uid' => $userId, ':v' => $val]);
  }

  // return new tallies
  $agg = $conn->prepare("
    SELECT
      SUM(value = 1)  AS likes,
      SUM(value = -1) AS dislikes,
      COALESCE(SUM(value), 0) AS score
    FROM post_vote WHERE post_id = :pid
  ");
  $agg->execute([':pid' => $postId]);
  $row = $agg->fetch(PDO::FETCH_ASSOC) ?: ['likes'=>0,'dislikes'=>0,'score'=>0];

  echo json_encode(['ok' => true, 'likes' => (int)$row['likes'], 'dislikes' => (int)$row['dislikes'], 'score' => (int)$row['score']]);
} catch (Throwable $t) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'server']);
}
