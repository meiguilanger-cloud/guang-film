<?php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
requireLoginJson();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '仅支持 GET 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$songId = (int) ($_GET['song_id'] ?? 0);
if ($songId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '缺少 song_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

function cleanLyricsForEditor(string $lyrics): string {
    $lyrics = str_replace(["\r\n", "\r", "\\n"], "\n", $lyrics);
    $lyrics = preg_replace('/\n+voice\s*:\s*.*$/i', '', $lyrics) ?? $lyrics;
    $lyrics = preg_replace('/\n+(style|genre|mood|tempo|instrumentation)\s*:\s*.*$/im', '', $lyrics) ?? $lyrics;
    $lyrics = preg_replace('/^\s*[^\n]{1,80}\n\s*(词曲|作词|作曲|词|曲)\s*[：:].*(\n\s*)+/u', '', $lyrics, 1) ?? $lyrics;
    $lyrics = preg_replace('/^\s*(词曲|作词|作曲|词|曲)\s*[：:].*(\n\s*)+/u', '', $lyrics, 1) ?? $lyrics;
    return trim($lyrics);
}

$pdo = getPdo();
$stmt = $pdo->prepare('SELECT id, title, lyrics FROM songs WHERE id = :id AND user_id = :user_id LIMIT 1');
$stmt->execute([
    ':id' => $songId,
    ':user_id' => (int) $_SESSION['user_id'],
]);
$song = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$song) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => '歌曲不存在或无权限操作'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'song' => [
        'id' => (int) $song['id'],
        'title' => (string) ($song['title'] ?? ''),
        'lyrics' => cleanLyricsForEditor((string) ($song['lyrics'] ?? '')),
    ],
], JSON_UNESCAPED_UNICODE);
