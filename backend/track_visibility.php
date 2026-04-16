<?php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

requireLoginJson();

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$songId = (int) ($data['song_id'] ?? 0);
$visibility = $data['visibility'] ?? null;
if ($songId <= 0 || !in_array($visibility, ['public', 'private'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid parameters'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getPdo();
$ownerStmt = $pdo->prepare('SELECT user_id FROM songs WHERE id = :id LIMIT 1');
$ownerStmt->execute([':id' => $songId]);
$ownerId = (int) ($ownerStmt->fetchColumn() ?: 0);
if ($ownerId !== (int) ($_SESSION['user_id'] ?? 0)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => '只能修改你自己的歌曲'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $pdo->prepare('UPDATE songs SET visibility = :vis WHERE id = :id');
$stmt->execute([':vis' => $visibility, ':id' => $songId]);

echo json_encode(['ok' => true, 'visibility' => $visibility], JSON_UNESCAPED_UNICODE);
