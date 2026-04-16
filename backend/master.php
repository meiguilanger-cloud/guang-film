<?php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

function masterJson(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensureMasteringJobsTable(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mastering_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            song_id INTEGER NOT NULL,
            mastering_type TEXT NOT NULL DEFAULT 'software',
            style TEXT DEFAULT 'balanced',
            target_lufs REAL DEFAULT -9,
            status TEXT NOT NULL DEFAULT 'queued',
            input_file TEXT,
            output_file TEXT,
            preview_file TEXT,
            notes TEXT,
            error_message TEXT,
            provider_job_id TEXT,
            provider_name TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at TEXT,
            completed_at TEXT
        )"
    );
}

function masteringApiBase(): string {
    return rtrim(trim((string) getenv('MASTERING_API_BASE')), '/');
}

function masteringApiToken(): string {
    return trim((string) getenv('MASTERING_API_TOKEN'));
}

function masteringCallbackToken(): string {
    return trim((string) getenv('MASTERING_CALLBACK_TOKEN'));
}

function masteringCallbackUrl(): string {
    $configured = trim((string) getenv('MASTERING_CALLBACK_URL'));
    if ($configured !== '') {
        return $configured;
    }
    return absoluteUrl('backend/master_callback.php');
}

function canDispatchToExternalMastering(): bool {
    return masteringApiBase() !== '' && masteringApiToken() !== '' && masteringCallbackToken() !== '';
}

function dispatchExternalMasteringJob(array $song, int $jobId, int $userId, string $style, float $targetLufs): array {
    $endpoint = masteringApiBase() . '/api/mastering/jobs';
    $payload = [
        'job_id' => (string) $jobId,
        'song_id' => (int) ($song['id'] ?? 0),
        'user_id' => $userId,
        'title' => (string) ($song['title'] ?? 'song'),
        'source_url' => absoluteAudioUrl(resolveSongAudioUrl($song, 'absolute')),
        'source_format' => strtolower(pathinfo((string) ($song['file_path'] ?? ''), PATHINFO_EXTENSION)) ?: 'mp3',
        'callback_url' => masteringCallbackUrl(),
        'callback_token' => masteringCallbackToken(),
        'want_preview' => true,
        'want_master_wav' => true,
        'want_master_mp3' => true,
        'style' => $style,
        'target_lufs' => $targetLufs,
        'notes' => 'software mastering request from starwaves',
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . masteringApiToken(),
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('external mastering dispatch failed: ' . $error);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $decoded = json_decode((string) $body, true);
    if ($status >= 400) {
        throw new RuntimeException('external mastering returned HTTP ' . $status);
    }
    if (!is_array($decoded) || empty($decoded['ok']) || empty($decoded['accepted'])) {
        throw new RuntimeException('external mastering did not accept the job');
    }

    return [
        'provider_job_id' => (string) ($decoded['provider_job_id'] ?? ''),
        'provider_name' => 'external_mastering_api',
        'status' => (string) ($decoded['status'] ?? 'queued'),
        'raw' => $decoded,
    ];
}

function readJsonInput(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function masteringSlug(string $name): string {
    $name = pathinfo($name, PATHINFO_FILENAME);
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? 'song';
    $name = trim($name, '-._');
    return $name !== '' ? $name : 'song';
}

function ensureMasteringStorage(): string {
    $dir = dirname(__DIR__) . '/storage/mastering_inbox';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function ensureMasteringOutputStorage(): string {
    $dir = dirname(__DIR__) . '/storage/mastering_output';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function relativeMasteringPath(string $absolutePath): string {
    $root = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    $absolutePath = str_replace('\\', '/', $absolutePath);
    if (strpos($absolutePath, $root . '/') === 0) {
        return ltrim(substr($absolutePath, strlen($root)), '/');
    }
    return $absolutePath;
}

function runAutomatedMastering(string $inputPath, string $songTitle): array {
    $outputDir = ensureMasteringOutputStorage();
    $jobToken = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $baseName = masteringSlug($songTitle) . '-' . $jobToken;
    $wavOutput = $outputDir . '/' . $baseName . '.wav';
    $mp3Output = $outputDir . '/' . $baseName . '.mp3';
    $script = __DIR__ . '/scripts/master_audio.py';
    $command = sprintf(
        'python3 %s %s %s %s 2>&1',
        escapeshellarg($script),
        escapeshellarg($inputPath),
        escapeshellarg($wavOutput),
        escapeshellarg($mp3Output)
    );
    $output = shell_exec($command);
    $decoded = json_decode((string) $output, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        throw new RuntimeException(is_array($decoded) && !empty($decoded['error']) ? (string) $decoded['error'] : '自动母带处理失败');
    }
    if (!is_file($wavOutput) || !is_file($mp3Output)) {
        throw new RuntimeException('自动母带输出文件未生成');
    }
    return [
        'engine' => (string) ($decoded['engine'] ?? 'ffmpeg-loudnorm'),
        'output_file' => relativeMasteringPath($wavOutput),
        'preview_file' => relativeMasteringPath($mp3Output),
        'analysis_before' => $decoded['analysis_before'] ?? null,
        'analysis_target' => $decoded['analysis_target'] ?? null,
        'analysis_after' => $decoded['analysis_after'] ?? null,
        'analysis_delta' => $decoded['analysis_delta'] ?? null,
    ];
}

function downloadSongToLocal(string $source, string $songTitle): array {
    if ($source === '') {
        throw new RuntimeException('歌曲文件地址为空。');
    }

    $inboxDir = ensureMasteringStorage();
    $extension = strtolower(pathinfo(parse_url($source, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION)) ?: 'mp3';
    $jobToken = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $filename = masteringSlug($songTitle) . '-' . $jobToken . '.' . $extension;
    $targetPath = $inboxDir . '/' . $filename;

    $context = stream_context_create([
        'http' => [
            'timeout' => 60,
            'follow_location' => 1,
            'user_agent' => 'StarwavesMaster/1.0',
        ],
        'https' => [
            'timeout' => 60,
            'follow_location' => 1,
            'user_agent' => 'StarwavesMaster/1.0',
        ],
    ]);

    $data = @file_get_contents($source, false, $context);
    if ($data === false) {
        throw new RuntimeException('远程音频下载失败，请稍后再试。');
    }
    if (file_put_contents($targetPath, $data) === false) {
        throw new RuntimeException('远程音频已拿到，但写入服务器本地失败。');
    }

    return [
        'path' => $targetPath,
        'name' => $filename,
    ];
}

$action = $_GET['action'] ?? 'create';
$pdo = getPdo();
ensureMasteringJobsTable($pdo);

function findActiveMasteringJob(PDO $pdo, int $userId, int $songId, string $masteringType): ?array {
    $stmt = $pdo->prepare(
        'SELECT mj.*, s.title AS song_title
         FROM mastering_jobs mj
         LEFT JOIN songs s ON s.id = mj.song_id
         WHERE mj.user_id = :user_id
           AND mj.song_id = :song_id
           AND mj.mastering_type = :mastering_type
           AND mj.status IN (\'queued\', \'processing\')
         ORDER BY mj.created_at DESC
         LIMIT 1'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':song_id' => $songId,
        ':mastering_type' => $masteringType,
    ]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    return $job ?: null;
}

if ($action === 'list') {
    if (empty($_SESSION['user_id'])) {
        masterJson(200, ['ok' => false, 'code' => 'LOGIN_REQUIRED', 'error' => '请先登录后再查看任务']);
    }

    $stmt = $pdo->prepare(
        'SELECT mj.id, mj.song_id, mj.mastering_type, mj.status, mj.style, mj.target_lufs, mj.created_at, mj.updated_at,
                mj.output_file, mj.preview_file, mj.notes, mj.error_message, mj.provider_job_id, mj.provider_name,
                mj.analysis_before_json, mj.analysis_target_json, mj.analysis_after_json,
                s.title AS song_title
         FROM mastering_jobs mj
         LEFT JOIN songs s ON s.id = mj.song_id
         WHERE mj.user_id = :user_id
           AND mj.mastering_type = \'software\'
         ORDER BY mj.created_at DESC
         LIMIT 12'
    );
    $stmt->execute([':user_id' => (int) $_SESSION['user_id']]);
    masterJson(200, ['ok' => true, 'jobs' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === 'status') {
    if (empty($_SESSION['user_id'])) {
        masterJson(200, ['ok' => false, 'code' => 'LOGIN_REQUIRED', 'error' => '请先登录后再查看任务']);
    }
    $jobId = (int) ($_GET['id'] ?? 0);
    if ($jobId <= 0) {
        masterJson(422, ['ok' => false, 'error' => '缺少任务 id']);
    }
    $stmt = $pdo->prepare(
        'SELECT mj.*, s.title AS song_title
         FROM mastering_jobs mj
         LEFT JOIN songs s ON s.id = mj.song_id
         WHERE mj.id = :id AND mj.user_id = :user_id
         LIMIT 1'
    );
    $stmt->execute([':id' => $jobId, ':user_id' => (int) $_SESSION['user_id']]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        masterJson(404, ['ok' => false, 'error' => '任务不存在']);
    }
    masterJson(200, ['ok' => true, 'job' => $job]);
}

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    masterJson(405, ['ok' => false, 'error' => '仅支持 POST 请求']);
}

if (empty($_SESSION['user_id'])) {
    masterJson(200, ['ok' => false, 'code' => 'LOGIN_REQUIRED', 'error' => '请先登录后再提交真实母带任务']);
}

$input = readJsonInput();
$songId = (int) ($input['song_id'] ?? 0);
$masteringType = 'software';
$style = trim((string) ($input['style'] ?? 'balanced')) ?: 'balanced';
$targetLufs = (float) ($input['target_lufs'] ?? -9.0);

if ($songId <= 0) {
    masterJson(422, ['ok' => false, 'error' => '缺少 song_id']);
}

$songStmt = $pdo->prepare('SELECT * FROM songs WHERE id = :id AND user_id = :user_id LIMIT 1');
$songStmt->execute([':id' => $songId, ':user_id' => (int) $_SESSION['user_id']]);
$song = $songStmt->fetch(PDO::FETCH_ASSOC);
if (!$song) {
    masterJson(404, ['ok' => false, 'error' => '歌曲不存在或无权限操作']);
}

$existingActiveJob = findActiveMasteringJob($pdo, (int) $_SESSION['user_id'], $songId, $masteringType);
if ($existingActiveJob) {
    masterJson(200, [
        'ok' => true,
        'duplicate' => true,
        'job' => [
            'id' => (int) $existingActiveJob['id'],
            'song_id' => (int) $existingActiveJob['song_id'],
            'song_title' => $existingActiveJob['song_title'] ?: $song['title'],
            'mastering_type' => $existingActiveJob['mastering_type'],
            'style' => $existingActiveJob['style'] ?: 'balanced',
            'target_lufs' => (float) $existingActiveJob['target_lufs'],
            'status' => $existingActiveJob['status'],
            'notes' => $existingActiveJob['notes'] ?: '这首歌已有进行中的同类型母带任务。',
            'input_file' => (string) ($existingActiveJob['input_file'] ?? ''),
            'output_file' => (string) ($existingActiveJob['output_file'] ?? ''),
            'preview_file' => (string) ($existingActiveJob['preview_file'] ?? ''),
            'analysis_before_json' => $existingActiveJob['analysis_before_json'] ?? null,
            'analysis_target_json' => $existingActiveJob['analysis_target_json'] ?? null,
            'analysis_after_json' => $existingActiveJob['analysis_after_json'] ?? null,
            'error_message' => $existingActiveJob['error_message'] ?? null,
        ],
    ]);
}

$chargeResult = null;
try {
    $chargeResult = chargeUserCredits($pdo, (int) $_SESSION['user_id'], 'master_software_1', '软件母带1');
} catch (Throwable $e) {
    logMessage('软件母带积分检查/扣除失败: ' . $e->getMessage());
    masterJson(500, ['ok' => false, 'error' => '积分处理异常，请稍后重试']);
}

$status = 'queued';
$sourceAudioUrl = absoluteAudioUrl(resolveSongAudioUrl($song, 'absolute'));
$inputFile = $sourceAudioUrl;
$errorMessage = null;
$previewFile = $sourceAudioUrl;
$outputFile = null;
$engineName = null;
$providerJobId = null;
$providerName = null;
$dispatchMode = 'local_worker';
$notes = '软件母带任务已创建，已进入后台自动母带队列。';

$insert = $pdo->prepare(
    'INSERT INTO mastering_jobs (user_id, song_id, mastering_type, style, target_lufs, status, input_file, output_file, preview_file, notes, error_message, provider_job_id, provider_name, created_at, updated_at, completed_at)
     VALUES (:user_id, :song_id, :mastering_type, :style, :target_lufs, :status, :input_file, :output_file, :preview_file, :notes, :error_message, :provider_job_id, :provider_name, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, :completed_at)'
);
$insert->execute([
    ':user_id' => (int) $_SESSION['user_id'],
    ':song_id' => $songId,
    ':mastering_type' => $masteringType,
    ':style' => $style,
    ':target_lufs' => $targetLufs,
    ':status' => $status,
    ':input_file' => $inputFile,
    ':output_file' => $outputFile,
    ':preview_file' => $previewFile,
    ':notes' => $notes,
    ':error_message' => $errorMessage,
    ':provider_job_id' => null,
    ':provider_name' => null,
    ':completed_at' => null,
]);

$jobId = (int) $pdo->lastInsertId();

if (canDispatchToExternalMastering()) {
    try {
        $dispatch = dispatchExternalMasteringJob($song, $jobId, (int) $_SESSION['user_id'], $style, $targetLufs);
        $providerJobId = $dispatch['provider_job_id'] !== '' ? $dispatch['provider_job_id'] : (string) $jobId;
        $providerName = $dispatch['provider_name'];
        $dispatchMode = 'external_api';
        $status = $dispatch['status'] !== '' ? $dispatch['status'] : 'queued';
        $notes = '软件母带任务已发送到外部母带服务，等待回调结果。';
        $pdo->prepare('UPDATE mastering_jobs SET status = :status, notes = :notes, provider_job_id = :provider_job_id, provider_name = :provider_name, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute([
                ':status' => $status,
                ':notes' => $notes,
                ':provider_job_id' => $providerJobId,
                ':provider_name' => $providerName,
                ':id' => $jobId,
            ]);
        $pdo->prepare('UPDATE songs SET mastering_status = :status, mastering_job_id = :mastering_job_id WHERE id = :song_id')
            ->execute([
                ':status' => 'queued',
                ':mastering_job_id' => $jobId,
                ':song_id' => $songId,
            ]);
        logMessage('Mastering job dispatched externally: job_id=' . $jobId . ', provider_job_id=' . $providerJobId . ', song_id=' . $songId);
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
        $notes = '外部软件母带服务暂时不可用，已自动切回本地母带 worker 兜底。';
        logMessage('External mastering dispatch failed, fallback to local worker: job_id=' . $jobId . ', error=' . $errorMessage);
    }
}

if ($dispatchMode === 'local_worker') {
    $workerCommand = sprintf(
        'php %s %d > /dev/null 2>&1 &',
        escapeshellarg(__DIR__ . '/master_worker.php'),
        $jobId
    );
    @exec($workerCommand);
    $pdo->prepare('UPDATE songs SET mastering_status = :status, mastering_job_id = :mastering_job_id WHERE id = :song_id')
        ->execute([
            ':status' => 'queued',
            ':mastering_job_id' => $jobId,
            ':song_id' => $songId,
        ]);
}

logMessage('Mastering job created: job_id=' . $jobId . ', song_id=' . $songId . ', type=' . $masteringType . ', user_id=' . (int) $_SESSION['user_id'] . ', status=' . $status . ', dispatch=' . $dispatchMode);

masterJson(200, [
    'ok' => true,
    'job' => [
        'id' => $jobId,
        'song_id' => $songId,
        'song_title' => $song['title'],
        'mastering_type' => $masteringType,
        'style' => $style,
        'target_lufs' => $targetLufs,
        'status' => $status,
        'notes' => $notes,
        'input_file' => $inputFile,
        'output_file' => $outputFile,
        'preview_file' => $previewFile,
        'engine' => $engineName,
        'provider_job_id' => $providerJobId,
        'provider_name' => $providerName,
        'dispatch_mode' => $dispatchMode,
        'analysis_before_json' => null,
        'analysis_target_json' => null,
        'analysis_after_json' => null,
        'error_message' => $errorMessage,
        'charged_credits' => (int) (($chargeResult['charged'] ?? 0)),
        'remaining_credits' => (int) (($chargeResult['after'] ?? 0)),
    ],
]);
?>

