<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/config.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '请先登录';
    exit;
}

function failDownload(int $code, string $message): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function sanitizeDownloadFilename(string $title): string {
    $title = trim($title);
    if ($title === '') {
        return 'song';
    }
    $title = preg_replace('/[\\\\\/\:\*\?"<>\|]+/', ' ', $title);
    $title = preg_replace('/\s+/', ' ', (string) $title);
    return trim((string) $title) ?: 'song';
}

function downloadRemoteToTemp(string $url, string $suffix): ?string {
    $tmpPath = tempnam(sys_get_temp_dir(), 'sw_dl_');
    if ($tmpPath === false) {
        return null;
    }
    $targetPath = $tmpPath . $suffix;
    @rename($tmpPath, $targetPath);

    $fp = fopen($targetPath, 'wb');
    if (!$fp) {
        @unlink($targetPath);
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_USERAGENT => 'Starwaves-Download/1.0',
    ]);
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($fp);

    if ($ok === false || $status >= 400 || !is_file($targetPath) || filesize($targetPath) === 0) {
        @unlink($targetPath);
        return null;
    }

    return $targetPath;
}

function localUploadPathForSong(array $song): ?string {
    $raw = trim((string) ($song['file_path'] ?? ''));
    if ($raw === '') {
        return null;
    }
    $normalized = ltrim(str_replace('\\', '/', $raw), '/');
    if (preg_match('#^https?://#i', $normalized) || str_starts_with($normalized, 'backend/netdisk_')) {
        return null;
    }
    $candidate = __DIR__ . '/uploads/' . basename($normalized);
    return is_file($candidate) ? $candidate : null;
}

function internalSongStreamUrl(int $songId): string {
    return siteUrl('backend/netdisk_stream.php?id=' . $songId);
}

function streamFileDownload(string $path, string $downloadName, string $contentType): void {
    if (!is_file($path)) {
        failDownload(404, '文件不存在');
    }
    if (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('Cache-Control: private, max-age=0, no-store');
    readfile($path);
    exit;
}

$songId = (int) ($_GET['id'] ?? 0);
$format = strtolower(trim((string) ($_GET['format'] ?? 'mp3')));
if ($songId <= 0) {
    failDownload(400, '无效歌曲');
}
if (!in_array($format, ['mp3', 'wav'], true)) {
    failDownload(400, '仅支持 mp3 或 wav');
}

$pdo = getPdo();
$stmt = $pdo->prepare('SELECT id, title, file_path, storage_type, archive_path, mastered_file_path, mastered_preview_path, mastered_archive_path, mastered_preview_archive_path, mastering_status FROM songs WHERE id = ? LIMIT 1');
$stmt->execute([$songId]);
$song = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$song) {
    failDownload(404, '歌曲不存在');
}

$downloadBase = sanitizeDownloadFilename((string) ($song['title'] ?? 'song'));
$sourcePath = null;
$tempSource = null;
$tempOutput = null;

$localPath = localUploadPathForSong($song);
if ($localPath) {
    $sourcePath = $localPath;
} else {
    $streamUrl = internalSongStreamUrl($songId);
    $suffix = '.' . strtolower(pathinfo((string) ($song['archive_path'] ?? $song['file_path'] ?? 'song.mp3'), PATHINFO_EXTENSION) ?: 'mp3');
    $tempSource = downloadRemoteToTemp($streamUrl, $suffix);
    if ($tempSource) {
        $sourcePath = $tempSource;
    }
}

if (!$sourcePath) {
    failDownload(404, '歌曲源文件不存在');
}

register_shutdown_function(static function () use (&$tempSource, &$tempOutput): void {
    if ($tempSource && is_file($tempSource)) {
        @unlink($tempSource);
    }
    if ($tempOutput && is_file($tempOutput)) {
        @unlink($tempOutput);
    }
});

$sourceExt = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
if ($format === 'mp3' && $sourceExt === 'mp3') {
    streamFileDownload($sourcePath, $downloadBase . '.mp3', 'audio/mpeg');
}
if ($format === 'wav' && $sourceExt === 'wav') {
    streamFileDownload($sourcePath, $downloadBase . '.wav', 'audio/wav');
}

$tmpOutput = tempnam(sys_get_temp_dir(), 'sw_dl_out_');
if ($tmpOutput === false) {
    failDownload(500, '无法创建临时下载文件');
}
$tempOutput = $tmpOutput . '.' . $format;
@rename($tmpOutput, $tempOutput);

$codecArgs = $format === 'mp3'
    ? '-codec:a libmp3lame -b:a 192k'
    : '-c:a pcm_s16le';
$command = sprintf(
    'ffmpeg -y -i %s %s %s 2>&1',
    escapeshellarg($sourcePath),
    $codecArgs,
    escapeshellarg($tempOutput)
);
exec($command, $output, $code);
if ($code !== 0 || !is_file($tempOutput) || filesize($tempOutput) === 0) {
    failDownload(500, '下载转码失败');
}

streamFileDownload($tempOutput, $downloadBase . '.' . $format, $format === 'mp3' ? 'audio/mpeg' : 'audio/wav');
