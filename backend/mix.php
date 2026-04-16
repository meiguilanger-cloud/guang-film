<?php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
requireLoginJson();

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE || empty($data['song_id'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request'], JSON_UNESCAPED_UNICODE);
    exit;
}

$songId = (int) $data['song_id'];
$mixMode = trim((string) ($data['mix_mode'] ?? 'software'));
if (!in_array($mixMode, ['software', 'hardware'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '混音模式无效'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getPdo();
$chargeKey = $mixMode === 'hardware' ? 'mix_hardware' : 'mix_software';
$chargeLabel = $mixMode === 'hardware' ? '硬件混音' : '软件混音';

try {
    $chargeResult = chargeUserCredits($pdo, (int) $_SESSION['user_id'], $chargeKey, $chargeLabel);
} catch (Throwable $e) {
    logMessage('混音积分检查/扣除失败: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '积分处理异常，请稍后重试'], JSON_UNESCAPED_UNICODE);
    exit;
}

logMessage('Mix requested for song_id=' . $songId . ', mode=' . $mixMode);

echo json_encode([
    'ok' => true,
    'msg' => 'Mix placeholder',
    'song_id' => $songId,
    'mix_mode' => $mixMode,
    'charged_credits' => (int) (($chargeResult['charged'] ?? 0)),
    'remaining_credits' => (int) (($chargeResult['after'] ?? 0)),
], JSON_UNESCAPED_UNICODE);
?>