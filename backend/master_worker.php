<?php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function ensureMasteringJobsTable(PDO $pdo): void {
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
            completed_at TEXT
        )"
    );
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
        'http' => ['timeout' => 60, 'follow_location' => 1, 'user_agent' => 'StarwavesMaster/1.0'],
        'https' => ['timeout' => 60, 'follow_location' => 1, 'user_agent' => 'StarwavesMaster/1.0'],
    ]);
    $data = @file_get_contents($source, false, $context);
    if ($data === false) {
        throw new RuntimeException('远程音频下载失败，请稍后再试。');
    }
    if (file_put_contents($targetPath, $data) === false) {
        throw new RuntimeException('远程音频已拿到，但写入服务器本地失败。');
    }
    return ['path' => $targetPath, 'name' => $filename];
}

function masterNetdiskProxyUrl(int $songId, string $variant): string {
    return '/backend/netdisk_stream.php?id=' . $songId . '&variant=' . $variant;
}

function uploadMasteringFileToNetdisk(string $localRelativePath, string $remoteDir): ?string {
    $absolutePath = dirname(__DIR__) . '/' . ltrim($localRelativePath, '/');
    if (!is_file($absolutePath)) {
        return null;
    }
    return uploadSongToNetdisk($absolutePath, $remoteDir);
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
        'reference_profile' => $decoded['reference_profile'] ?? null,
        'analysis_before' => $decoded['analysis_before'] ?? null,
        'analysis_target' => $decoded['analysis_target'] ?? null,
        'analysis_after' => $decoded['analysis_after'] ?? null,
        'analysis_delta' => $decoded['analysis_delta'] ?? null,
    ];
}

$jobId = (int) ($argv[1] ?? 0);
if ($jobId <= 0) {
    fwrite(STDERR, "missing job id\n");
    exit(1);
}

$pdo = getPdo();
ensureMasteringJobsTable($pdo);
$stmt = $pdo->prepare('SELECT mj.*, s.title AS song_title, s.file_path AS song_file_path, s.id AS real_song_id FROM mastering_jobs mj LEFT JOIN songs s ON s.id = mj.song_id WHERE mj.id = :id LIMIT 1');
$stmt->execute([':id' => $jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$job) {
    fwrite(STDERR, "job not found\n");
    exit(1);
}
if (($job['mastering_type'] ?? 'software') !== 'software') {
    exit(0);
}
if (in_array($job['status'], ['completed', 'processing'], true)) {
    exit(0);
}

$pdo->prepare('UPDATE mastering_jobs SET status = :status, notes = :notes, started_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
    ->execute([
        ':status' => 'processing',
        ':notes' => '任务已进入自动母带处理中，请稍候刷新查看结果。',
        ':id' => $jobId,
    ]);

$pdo->prepare('UPDATE songs SET mastering_status = :status, mastering_job_id = :mastering_job_id WHERE id = :song_id')
    ->execute([
        ':status' => 'processing',
        ':mastering_job_id' => $jobId,
        ':song_id' => (int) $job['real_song_id'],
    ]);

try {
    $source = (string) ($job['song_file_path'] ?: $job['preview_file'] ?: $job['input_file'] ?: '');
    $download = downloadSongToLocal($source, (string) ($job['song_title'] ?: 'song'));
    $inputFile = $download['path'];
    $mastered = runAutomatedMastering($inputFile, (string) ($job['song_title'] ?: 'song'));
    $referenceName = (string) (($mastered['reference_profile']['name'] ?? '爱的海洋_master_mmm'));
    $targetText = '';
    if (is_array($mastered['analysis_target'] ?? null)) {
        $target = $mastered['analysis_target'];
        $targetText = sprintf('%.1f LUFS / %.1f dBTP / LRA %.1f', (float) ($target['integrated_lufs'] ?? -9), (float) ($target['true_peak_dbtp'] ?? -1.1), (float) ($target['lra'] ?? 8.8));
    }
    $remoteMasterDir = '/工作/starwaves/starwaves master music';
    $masteredArchivePath = uploadMasteringFileToNetdisk($mastered['output_file'], $remoteMasterDir);
    $masteredPreviewArchivePath = uploadMasteringFileToNetdisk($mastered['preview_file'], $remoteMasterDir);
    if (!$masteredArchivePath || !$masteredPreviewArchivePath) {
        throw new RuntimeException('母带结果上传百度网盘失败');
    }

    $pdo->prepare('UPDATE mastering_jobs SET status = :status, input_file = :input_file, output_file = :output_file, preview_file = :preview_file, notes = :notes, error_message = NULL, analysis_before_json = :analysis_before_json, analysis_target_json = :analysis_target_json, analysis_after_json = :analysis_after_json, completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
        ->execute([
            ':status' => 'completed',
            ':input_file' => $inputFile,
            ':output_file' => $masteredArchivePath,
            ':preview_file' => $masteredPreviewArchivePath,
            ':notes' => '自动母带已完成，参考《' . $referenceName . '》基准生成 WAV 成品和 MP3 预览，并已回传百度网盘。' . ($targetText !== '' ? ' 目标：' . $targetText : ''),
            ':analysis_before_json' => json_encode([
                'reference' => $mastered['reference_profile'] ?? ['name' => $referenceName],
                'measured' => $mastered['analysis_before'] ?? null,
                'delta' => $mastered['analysis_delta']['before_to_reference_master'] ?? null,
                'delta_to_target' => $mastered['analysis_delta']['before_to_target'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':analysis_target_json' => json_encode([
                'reference' => $mastered['reference_profile'] ?? null,
                'target' => $mastered['analysis_target'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':analysis_after_json' => json_encode([
                'measured' => $mastered['analysis_after'] ?? null,
                'delta_to_target' => $mastered['analysis_delta']['after_to_target'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id' => $jobId,
        ]);
    $pdo->prepare('UPDATE songs SET mastering_status = :status, mastering_job_id = :mastering_job_id, mastered_file_path = :mastered_file_path, mastered_preview_path = :mastered_preview_path, mastered_archive_path = :mastered_archive_path, mastered_preview_archive_path = :mastered_preview_archive_path, mastered_at = CURRENT_TIMESTAMP WHERE id = :song_id')
        ->execute([
            ':status' => 'completed',
            ':mastering_job_id' => $jobId,
            ':mastered_file_path' => masterNetdiskProxyUrl((int) $job['real_song_id'], 'mastered_file'),
            ':mastered_preview_path' => masterNetdiskProxyUrl((int) $job['real_song_id'], 'mastered_preview'),
            ':mastered_archive_path' => $masteredArchivePath,
            ':mastered_preview_archive_path' => $masteredPreviewArchivePath,
            ':song_id' => (int) $job['real_song_id'],
        ]);
    logMessage('Mastering worker completed: job_id=' . $jobId . ', engine=' . $mastered['engine'] . ', reference=' . $referenceName);
} catch (Throwable $e) {
    $pdo->prepare('UPDATE mastering_jobs SET status = :status, notes = :notes, error_message = :error_message, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
        ->execute([
            ':status' => 'processing_failed',
            ':notes' => '自动母带处理失败，请稍后重试或检查源音频链接。',
            ':error_message' => $e->getMessage(),
            ':id' => $jobId,
        ]);
    $pdo->prepare('UPDATE songs SET mastering_status = :status, mastering_job_id = :mastering_job_id WHERE id = :song_id')
        ->execute([
            ':status' => 'failed',
            ':mastering_job_id' => $jobId,
            ':song_id' => (int) $job['real_song_id'],
        ]);
    logMessage('Mastering worker failed: job_id=' . $jobId . ', error=' . $e->getMessage());
    exit(1);
}

