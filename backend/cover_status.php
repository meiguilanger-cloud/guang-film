<?php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$coverTaskId = trim((string) ($_GET['cover_task_id'] ?? $_POST['cover_task_id'] ?? ''));
if ($coverTaskId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'cover_task_id missing'], JSON_UNESCAPED_UNICODE);
    exit;
}

$config = starAiApiConfig();
$apiKey = trim((string) ($config['suno_api_key'] ?? ''));
$apiBase = rtrim((string) ($config['suno_api_base'] ?? 'https://api.sunoapi.org'), '/');

// Cover tasks are returned by the generic generate record endpoint; the cover-specific path keeps returning data:null.
$ch = curl_init($apiBase . '/api/v1/generate/record-info?taskId=' . rawurlencode($coverTaskId));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => '请求失败: ' . $curlError], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($response, true);
if (!is_array($data)) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => '无法解析返回', 'raw' => $response], JSON_UNESCAPED_UNICODE);
    exit;
}

$code = (int) ($data['code'] ?? 0);
if ($httpCode >= 400 || $code !== 200) {
    http_response_code($httpCode >= 400 ? $httpCode : 400);
    echo json_encode([
        'ok' => false,
        'error' => $data['msg'] ?? $data['message'] ?? 'unknown error',
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = $data['data'] ?? [];
$rawStatus = $payload['status'] ?? null;
$rawTracks = $payload['tracks'] ?? $payload['response']['tracks'] ?? $payload['response']['sunoData'] ?? [];

function extractDisplayStyle(array $payload, array $track, ?array $originalSong = null): string {
    $fallback = trim((string) ($originalSong['description'] ?? 'AI 翻唱'));
    $styleText = '';
    $promptText = '';

    if (!empty($payload['param']) && is_string($payload['param'])) {
        $paramData = json_decode($payload['param'], true);
        if (is_array($paramData)) {
            $styleText = trim((string) ($paramData['style'] ?? ''));
            $promptText = trim((string) ($paramData['prompt'] ?? ''));
        }
    }

    if ($promptText !== '' && preg_match('/补充要求：\s*([^\n\r]+)/u', $promptText, $matches)) {
        $candidate = trim((string) ($matches[1] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    if ($styleText === '') {
        $styleText = trim((string) ($track['tags'] ?? ''));
    }

    if ($styleText === '') {
        return $fallback !== '' ? $fallback : 'AI 翻唱';
    }

    $styleText = preg_split('/[;；]/u', $styleText)[0] ?? $styleText;
    $styleText = trim((string) $styleText);

    return $styleText !== '' ? $styleText : ($fallback !== '' ? $fallback : 'AI 翻唱');
}

$status = 'pending';
if (is_string($rawStatus) && $rawStatus !== '') {
    $normalized = strtolower(trim($rawStatus));
    $statusAliasMap = [
        'success' => 'success',
        'completed' => 'success',
        'complete' => 'success',
        'succeeded' => 'success',
        'pending' => 'pending',
        'queued' => 'pending',
        'processing' => 'generating',
        'running' => 'generating',
        'generating' => 'generating',
        'failed' => 'failed',
        'fail' => 'failed',
        'error' => 'failed',
        'timeout' => 'failed',
        'cancelled' => 'failed',
        'canceled' => 'failed',
    ];
    $status = $statusAliasMap[$normalized] ?? 'pending';
} elseif (is_numeric($rawStatus)) {
    $statusMap = [0 => 'pending', 1 => 'success', 2 => 'generating', 3 => 'failed'];
    $status = $statusMap[(int) $rawStatus] ?? 'pending';
}

$tracks = [];
$expectedTrackCount = is_array($rawTracks) ? count($rawTracks) : 0;
if (is_array($rawTracks)) {
    foreach ($rawTracks as $index => $track) {
        if (!is_array($track)) {
            continue;
        }
        $audioUrl = trim((string) ($track['audio_url'] ?? $track['audioUrl'] ?? $track['stream_audio_url'] ?? $track['streamAudioUrl'] ?? ''));
        if ($audioUrl === '') {
            continue;
        }
        $tracks[] = [
            'slot_index' => (int) $index,
            'audio_url' => $audioUrl,
            'stream_audio_url' => trim((string) ($track['stream_audio_url'] ?? $track['streamAudioUrl'] ?? '')),
            'image_url' => trim((string) ($track['image_url'] ?? $track['imageUrl'] ?? '')),
            'title' => trim((string) ($track['title'] ?? 'STAR.AI 翻唱作品')),
            'tags' => trim((string) ($track['tags'] ?? 'AI 翻唱')),
            'lyrics' => trim((string) ($track['lyrics'] ?? $track['prompt'] ?? '')),
            'prompt' => trim((string) ($track['prompt'] ?? '')),
            'audio_id' => trim((string) ($track['audio_id'] ?? $track['audioId'] ?? $track['id'] ?? '')),
        ];
    }
}

$readyTrackCount = count($tracks);
if ($status === 'success' && $expectedTrackCount > 0 && $readyTrackCount < $expectedTrackCount) {
    $status = 'generating';
} elseif ($status !== 'failed' && $readyTrackCount > 0 && $expectedTrackCount > 0 && $readyTrackCount < $expectedTrackCount) {
    $status = 'generating';
}

$pdo = getPdo();
$findSongStmt = $pdo->prepare('SELECT id, user_id, title, description, lyrics FROM songs WHERE cover_task_id = :cover_task_id ORDER BY id DESC LIMIT 1');
$findSongStmt->execute([':cover_task_id' => $coverTaskId]);
$originalSong = $findSongStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$updateStatusStmt = $pdo->prepare('UPDATE songs SET cover_status = :cover_status WHERE cover_task_id = :cover_task_id');
$updateStatusStmt->execute([
    ':cover_status' => $status,
    ':cover_task_id' => $coverTaskId,
]);

if ($originalSong && !empty($tracks)) {
    $existsStmt = $pdo->prepare('SELECT id FROM songs WHERE user_id = :user_id AND file_path = :path LIMIT 1');
    $insertStmt = $pdo->prepare('INSERT INTO songs (title, description, lyrics, lyrics_status, lyrics_note, file_path, image_url, source, user_id, visibility, cover_task_id, ai_audio_id) VALUES (:title, :description, :lyrics, :lyrics_status, :lyrics_note, :path, :image_url, :source, :user_id, :visibility, :cover_task_id, :ai_audio_id)');

    foreach ($tracks as $track) {
        $audioUrl = trim((string) $track['audio_url']);
        if ($audioUrl === '') {
            continue;
        }

        $existsStmt->execute([
            ':user_id' => (int) $originalSong['user_id'],
            ':path' => $audioUrl,
        ]);
        if ($existsStmt->fetchColumn()) {
            continue;
        }

        $lyrics = trim((string) (($originalSong['lyrics'] ?? '') !== '' ? $originalSong['lyrics'] : ($track['lyrics'] ?: '')));
        $baseTitle = trim((string) ($originalSong['title'] ?? 'STAR.AI 翻唱作品'));
        $title = $baseTitle !== '' ? $baseTitle . '（翻唱）' : trim((string) ($track['title'] ?: 'STAR.AI 翻唱作品'));
        $description = extractDisplayStyle($payload, $track, $originalSong);

        $insertStmt->execute([
            ':title' => $title,
            ':description' => $description !== '' ? $description : 'AI 翻唱',
            ':lyrics' => $lyrics,
            ':lyrics_status' => $lyrics !== '' ? 'pending' : 'none',
            ':lyrics_note' => $lyrics !== '' ? '翻唱结果已同步歌词，可继续编辑或生成 LRC' : '翻唱结果未返回可用歌词',
            ':path' => $audioUrl,
            ':image_url' => $track['image_url'],
            ':source' => 'ai',
            ':user_id' => (int) $originalSong['user_id'],
            ':visibility' => 'private',
            ':cover_task_id' => $coverTaskId,
            ':ai_audio_id' => $track['audio_id'],
        ]);
    }
}

echo json_encode([
    'ok' => true,
    'status' => $status,
    'cover_task_id' => $coverTaskId,
    'tracks' => $tracks,
    'raw' => $data,
], JSON_UNESCAPED_UNICODE);
