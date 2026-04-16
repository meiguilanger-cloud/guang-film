<?php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$isGetRequest = $_SERVER['REQUEST_METHOD'] === 'GET';
if ($isGetRequest) {
    requireLoginPage('../star-ai.php');
} else {
    requireLoginJson();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '仅支持 GET / POST 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (json_decode(file_get_contents('php://input'), true) ?: [])
    : $_GET;
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

$uploadUrl = absoluteAudioUrl(resolveSongAudioUrl($song, 'frontend'));
if ($uploadUrl === '' || !filter_var($uploadUrl, FILTER_VALIDATE_URL)) {
    $error = '这首歌还没有可用的音频地址，暂时无法发起翻唱';
    if ($isGetRequest) {
        $redirect = trim((string) ($_GET['redirect'] ?? '../star-ai.php'));
        header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'cover_error=' . rawurlencode($error));
        exit;
    }
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

$chargeResult = null;

$config = starAiApiConfig();
$apiKey = trim((string) ($config['suno_api_key'] ?? ''));
$apiBase = rtrim((string) ($config['suno_api_base'] ?? 'https://api.sunoapi.org'), '/');
$model = (string) ($config['suno_model'] ?? 'V4_5');
if ($apiKey === '') {
    $error = 'Suno API Key 未配置';
    if ($isGetRequest) {
        $redirect = trim((string) ($_GET['redirect'] ?? '../star-ai.php'));
        header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'cover_error=' . rawurlencode($error));
        exit;
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

function cleanLyricsForCover(string $lyrics): string {
    $lyrics = str_replace(["\r\n", "\r", "\\n"], "\n", $lyrics);
    $lyrics = preg_replace('/\n+voice\s*:\s*.*$/i', '', $lyrics) ?? $lyrics;
    $lyrics = preg_replace('/\n+(style|genre|mood|tempo|instrumentation)\s*:\s*.*$/im', '', $lyrics) ?? $lyrics;
    $lyrics = preg_replace('/^\s*[^\n]{1,80}\n\s*(词曲|作词|作曲|词|曲)\s*[：:].*(\n\s*)+/u', '', $lyrics, 1) ?? $lyrics;
    $lyrics = preg_replace('/^\s*(词曲|作词|作曲|词|曲)\s*[：:].*(\n\s*)+/u', '', $lyrics, 1) ?? $lyrics;
    return trim($lyrics);
}

$style = trim((string) ($input['style'] ?? ''));
$prompt = trim((string) ($input['prompt'] ?? ''));
if ($style === '' && $prompt === '') {
    $error = '请先填写翻唱风格';
    if ($isGetRequest) {
        $redirect = trim((string) ($_GET['redirect'] ?? '../star-ai.php'));
        header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'cover_error=' . rawurlencode($error));
        exit;
    }
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $chargeResult = chargeUserCredits($pdo, (int) $_SESSION['user_id'], 'cover', '翻唱 Cover');
} catch (Throwable $e) {
    logMessage('翻唱积分检查/扣除失败: ' . $e->getMessage());
    if ($isGetRequest) {
        $redirect = trim((string) ($_GET['redirect'] ?? '../star-ai.php'));
        header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'cover_error=' . rawurlencode('积分处理异常，请稍后重试'));
        exit;
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '积分处理异常，请稍后重试'], JSON_UNESCAPED_UNICODE);
    exit;
}

$originalTitle = trim((string) ($song['title'] ?? ''));
$originalLyrics = cleanLyricsForCover((string) ($song['lyrics'] ?? ''));
$lyricsOverride = cleanLyricsForCover((string) ($input['lyrics_override'] ?? ''));
$effectiveLyrics = $lyricsOverride !== '' ? $lyricsOverride : $originalLyrics;
$styleText = $style !== '' ? $style : '保留原曲风格';
if (mb_strlen($styleText) > 900) {
    $styleText = mb_substr($styleText, 0, 900);
}

$promptParts = [
    '要求：保留原曲主旋律、段落结构与原标题，只改变演唱和编曲风格，不要改写成新歌。'
];
if ($prompt !== '') {
    $promptParts[] = '补充要求：' . $prompt;
}
if ($effectiveLyrics !== '') {
    $promptParts[] = $effectiveLyrics;
} else {
    $promptParts[] = '请严格贴合上传音频的人声旋律与主题，不要自由改写成新的歌词内容。';
}
$coverPrompt = trim(implode("\n\n", $promptParts));
if (mb_strlen($coverPrompt) > 4800) {
    $coverPrompt = mb_substr($coverPrompt, 0, 4800);
}

$payload = [
    'uploadUrl' => $uploadUrl,
    'customMode' => true,
    'instrumental' => false,
    'callBackUrl' => absoluteUrl('starwaves_project/backend/star_ai_callback.php'),
    'model' => $model,
    'style' => $styleText,
    'prompt' => $coverPrompt,
    'title' => $originalTitle !== '' ? $originalTitle : 'STAR.AI 翻唱',
];

$ch = curl_init($apiBase . '/api/v1/generate/upload-cover');
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
    $error = '翻唱请求失败：' . $curlError;
    if ($isGetRequest) {
        $redirect = trim((string) ($_GET['redirect'] ?? '../star-ai.php'));
        header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'cover_error=' . rawurlencode($error));
        exit;
    }
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($response, true);
if (!is_array($data)) {
    $error = 'Suno Cover 返回了无法解析的数据';
    if ($isGetRequest) {
        $redirect = trim((string) ($_GET['redirect'] ?? '../star-ai.php'));
        header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'cover_error=' . rawurlencode($error));
        exit;
    }
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $error, 'raw_text' => $response], JSON_UNESCAPED_UNICODE);
    exit;
}

$coverTaskId = trim((string) ($data['data']['taskId'] ?? ''));
$coverAlreadyExists = ((int) ($data['code'] ?? 200) === 400)
    && $coverTaskId !== ''
    && (
        str_contains(strtolower((string) ($data['msg'] ?? $data['message'] ?? '')), 'already been generated')
        || str_contains(strtolower((string) ($data['msg'] ?? $data['message'] ?? '')), 'already exists')
    );

if (($httpCode >= 400 || ((int) ($data['code'] ?? 200) !== 200)) && !$coverAlreadyExists) {
    $error = $data['msg'] ?? $data['message'] ?? '翻唱请求失败';
    if ($isGetRequest) {
        $redirect = trim((string) ($_GET['redirect'] ?? '../star-ai.php'));
        header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'cover_error=' . rawurlencode($error));
        exit;
    }
    http_response_code($httpCode >= 400 ? $httpCode : 502);
    echo json_encode([
        'ok' => false,
        'error' => $error,
        'raw' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$pdo->prepare('UPDATE songs SET cover_task_id = :cover_task_id, cover_status = :cover_status WHERE id = :id')
    ->execute([
        ':cover_task_id' => $coverTaskId,
        ':cover_status' => 'pending',
        ':id' => $songId,
    ]);

logMessage('STAR.AI Cover 任务提交成功: song_id=' . $songId . ', upload_url=' . $uploadUrl . ', cover_task_id=' . $coverTaskId . ', style=' . $style . ', lyrics_override=' . ($lyricsOverride !== '' ? 'yes' : 'no'));

if ($isGetRequest) {
    $redirect = trim((string) ($_GET['redirect'] ?? '../star-ai.php'));
    header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'cover_submitted=1&cover_task_id=' . rawurlencode($coverTaskId));
    exit;
}

echo json_encode([
    'ok' => true,
    'song_id' => $songId,
    'upload_url' => $uploadUrl,
    'cover_task_id' => $coverTaskId,
    'style' => $style,
    'prompt' => $prompt,
    'lyrics_override' => $lyricsOverride,
    'message' => '翻唱任务已提交到 Suno API。',
    'charged_credits' => (int) (($chargeResult['charged'] ?? 0)),
    'remaining_credits' => (int) (($chargeResult['after'] ?? 0)),
], JSON_UNESCAPED_UNICODE);
