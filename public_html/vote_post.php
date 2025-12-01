<?php
// vote_post.php — handles like/dislike
declare(strict_types=1);
session_start();

// Keep this endpoint JSON-only (no PHP warnings/HTML)
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

header('Content-Type: application/json');

require_once "database.php";

try {
    $user = $_SESSION['user'] ?? null;
    if (!$user || empty($user['user_id'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'auth']);
        exit;
    }
    $userId = (int)$user['user_id'];

    $raw = file_get_contents('php://input');
    if ($raw === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'no_body']);
        exit;
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_json']);
        exit;
    }

    $postId = isset($payload['post_id']) ? (int)$payload['post_id'] : 0;
    $val    = isset($payload['value'])   ? (int)$payload['value']   : 0; // +1 or -1

    if ($postId <= 0 || !in_array($val, [1, -1], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_request']);
        exit;
    }

    $conn = Database::dbConnect();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check existing vote
    $stmt = $conn->prepare("
        SELECT value
        FROM post_vote
        WHERE post_id = :pid AND user_id = :uid
    ");
    $stmt->execute([':pid' => $postId, ':uid' => $userId]);
    $cur = $stmt->fetchColumn();

    if ($cur !== false) {
        $cur = (int)$cur;
        if ($cur === $val) {
            // Toggle off (remove vote)
            $del = $conn->prepare("
                DELETE FROM post_vote
                WHERE post_id = :pid AND user_id = :uid
            ");
            $del->execute([':pid' => $postId, ':uid' => $userId]);
        } else {
            // Update to new value
            $upd = $conn->prepare("
                UPDATE post_vote
                SET value = :v
                WHERE post_id = :pid AND user_id = :uid
            ");
            $upd->execute([':v' => $val, ':pid' => $postId, ':uid' => $userId]);
        }
    } else {
        // Insert new vote
        $ins = $conn->prepare("
            INSERT INTO post_vote (post_id, user_id, value)
            VALUES (:pid, :uid, :v)
        ");
        $ins->execute([':pid' => $postId, ':uid' => $userId, ':v' => $val]);
    }

    // Return fresh tallies
    $agg = $conn->prepare("
        SELECT
          SUM(value = 1)  AS likes,
          SUM(value = -1) AS dislikes,
          COALESCE(SUM(value), 0) AS score
        FROM post_vote
        WHERE post_id = :pid
    ");
    $agg->execute([':pid' => $postId]);
    $row = $agg->fetch(PDO::FETCH_ASSOC) ?: [
        'likes'    => 0,
        'dislikes' => 0,
        'score'    => 0
    ];

    echo json_encode([
        'ok'       => true,
        'likes'    => (int)$row['likes'],
        'dislikes' => (int)$row['dislikes'],
        'score'    => (int)$row['score'],
    ]);
} catch (Throwable $t) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'server',
    ]);
}

