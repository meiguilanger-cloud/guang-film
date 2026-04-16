<?php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

function masterCallbackJson(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensureMasteringJobsTableCallback(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mastering_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            song_id INTEGER NOT NULL,
            mastering_type TEXT NOT NULL DEFAULT 'software',
            style TEXT DEFAULT 'balanced',
            target_lufs REAL DEFAULT -10,
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
            completed_at TEXT,
            analysis_before_json TEXT,
            analysis_target_json TEXT,
            analysis_after_json TEXT
        )"
    );
}

function readMasterCallbackInput(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function expectedMasteringCallbackToken(): string {
    return trim((string) getenv('MASTERING_CALLBACK_TOKEN'));
}

function receivedBearerToken(): string {
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        return trim((string) $matches[1]);
    }
    return trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
}

function callbackArchiveDir(string $kind): string {
    return $kind === 'preview'
        ? '/工作/starwaves/starwaves master music'
        : '/工作/starwaves/starwaves master music';
}

function masterNetdiskProxyUrlCallback(int $songId, string $variant): string {
    return '/backend/netdisk_stream.php?id=' . $songId . '&variant=' . $variant;
}

$expectedToken = expectedMasteringCallbackToken();
if ($expectedToken === '') {
    logMessage('Master callback rejected: MASTERING_CALLBACK_TOKEN missing');
    masterCallbackJson(500, ['ok' => false, 'error' => 'server callback token not configured']);
}

$receivedToken = receivedBearerToken();
if ($receivedToken === '' || !hash_equals($expectedToken, $receivedToken)) {
    logMessage('Master callback rejected: invalid token');
    masterCallbackJson(401, ['ok' => false, 'error' => 'invalid callback token']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    masterCallbackJson(405, ['ok' => false, 'error' => 'only POST is allowed']);
}

$input = readMasterCallbackInput();
$jobId = trim((string) ($input['job_id'] ?? ''));
$providerJobId = trim((string) ($input['provider_job_id'] ?? ''));
$songId = (int) ($input['song_id'] ?? 0);
$status = trim((string) ($input['status'] ?? ''));
$previewFileUrl = trim((string) ($input['preview_file_url'] ?? ''));
$masterFileUrl = trim((string) ($input['master_file_url'] ?? ''));
$masterMp3Url = trim((string) ($input['master_mp3_url'] ?? ''));
$errorCode = trim((string) ($input['error_code'] ?? ''));
$errorMessage = trim((string) ($input['error_message'] ?? ''));
$meta = is_array($input['meta'] ?? null) ? $input['meta'] : [];

if ($jobId === '' || $songId <= 0 || $status === '') {
    masterCallbackJson(422, ['ok' => false, 'error' => 'job_id, song_id, status are required']);
}

$pdo = getPdo();
ensureMasteringJobsTableCallback($pdo);
$stmt = $pdo->prepare('SELECT * FROM mastering_jobs WHERE id = :id AND song_id = :song_id LIMIT 1');
$stmt->execute([
    ':id' => (int) $jobId,
    ':song_id' => $songId,
]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$job) {
    masterCallbackJson(404, ['ok' => false, 'error' => 'mastering job not found']);
}

$songStmt = $pdo->prepare('SELECT id, title FROM songs WHERE id = :id LIMIT 1');
$songStmt->execute([':id' => $songId]);
$song = $songStmt->fetch(PDO::FETCH_ASSOC);
if (!$song) {
    masterCallbackJson(404, ['ok' => false, 'error' => 'song not found']);
}

try {
    if ($status === 'failed') {
        $fullError = trim($errorCode . ' ' . $errorMessage);
        $pdo->prepare('UPDATE mastering_jobs SET status = :status, notes = :notes, error_message = :error_message, provider_job_id = :provider_job_id, provider_name = :provider_name, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute([
                ':status' => 'processing_failed',
                ':notes' => '软件母带服务回调失败。',
                ':error_message' => $fullError !== '' ? $fullError : 'software mastering callback failed',
                ':provider_job_id' => $providerJobId,
                ':provider_name' => 'external_mastering_api',
                ':id' => (int) $job['id'],
            ]);
        $pdo->prepare('UPDATE songs SET mastering_status = :status, mastering_job_id = :mastering_job_id WHERE id = :song_id')
            ->execute([
                ':status' => 'failed',
                ':mastering_job_id' => (int) $job['id'],
                ':song_id' => $songId,
            ]);
        logMessage('Master callback failed: job_id=' . $jobId . ', provider_job_id=' . $providerJobId . ', error=' . $fullError);
        masterCallbackJson(200, ['ok' => true, 'accepted' => true, 'job_id' => $jobId, 'status' => 'failed']);
    }

    if ($status !== 'completed') {
        $pdo->prepare('UPDATE mastering_jobs SET status = :status, notes = :notes, provider_job_id = :provider_job_id, provider_name = :provider_name, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute([
                ':status' => $status,
                ':notes' => '软件母带服务状态已更新：' . $status,
                ':provider_job_id' => $providerJobId,
                ':provider_name' => 'external_mastering_api',
                ':id' => (int) $job['id'],
            ]);
        $pdo->prepare('UPDATE songs SET mastering_status = :status, mastering_job_id = :mastering_job_id WHERE id = :song_id')
            ->execute([
                ':status' => $status,
                ':mastering_job_id' => (int) $job['id'],
                ':song_id' => $songId,
            ]);
        logMessage('Master callback progress update: job_id=' . $jobId . ', provider_job_id=' . $providerJobId . ', status=' . $status);
        masterCallbackJson(200, ['ok' => true, 'accepted' => true, 'job_id' => $jobId, 'status' => $status]);
    }

    if ($previewFileUrl === '' && $masterFileUrl === '' && $masterMp3Url === '') {
        masterCallbackJson(422, ['ok' => false, 'error' => 'completed callback requires at least one result file url']);
    }

    $remoteDir = callbackArchiveDir('master');
    $masteredArchivePath = $masterFileUrl !== '' ? uploadRemoteFileToNetdisk($masterFileUrl, $remoteDir, basename(parse_url($masterFileUrl, PHP_URL_PATH) ?: 'master.wav')) : null;
    $previewSourceUrl = $previewFileUrl !== '' ? $previewFileUrl : $masterMp3Url;
    $masteredPreviewArchivePath = $previewSourceUrl !== '' ? uploadRemoteFileToNetdisk($previewSourceUrl, $remoteDir, basename(parse_url($previewSourceUrl, PHP_URL_PATH) ?: 'master-preview.mp3')) : null;

    if (!$masteredArchivePath && !$masteredPreviewArchivePath) {
        throw new RuntimeException('master callback files could not be archived to netdisk');
    }

    $analysisAfterJson = !empty($meta)
        ? json_encode(['provider_job_id' => $providerJobId, 'meta' => $meta], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null;

    $pdo->prepare('UPDATE mastering_jobs SET status = :status, output_file = :output_file, preview_file = :preview_file, notes = :notes, error_message = NULL, provider_job_id = :provider_job_id, provider_name = :provider_name, analysis_after_json = :analysis_after_json, completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
        ->execute([
            ':status' => 'completed',
            ':output_file' => $masteredArchivePath ?: (string) ($job['output_file'] ?? ''),
            ':preview_file' => $masteredPreviewArchivePath ?: (string) ($job['preview_file'] ?? ''),
            ':notes' => '软件母带服务已回调完成，结果已归档到百度网盘。',
            ':provider_job_id' => $providerJobId,
            ':provider_name' => 'external_mastering_api',
            ':analysis_after_json' => $analysisAfterJson,
            ':id' => (int) $job['id'],
        ]);

    $pdo->prepare('UPDATE songs SET mastering_status = :status, mastering_job_id = :mastering_job_id, mastered_file_path = :mastered_file_path, mastered_preview_path = :mastered_preview_path, mastered_archive_path = :mastered_archive_path, mastered_preview_archive_path = :mastered_preview_archive_path, mastered_at = CURRENT_TIMESTAMP WHERE id = :song_id')
        ->execute([
            ':status' => 'completed',
            ':mastering_job_id' => (int) $job['id'],
            ':mastered_file_path' => $masteredArchivePath ? masterNetdiskProxyUrlCallback($songId, 'mastered_file') : (string) ($job['output_file'] ?? ''),
            ':mastered_preview_path' => $masteredPreviewArchivePath ? masterNetdiskProxyUrlCallback($songId, 'mastered_preview') : (string) ($job['preview_file'] ?? ''),
            ':mastered_archive_path' => $masteredArchivePath ?: null,
            ':mastered_preview_archive_path' => $masteredPreviewArchivePath ?: null,
            ':song_id' => $songId,
        ]);

    logMessage('Master callback completed: job_id=' . $jobId . ', provider_job_id=' . $providerJobId . ', song_id=' . $songId);
    masterCallbackJson(200, ['ok' => true, 'accepted' => true, 'job_id' => $jobId, 'status' => 'archived']);
} catch (Throwable $e) {
    logMessage('Master callback exception: job_id=' . $jobId . ', error=' . $e->getMessage());
    masterCallbackJson(500, ['ok' => false, 'error' => $e->getMessage()]);
}
