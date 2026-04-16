<?php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
requireLoginJson();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '仅支持 POST 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$songId = (int) ($input['song_id'] ?? 0);
$title = trim((string) ($input['title'] ?? ''));

if ($songId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '缺少 song_id'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($title === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '歌名不能为空'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($title) > 120) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '歌名不能超过 120 个字符'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getPdo();
$stmt = $pdo->prepare('SELECT id, user_id FROM songs WHERE id = ? LIMIT 1');
$stmt->execute([$songId]);
$song = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$song) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => '歌曲不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ((int) $song['user_id'] !== (int) $_SESSION['user_id']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => '没有权限修改这首歌'], JSON_UNESCAPED_UNICODE);
    exit;
}

$update = $pdo->prepare('UPDATE songs SET title = ? WHERE id = ?');
$update->execute([$title, $songId]);
logMessage('用户 ID ' . (int) $_SESSION['user_id'] . ' 重命名歌曲: song_id=' . $songId . ', title=' . $title);

echo json_encode([
    'ok' => true,
    'song_id' => $songId,
    'title' => $title,
    'message' => '歌曲已重命名',
], JSON_UNESCAPED_UNICODE);
