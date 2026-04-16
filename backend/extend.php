<?php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/config.php';
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
if ($songId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '缺少 song_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getPdo();
$stmt = $pdo->prepare('SELECT * FROM songs WHERE id = :id AND user_id = :user_id LIMIT 1');
$stmt->execute([':id' => $songId, ':user_id' => (int) $_SESSION['user_id']]);
$song = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$song) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => '歌曲不存在或无权限操作'], JSON_UNESCAPED_UNICODE);
    exit;
}

$audioId = trim((string) ($song['ai_audio_id'] ?? ''));
if ($audioId === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '这首歌还没有可用的 AI audioId，暂时无法发起延长'], JSON_UNESCAPED_UNICODE);
    exit;
}

$chargeResult = null;
try {
    $chargeResult = chargeUserCredits($pdo, (int) $_SESSION['user_id'], 'extend', '延长 Extend');
} catch (Throwable $e) {
    logMessage('延长积分检查/扣除失败: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '积分处理异常，请稍后重试'], JSON_UNESCAPED_UNICODE);
    exit;
}

$config = starAiApiConfig();
$apiKey = trim((string) ($config['suno_api_key'] ?? ''));
$apiBase = rtrim((string) ($config['suno_api_base'] ?? 'https://api.sunoapi.org'), '/');
$model = (string) ($config['suno_model'] ?? 'V4_5');
if ($apiKey === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Suno API Key 未配置'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = [
    'defaultParamFlag' => false,
    'audioId' => $audioId,
    'callBackUrl' => absoluteUrl('starwaves_project/backend/star_ai_callback.php'),
    'model' => $model,
];

$ch = curl_init($apiBase . '/api/v1/generate/extend');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 90,
]);
$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => '延长请求失败：' . $curlError], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($response, true);
if (!is_array($data)) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Suno Extend 返回了无法解析的数据', 'raw_text' => $response], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($httpCode >= 400 || ((int) ($data['code'] ?? 200) !== 200)) {
    http_response_code($httpCode >= 400 ? $httpCode : 502);
    echo json_encode([
        'ok' => false,
        'error' => $data['msg'] ?? $data['message'] ?? '延长请求失败',
        'raw' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$extendTaskId = trim((string) ($data['data']['taskId'] ?? ''));
$pdo->prepare('UPDATE songs SET extend_task_id = :extend_task_id, extend_status = :extend_status WHERE id = :id')
    ->execute([
        ':extend_task_id' => $extendTaskId,
        ':extend_status' => 'pending',
        ':id' => $songId,
    ]);

logMessage('STAR.AI Extend 任务提交成功: song_id=' . $songId . ', audio_id=' . $audioId . ', extend_task_id=' . $extendTaskId);

echo json_encode([
    'ok' => true,
    'song_id' => $songId,
    'audio_id' => $audioId,
    'extend_task_id' => $extendTaskId,
    'message' => '延长任务已提交到 Suno API。',
    'charged_credits' => (int) (($chargeResult['charged'] ?? 0)),
    'remaining_credits' => (int) (($chargeResult['after'] ?? 0)),
], JSON_UNESCAPED_UNICODE);
