<?php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$extendTaskId = trim((string) ($_GET['extend_task_id'] ?? $_POST['extend_task_id'] ?? ''));
if ($extendTaskId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'extend_task_id missing'], JSON_UNESCAPED_UNICODE);
    exit;
}

$config = starAiApiConfig();
$apiKey = trim((string) ($config['suno_api_key'] ?? ''));
$apiBase = rtrim((string) ($config['suno_api_base'] ?? 'https://api.sunoapi.org'), '/');

$ch = curl_init($apiBase . '/api/v1/generate/record-info?taskId=' . rawurlencode($extendTaskId));
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

$status = 'pending';
if (is_string($rawStatus) && $rawStatus !== '') {
    $normalized = strtolower(trim($rawStatus));
    $map = [
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
    $status = $map[$normalized] ?? 'pending';
} elseif (is_numeric($rawStatus)) {
    $numMap = [0 => 'pending', 1 => 'success', 2 => 'generating', 3 => 'failed'];
    $status = $numMap[(int) $rawStatus] ?? 'pending';
}

// Extract tracks (similar to cover_status)
$tracks = [];
if (is_array($rawTracks)) {
    foreach ($rawTracks as $i => $track) {
        if (!is_array($track)) continue;
        $audioUrl = trim((string) ($track['audio_url'] ?? $track['audioUrl'] ?? $track['stream_audio_url'] ?? $track['streamAudioUrl'] ?? ''));
        if ($audioUrl === '') continue;
        $tracks[] = [
            'slot_index' => (int) $i,
            'audio_url' => $audioUrl,
            'stream_audio_url' => trim((string) ($track['stream_audio_url'] ?? $track['streamAudioUrl'] ?? '')),
            'image_url' => trim((string) ($track['image_url'] ?? $track['imageUrl'] ?? '')),
            'title' => trim((string) ($track['title'] ?? 'STAR.AI 延长作品')),
            'tags' => trim((string) ($track['tags'] ?? 'AI 延长')),
            'lyrics' => trim((string) ($track['lyrics'] ?? $track['prompt'] ?? '')),
            'prompt' => trim((string) ($track['prompt'] ?? '')),
            'audio_id' => trim((string) ($track['audio_id'] ?? $track['audioId'] ?? $track['id'] ?? '')),
        ];
    }
}

$pdo = getPdo();
$updateStmt = $pdo->prepare('UPDATE songs SET extend_status = :extend_status WHERE extend_task_id = :task_id');
$updateStmt->execute([':extend_status' => $status, ':task_id' => $extendTaskId]);

echo json_encode([
    'ok' => true,
    'status' => $status,
    'extend_task_id' => $extendTaskId,
    'tracks' => $tracks,
    'raw' => $data,
], JSON_UNESCAPED_UNICODE);
?>