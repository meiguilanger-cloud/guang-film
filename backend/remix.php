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

$uploadUrl = absoluteAudioUrl(resolveSongAudioUrl($song, 'frontend'));
if ($uploadUrl === '' || !filter_var($uploadUrl, FILTER_VALIDATE_URL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '这首歌还没有可用的音频地址，暂时无法发起重新混音'], JSON_UNESCAPED_UNICODE);
    exit;
}

$chargeResult = null;
try {
    $chargeResult = chargeUserCredits($pdo, (int) $_SESSION['user_id'], 'remaster', '重新混音 Remaster');
} catch (Throwable $e) {
    logMessage('重新混音积分检查/扣除失败: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '积分处理异常，请稍后重试'], JSON_UNESCAPED_UNICODE);
    exit;
}

$config = starAiApiConfig();
$apiKey = trim((string) ($config['suno_api_key'] ?? ''));
$apiBase = rtrim((string) ($config['suno_api_base'] ?? 'https://api.sunoapi.org'), '/');
$defaultModel = 'V4_5';
$model = strtoupper(trim((string) ($input['model'] ?? $defaultModel)));
$allowedModels = ['V4_5', 'V4_5PLUS'];
if (!in_array($model, $allowedModels, true)) {
    $model = $defaultModel;
}
$variationStrength = strtolower(trim((string) ($input['variation_strength'] ?? 'normal')));
$variationHints = [
    'subtle' => '尽量贴近原曲，只做轻微刷新。',
    'normal' => '保持原曲核心听感，在质感和层次上做明显提升。',
    'high' => '允许在保留主旋律和段落结构的前提下，让风格刷新感更强。',
];
if (!isset($variationHints[$variationStrength])) {
    $variationStrength = 'normal';
}
if ($apiKey === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Suno API Key 未配置'], JSON_UNESCAPED_UNICODE);
    exit;
}

function cleanLyricsForRemix(string $lyrics): string {
    $lyrics = str_replace(["\r\n", "\r", "\\n"], "\n", $lyrics);
    $lyrics = preg_replace('/\n+voice\s*:\s*.*$/i', '', $lyrics) ?? $lyrics;
    $lyrics = preg_replace('/\n+(style|genre|mood|tempo|instrumentation)\s*:\s*.*$/im', '', $lyrics) ?? $lyrics;
    return trim($lyrics);
}

function sunoApiPostJson(string $url, string $apiKey, array $payload): array {
    $ch = curl_init($url);
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

    return [$response, $curlError, $httpCode];
}

$originalTitle = trim((string) ($song['title'] ?? 'STAR.AI 重新混音'));
$originalLyrics = cleanLyricsForRemix((string) ($song['lyrics'] ?? ''));
$styleSeedParts = [];
if (!empty($song['style'])) {
    $styleSeedParts[] = trim((string) $song['style']);
}
if (!empty($song['genre'])) {
    $styleSeedParts[] = trim((string) $song['genre']);
}
if (!empty($song['prompt'])) {
    $styleSeedParts[] = trim((string) $song['prompt']);
}
$styleSeed = trim(implode(', ', array_filter($styleSeedParts)));
if ($styleSeed === '') {
    $styleSeed = 'Original song profile: keep the existing genre identity, vocal character, and arrangement center.';
}

$remasterStyleTemplate = <<<TEXT
This is a highly conservative remaster of an existing song.

Keep the original song length unchanged.
Keep the original pitch unchanged.
Keep the original musical style unchanged.
Keep the original melody, song structure, chord progression, lyrics, vocal phrasing, emotional tone, and section order unchanged.
Do not extend, shorten, speed up, slow down, retime, or otherwise alter the duration, pacing, groove, or rhythmic placement of the song.
Do not change the key, pitch center, melodic register, perceived vocal pitch, or harmonic identity.
Do not rewrite, rearrange, reinterpret, or newly compose the song.
Do not significantly change the singer's vocal character, performance style, or the core instrumental arrangement.
Do not introduce obvious new hooks, sections, melodies, harmonies, ad-libs, transitions, or production ideas.
Do not dramatically shift genre, tempo feel, instrumentation balance, or musical identity.

Only improve the overall sonic quality:
enhance clarity, tonal balance, punch, warmth, depth, stereo imaging, instrument separation, vocal presence, low-end control, high-frequency detail, and overall polish.

Keep it extremely subtle and faithful. Prioritize polish over change.
The final result should sound like the same song professionally remastered, not a new version, remix, or cover.
TEXT;

$boostContent = trim($remasterStyleTemplate . "\n\n" . $styleSeed . "\n" . $variationHints[$variationStrength]);
if (mb_strlen($boostContent) > 900) {
    $boostContent = mb_substr($boostContent, 0, 900);
}

[$boostResponse, $boostCurlError, $boostHttpCode] = sunoApiPostJson(
    $apiBase . '/api/v1/style/generate',
    $apiKey,
    ['content' => $boostContent]
);
if ($boostResponse === false) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => '风格增强请求失败：' . $boostCurlError], JSON_UNESCAPED_UNICODE);
    exit;
}

$boostData = json_decode($boostResponse, true);
if (!is_array($boostData)) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Boost Music Style 返回了无法解析的数据', 'raw_text' => $boostResponse], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($boostHttpCode >= 400 || ((int) ($boostData['code'] ?? 200) !== 200)) {
    http_response_code($boostHttpCode >= 400 ? $boostHttpCode : 502);
    echo json_encode([
        'ok' => false,
        'error' => $boostData['msg'] ?? $boostData['message'] ?? '风格增强失败',
        'raw' => $boostData,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$boostedStyle = trim((string) ($boostData['data']['result'] ?? ''));
if ($boostedStyle === '') {
    $boostedStyle = $boostContent;
}
if (mb_strlen($boostedStyle) > 900) {
    $boostedStyle = mb_substr($boostedStyle, 0, 900);
}

$promptParts = [];
if ($originalLyrics !== '') {
    $promptParts[] = $originalLyrics;
}
$coverPrompt = trim(implode("\n\n", $promptParts));
if (mb_strlen($coverPrompt) > 4800) {
    $coverPrompt = mb_substr($coverPrompt, 0, 4800);
}

$coverPayload = [
    'uploadUrl' => $uploadUrl,
    'customMode' => true,
    'instrumental' => false,
    'callBackUrl' => absoluteUrl('starwaves_project/backend/star_ai_callback.php'),
    'model' => $model,
    'style' => $boostedStyle,
    'prompt' => $coverPrompt,
    'title' => $originalTitle,
];

[$coverResponse, $coverCurlError, $coverHttpCode] = sunoApiPostJson(
    $apiBase . '/api/v1/generate/upload-cover',
    $apiKey,
    $coverPayload
);
if ($coverResponse === false) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => '重新混音提交失败：' . $coverCurlError], JSON_UNESCAPED_UNICODE);
    exit;
}

$coverData = json_decode($coverResponse, true);
if (!is_array($coverData)) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => '重新混音返回了无法解析的数据', 'raw_text' => $coverResponse], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($coverHttpCode >= 400 || ((int) ($coverData['code'] ?? 200) !== 200)) {
    http_response_code($coverHttpCode >= 400 ? $coverHttpCode : 502);
    echo json_encode([
        'ok' => false,
        'error' => $coverData['msg'] ?? $coverData['message'] ?? '重新混音请求失败',
        'raw' => $coverData,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$remixTaskId = trim((string) ($coverData['data']['taskId'] ?? ''));
$pdo->prepare('UPDATE songs SET remix_task_id = :remix_task_id, remix_status = :remix_status WHERE id = :id')
    ->execute([
        ':remix_task_id' => $remixTaskId,
        ':remix_status' => 'pending',
        ':id' => $songId,
    ]);

logMessage('STAR.AI Remaster 任务提交成功: song_id=' . $songId . ', upload_url=' . $uploadUrl . ', model=' . $model . ', variation=' . $variationStrength . ', remix_task_id=' . $remixTaskId . ', boosted_style=' . $boostedStyle);

echo json_encode([
    'ok' => true,
    'song_id' => $songId,
    'upload_url' => $uploadUrl,
    'remix_task_id' => $remixTaskId,
    'model' => $model,
    'variation_strength' => $variationStrength,
    'boosted_style' => $boostedStyle,
    'message' => '重新混音任务已按 Boost Music Style + Cover 链路提交到 Suno API。',
    'charged_credits' => (int) (($chargeResult['charged'] ?? 0)),
    'remaining_credits' => (int) (($chargeResult['after'] ?? 0)),
], JSON_UNESCAPED_UNICODE);
