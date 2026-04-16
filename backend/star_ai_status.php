<?php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

requireLoginJson();

$taskId = trim($_GET['task_id'] ?? $_GET['taskId'] ?? '');
if ($taskId === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '缺少 task_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$config = starAiApiConfig();
$apiKey = trim((string) ($config['suno_api_key'] ?? ''));
$apiBase = rtrim((string) ($config['suno_api_base'] ?? 'https://api.sunoapi.org'), '/');

$ch = curl_init($apiBase . '/api/v1/generate/record-info?taskId=' . rawurlencode($taskId));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT => 60,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => '查询任务失败：' . $curlError], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($response, true);
if (!is_array($data)) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => '状态接口返回了无法解析的数据', 'raw_text' => $response], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($httpCode >= 400 || ((int) ($data['code'] ?? 200) !== 200)) {
    http_response_code($httpCode >= 400 ? $httpCode : 502);
    echo json_encode([
        'ok' => false,
        'error' => $data['msg'] ?? $data['message'] ?? '状态查询失败',
        'raw' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$status = (string) ($data['data']['status'] ?? 'PENDING');
$sunoData = $data['data']['response']['sunoData'] ?? [];
$tracks = [];

if (is_array($sunoData)) {
    foreach ($sunoData as $track) {
        if (!is_array($track)) {
            continue;
        }
        $tracks[] = [
            'audio_url' => $track['audioUrl'] ?? $track['streamAudioUrl'] ?? '',
            'stream_audio_url' => $track['streamAudioUrl'] ?? '',
            'image_url' => $track['imageUrl'] ?? '',
            'title' => $track['title'] ?? '',
            'tags' => $track['tags'] ?? '',
            'lyrics' => $track['lyrics'] ?? $track['prompt'] ?? '',
            'prompt' => $track['prompt'] ?? '',
            'audio_id' => $track['audioId'] ?? $track['id'] ?? '',
        ];
    }
}

$completed = $status === 'SUCCESS' && count($tracks) >= 2;

if ($completed && isset($_SESSION['user_id'])) {
    $pdo = getPdo();
    $insertStmt = $pdo->prepare('INSERT INTO songs (title, description, lyrics, lyrics_status, lyrics_note, file_path, image_url, source, user_id, visibility, ai_task_id, ai_audio_id) VALUES (:title, :description, :lyrics, :lyrics_status, :lyrics_note, :path, :image_url, :source, :user_id, :visibility, :ai_task_id, :ai_audio_id)');
    $existsStmt = $pdo->prepare('SELECT id FROM songs WHERE user_id = :user_id AND file_path = :path LIMIT 1');

    foreach ($tracks as $track) {
        $audioUrl = trim((string) ($track['audio_url'] ?? $track['stream_audio_url'] ?? ''));
        if ($audioUrl === '') {
            continue;
        }

        $existsStmt->execute([
            ':user_id' => (int) $_SESSION['user_id'],
            ':path' => $audioUrl,
        ]);
        if ($existsStmt->fetchColumn()) {
            continue;
        }

        $lyrics = trim((string) ($track['lyrics'] ?? $track['prompt'] ?? ''));
        $insertStmt->execute([
            ':title' => trim((string) ($track['title'] ?? 'STAR.AI 作品')) ?: 'STAR.AI 作品',
            ':description' => trim((string) ($track['tags'] ?? 'STAR.AI 生成歌曲')) ?: 'STAR.AI 生成歌曲',
            ':lyrics' => $lyrics,
            ':lyrics_status' => $lyrics !== '' ? 'pending' : 'none',
            ':lyrics_note' => $lyrics !== '' ? 'AI 生成结果已同步歌词，可继续编辑或生成 LRC' : '当前未返回可用歌词',
            ':path' => $audioUrl,
            ':image_url' => trim((string) ($track['image_url'] ?? '')),
            ':source' => 'ai',
            ':user_id' => (int) $_SESSION['user_id'],
            ':visibility' => 'private',
            ':ai_task_id' => $taskId,
            ':ai_audio_id' => trim((string) ($track['audio_id'] ?? '')),
        ]);
    }
}

$result = null;
if (!empty($tracks)) {
    $result = $tracks[0];
    $result['message'] = $completed ? '歌曲已生成完成，并已同步到后台' : '任务处理中';
}

echo json_encode([
    'ok' => true,
    'task_id' => $taskId,
    'status' => $completed ? 'completed' : strtolower($status),
    'upstream_status' => $status,
    'result' => $result,
    'tracks' => $tracks,
    'raw' => $data,
], JSON_UNESCAPED_UNICODE);
