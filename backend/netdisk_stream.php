<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

const NETDISK_DLINK_TTL_SECONDS = 7 * 3600;
const NETDISK_DLINK_REFRESH_BUFFER_SECONDS = 3600;
const NETDISK_VALIDATE_TIMEOUT_SECONDS = 8;
const NETDISK_LOCAL_CACHE_TTL_SECONDS = 1800;

function fail(int $code, string $message): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function netdiskToken(): string {
    $cmd = 'python3 ' . escapeshellarg('/root/.openclaw/workspace/skills/baidu_pan_api/main.py') . ' token';
    $output = shell_exec($cmd . ' 2>&1');
    $json = json_decode((string) $output, true);
    $token = is_array($json) ? (string) ($json['access_token'] ?? '') : '';
    if ($token === '') {
        fail(502, 'Baidu token unavailable');
    }
    return $token;
}

function requestJson(string $url, array $params): array {
    $ch = curl_init($url . '?' . http_build_query($params));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Starwaves-Netdisk-Proxy/1.0',
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        fail(502, 'Netdisk request failed: ' . $error);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status >= 400) {
        fail(502, 'Netdisk request returned HTTP ' . $status);
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        fail(502, 'Netdisk invalid JSON response');
    }
    return $data;
}

function buildAuthorizedDlink(string $dlink, string $token): string {
    $queryGlue = str_contains($dlink, '?') ? '&' : '?';
    return $dlink . $queryGlue . 'access_token=' . rawurlencode($token);
}

function guessMimeTypeFromPath(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match ($ext) {
        'wav' => 'audio/wav',
        'flac' => 'audio/flac',
        'm4a' => 'audio/mp4',
        'aac' => 'audio/aac',
        default => 'audio/mpeg',
    };
}

function localCacheDir(): string {
    $dir = __DIR__ . '/../storage/cache/audio';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function localCachePath(int $songId, string $variant, string $archivePath): string {
    $ext = strtolower(pathinfo($archivePath, PATHINFO_EXTENSION));
    $safeExt = preg_match('/^[a-z0-9]{1,8}$/', $ext) ? $ext : 'bin';
    return localCacheDir() . '/' . $songId . '_' . preg_replace('/[^a-z0-9_\-]/i', '_', $variant) . '.' . $safeExt;
}

function isLocalCacheFresh(string $path): bool {
    return is_file($path) && (filemtime($path) ?: 0) >= (time() - NETDISK_LOCAL_CACHE_TTL_SECONDS) && filesize($path) > 0;
}

function streamLocalFile(string $path, string $contentType): void {
    if (!is_file($path)) {
        fail(404, 'Local cache file missing');
    }
    if (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $size = filesize($path);
    $start = 0;
    $end = $size - 1;
    $statusCode = 200;

    header('Content-Type: ' . $contentType);
    header('Accept-Ranges: bytes');
    header('Cache-Control: public, max-age=600');

    if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $matches)) {
        $rangeStart = $matches[1] === '' ? 0 : (int) $matches[1];
        $rangeEnd = $matches[2] === '' ? $end : (int) $matches[2];
        if ($rangeStart > $end || $rangeEnd < $rangeStart) {
            header('Content-Range: bytes */' . $size, true, 416);
            exit;
        }
        $start = $rangeStart;
        $end = min($rangeEnd, $end);
        $statusCode = 206;
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    $length = $end - $start + 1;
    http_response_code($statusCode);
    header('Content-Length: ' . $length);

    $fh = fopen($path, 'rb');
    if (!$fh) {
        fail(500, 'Failed to read local cache file');
    }
    fseek($fh, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($fh)) {
        $chunk = fread($fh, min(65536, $remaining));
        if ($chunk === false) {
            break;
        }
        $remaining -= strlen($chunk);
        echo $chunk;
        flush();
    }
    fclose($fh);
    exit;
}

function warmLocalCache(string $url, string $cachePath): bool {
    $tmpPath = $cachePath . '.tmp';
    $dir = dirname($cachePath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $fp = fopen($tmpPath, 'wb');
    if (!$fp) {
        return false;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_BUFFERSIZE => 65536,
        CURLOPT_USERAGENT => 'Starwaves-Netdisk-Proxy/1.0',
        CURLOPT_FILE => $fp,
    ]);
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($fp);
    if ($ok === false || $status >= 400 || !is_file($tmpPath) || filesize($tmpPath) <= 0) {
        @unlink($tmpPath);
        return false;
    }
    rename($tmpPath, $cachePath);
    return true;
}

function proxyRemoteFile(string $url, string $fallbackContentType = 'audio/mpeg', ?string $cachePath = null): void {
    if ($cachePath && isLocalCacheFresh($cachePath)) {
        streamLocalFile($cachePath, $fallbackContentType);
    }

    if ($cachePath && empty($_SERVER['HTTP_RANGE']) && warmLocalCache($url, $cachePath) && isLocalCacheFresh($cachePath)) {
        streamLocalFile($cachePath, $fallbackContentType);
    }

    if (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $forwardHeaders = [
        'Accept-Ranges',
        'Content-Length',
        'Content-Range',
        'Content-Type',
        'Content-Disposition',
        'ETag',
        'Last-Modified',
    ];
    $sentHeaders = [];

    header_remove('Content-Type');
    header('Content-Type: ' . $fallbackContentType);
    header('Cache-Control: public, max-age=120');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_BUFFERSIZE => 65536,
        CURLOPT_USERAGENT => 'Starwaves-Netdisk-Proxy/1.0',
        CURLOPT_HTTPHEADER => array_values(array_filter([
            !empty($_SERVER['HTTP_RANGE']) ? 'Range: ' . $_SERVER['HTTP_RANGE'] : null,
        ])),
        CURLOPT_HEADERFUNCTION => static function ($ch, string $headerLine) use (&$sentHeaders, $forwardHeaders): int {
            $trimmed = trim($headerLine);
            $length = strlen($headerLine);
            if ($trimmed === '') {
                return $length;
            }
            if (preg_match('#^HTTP/\\S+\\s+(\\d{3})#i', $trimmed, $matches)) {
                http_response_code((int) $matches[1]);
                return $length;
            }
            $parts = explode(':', $trimmed, 2);
            if (count($parts) !== 2) {
                return $length;
            }
            $name = trim($parts[0]);
            $value = trim($parts[1]);
            if ($value === '' || !in_array($name, $forwardHeaders, true)) {
                return $length;
            }
            $lowerName = strtolower($name);
            if ($lowerName === 'content-type') {
                return $length;
            }
            if (isset($sentHeaders[$lowerName])) {
                return $length;
            }
            $sentHeaders[$lowerName] = true;
            header($name . ': ' . $value, true);
            return $length;
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk): int {
            echo $chunk;
            flush();
            return strlen($chunk);
        },
    ]);

    $ok = curl_exec($ch);
    if ($ok === false) {
        $error = curl_error($ch);
        curl_close($ch);
        fail(502, 'Netdisk stream failed: ' . $error);
    }
    curl_close($ch);
    exit;
}

function dlinkCacheColumns(string $variant): array {
    if ($variant === 'mastered_file') {
        return ['mastered_cached_dlink', 'mastered_cached_dlink_expires_at'];
    }
    if ($variant === 'mastered_preview') {
        return ['mastered_preview_cached_dlink', 'mastered_preview_cached_dlink_expires_at'];
    }
    return ['netdisk_cached_dlink', 'netdisk_cached_dlink_expires_at'];
}

function isDlinkFresh(?string $expiresAt): bool {
    if (!$expiresAt) {
        return false;
    }
    $expiresTs = strtotime($expiresAt);
    if ($expiresTs === false) {
        return false;
    }
    return $expiresTs > (time() + NETDISK_DLINK_REFRESH_BUFFER_SECONDS);
}

function validateAuthorizedDlink(string $authorizedUrl): bool {
    $ch = curl_init($authorizedUrl);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => NETDISK_VALIDATE_TIMEOUT_SECONDS,
        CURLOPT_TIMEOUT => NETDISK_VALIDATE_TIMEOUT_SECONDS,
        CURLOPT_USERAGENT => 'Starwaves-Netdisk-Proxy/1.0',
    ]);
    curl_exec($ch);
    $ok = false;
    if (curl_errno($ch) === 0) {
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ok = $status >= 200 && $status < 400;
    }
    curl_close($ch);
    return $ok;
}

function fetchFreshDlink(string $archivePath, string $token): string {
    $dir = dirname($archivePath);
    $fileName = basename($archivePath);
    $list = requestJson('https://pan.baidu.com/rest/2.0/xpan/file', [
        'method' => 'list',
        'access_token' => $token,
        'dir' => $dir,
        'limit' => 1000,
    ]);
    $target = null;
    foreach ((array) ($list['list'] ?? []) as $item) {
        if (($item['server_filename'] ?? '') === $fileName) {
            $target = $item;
            break;
        }
    }
    if (!$target || empty($target['fs_id'])) {
        fail(404, 'Netdisk file not found');
    }

    $meta = requestJson('https://pan.baidu.com/rest/2.0/xpan/multimedia', [
        'method' => 'filemetas',
        'access_token' => $token,
        'fsids' => json_encode([(int) $target['fs_id']]),
        'dlink' => 1,
    ]);
    $dlink = (string) (($meta['list'][0]['dlink'] ?? ''));
    if ($dlink === '') {
        fail(502, 'Netdisk dlink unavailable');
    }
    return $dlink;
}

function updateCachedDlink(PDO $pdo, int $songId, string $variant, string $dlink): void {
    [$dlinkColumn, $expiresColumn] = dlinkCacheColumns($variant);
    $expiresAt = date('Y-m-d H:i:s', time() + NETDISK_DLINK_TTL_SECONDS);
    $sql = sprintf('UPDATE songs SET %s = :dlink, %s = :expires_at WHERE id = :id', $dlinkColumn, $expiresColumn);
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':dlink' => $dlink,
        ':expires_at' => $expiresAt,
        ':id' => $songId,
    ]);
}

$id = (int) ($_GET['id'] ?? 0);
$variant = trim((string) ($_GET['variant'] ?? 'original'));
if ($id <= 0) {
    fail(400, 'Invalid song id');
}

$pdo = getPdo();
$stmt = $pdo->prepare('SELECT id, title, file_path, storage_type, archive_path, mastered_archive_path, mastered_preview_archive_path, netdisk_cached_dlink, netdisk_cached_dlink_expires_at, mastered_cached_dlink, mastered_cached_dlink_expires_at, mastered_preview_cached_dlink, mastered_preview_cached_dlink_expires_at FROM songs WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$song = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$song) {
    fail(404, 'Song not found');
}

if ($variant === 'mastered_file') {
    $archivePath = (string) ($song['mastered_archive_path'] ?? '');
} elseif ($variant === 'mastered_preview') {
    $archivePath = (string) ($song['mastered_preview_archive_path'] ?? '');
} else {
    if (($song['storage_type'] ?? 'local') !== 'baidu_netdisk') {
        fail(400, 'Song is not stored in Baidu Netdisk');
    }
    $archivePath = (string) ($song['archive_path'] ?? '');
}

if ($archivePath === '') {
    fail(404, 'Netdisk archive path missing');
}

[$dlinkColumn, $expiresColumn] = dlinkCacheColumns($variant);
$cachedDlink = trim((string) ($song[$dlinkColumn] ?? ''));
$cachedExpiresAt = (string) ($song[$expiresColumn] ?? '');
$token = netdiskToken();

$cachePath = localCachePath((int) $song['id'], $variant, $archivePath);

if ($cachedDlink !== '' && isDlinkFresh($cachedExpiresAt)) {
    $authorizedCachedDlink = buildAuthorizedDlink($cachedDlink, $token);
    if (validateAuthorizedDlink($authorizedCachedDlink)) {
        logMessage('Netdisk cached stream proxy hit: song_id=' . $id . ', variant=' . $variant . ', archive_path=' . $archivePath);
        proxyRemoteFile($authorizedCachedDlink, guessMimeTypeFromPath($archivePath), $cachePath);
    }
    logMessage('Netdisk cached dlink invalid, refreshing: song_id=' . $id . ', variant=' . $variant . ', archive_path=' . $archivePath);
}

$freshDlink = fetchFreshDlink($archivePath, $token);
updateCachedDlink($pdo, (int) $song['id'], $variant, $freshDlink);
$authorizedFreshDlink = buildAuthorizedDlink($freshDlink, $token);

logMessage('Netdisk stream proxy refreshed: song_id=' . $id . ', variant=' . $variant . ', archive_path=' . $archivePath);
proxyRemoteFile($authorizedFreshDlink, guessMimeTypeFromPath($archivePath), $cachePath);
