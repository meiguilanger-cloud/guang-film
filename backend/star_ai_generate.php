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

$config = starAiApiConfig();
$userId = $_SESSION['user_id'] ?? null;
if ($userId === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => '未登录用户'], JSON_UNESCAPED_UNICODE);
    exit;
}

$chargeResult = null;
try {
    $pdo = getPdo();
    $chargeResult = chargeUserCredits($pdo, (int) $userId, 'star_ai_generate', 'STAR.AI 生成 / 编曲');
} catch (Throwable $e) {
    logMessage('积分检查/扣除失败: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '积分处理异常，请稍后重试'], JSON_UNESCAPED_UNICODE);
    exit;
}

$apiKey = trim((string) ($config['suno_api_key'] ?? ''));
$apiBase = rtrim((string) ($config['suno_api_base'] ?? 'https://api.sunoapi.org'), '/');
$model = (string) ($config['suno_model'] ?? 'V4_5');

$creationMode = trim($_POST['creation_mode'] ?? 'simple');
$prompt = trim($_POST['prompt'] ?? '');
$title = trim($_POST['title'] ?? '');
$lyrics = trim($_POST['lyrics'] ?? '');
$language = trim($_POST['language'] ?? 'zh');
$mode = trim($_POST['mode'] ?? 'vocal');
$genre = trim($_POST['genre'] ?? '');
$mood = trim($_POST['mood'] ?? '');
$voiceGender = trim($_POST['voice_gender'] ?? 'auto');
$referenceStrength = max(0, min(100, (int) ($_POST['reference_strength'] ?? 0)));
$hasReferenceAudio = !empty($_FILES['reference_audio']['name'] ?? '');

if ($prompt === '' && $lyrics === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '请输入做歌提示词'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($apiKey === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Suno API Key 未配置'], JSON_UNESCAPED_UNICODE);
    exit;
}

$styleParts = array_filter([$genre, $mood, $language !== 'auto' ? $language : '', $voiceGender !== 'auto' ? $voiceGender . ' vocal' : '']);
$style = trim(implode(' / ', $styleParts));
$title = $title !== '' ? $title : ('STAR.AI ' . date('mdHis'));
$instrumental = $mode === 'instrumental';

if ($creationMode === 'pro') {
    $customMode = true;
    $finalPrompt = $instrumental ? '' : $lyrics;
    if ($finalPrompt === '') {
        $finalPrompt = $prompt;
    }
} else {
    $customMode = false;
    $idea = trim($prompt . ' ' . $genre . ' ' . $mood);
    $finalPrompt = $idea !== '' ? $idea : $lyrics;
}

if ($voiceGender !== 'auto' && $finalPrompt !== '') {
    $finalPrompt .= '\nVoice: ' . ($voiceGender === 'male' ? 'male vocal' : 'female vocal');
}
if ($hasReferenceAudio && $finalPrompt !== '') {
    $finalPrompt .= '\nReference strength: ' . $referenceStrength . '%';
}

$payload = [
    'prompt' => trim($finalPrompt),
    'style' => $customMode ? ($style !== '' ? $style : 'Pop') : '',
    'title' => $customMode ? $title : '',
    'customMode' => $customMode,
    'instrumental' => $instrumental,
    'model' => $model,
    'callBackUrl' => absoluteUrl('starwaves_project/backend/star_ai_callback.php'),
];

$ch = curl_init($apiBase . '/api/v1/generate');
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
    echo json_encode(['ok' => false, 'error' => 'STAR.AI 请求失败：' . $curlError], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($response, true);
if (!is_array($data)) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Suno API 返回了无法解析的数据', 'raw_text' => $response], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($httpCode >= 400 || ((int) ($data['code'] ?? 200) !== 200)) {
    http_response_code($httpCode >= 400 ? $httpCode : 502);
    echo json_encode([
        'ok' => false,
        'error' => $data['msg'] ?? $data['message'] ?? $data['error'] ?? 'STAR.AI 上游接口返回错误',
        'raw' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$taskId = $data['data']['taskId'] ?? $data['data']['task_id'] ?? $data['taskId'] ?? $data['task_id'] ?? ('task-' . date('YmdHis'));
logMessage('STAR.AI 任务提交成功: ' . $taskId . ', mode=' . $creationMode . ', prompt=' . mb_substr($prompt !== '' ? $prompt : $lyrics, 0, 120));

echo json_encode([
    'ok' => true,
    'message' => '任务已提交到 Suno API，正在生成 2 首候选歌曲。',
    'task_id' => $taskId,
    'raw' => $data,
    'remaining_credits' => (int) (($chargeResult['after'] ?? 0)),
    'charged_credits' => (int) (($chargeResult['charged'] ?? 0)),
], JSON_UNESCAPED_UNICODE);
