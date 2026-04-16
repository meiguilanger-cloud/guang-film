<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

function failMix(int $code, string $message): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function netdiskTokenMix(): string {
    $cmd = 'python3 ' . escapeshellarg('/root/.openclaw/workspace/skills/baidu_pan_api/main.py') . ' token';
    $output = shell_exec($cmd . ' 2>&1');
    $json = json_decode((string) $output, true);
    $token = is_array($json) ? (string) ($json['access_token'] ?? '') : '';
    if ($token === '') {
        failMix(502, 'Baidu token unavailable');
    }
    return $token;
}

function requestJsonMix(string $url, array $params): array {
    $ch = curl_init($url . '?' . http_build_query($params));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Starwaves-Mix-Proxy/1.0',
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        failMix(502, 'Netdisk request failed: ' . $error);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status >= 400) {
        failMix(502, 'Netdisk request returned HTTP ' . $status);
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        failMix(502, 'Netdisk invalid JSON response');
    }
    return $data;
}

function buildAuthorizedDlinkMix(string $dlink, string $token): string {
    $queryGlue = str_contains($dlink, '?') ? '&' : '?';
    return $dlink . $queryGlue . 'access_token=' . rawurlencode($token);
}

function guessMixMimeType(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match ($ext) {
        'wav' => 'audio/wav',
        'flac' => 'audio/flac',
        'm4a' => 'audio/mp4',
        'aac' => 'audio/aac',
        default => 'audio/mpeg',
    };
}

function proxyRemoteFileMix(string $url, string $fallbackContentType = 'audio/mpeg'): void {
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
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_BUFFERSIZE => 65536,
        CURLOPT_USERAGENT => 'Starwaves-Mix-Proxy/1.0',
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
        failMix(502, 'Netdisk mix stream failed: ' . $error);
    }
    curl_close($ch);
    exit;
}

function fetchFreshDlinkMix(string $archivePath, string $token): string {
    $dir = dirname($archivePath);
    $fileName = basename($archivePath);
    $list = requestJsonMix('https://pan.baidu.com/rest/2.0/xpan/file', [
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
        failMix(404, 'Netdisk mix file not found');
    }

    $meta = requestJsonMix('https://pan.baidu.com/rest/2.0/xpan/multimedia', [
        'method' => 'filemetas',
        'access_token' => $token,
        'fsids' => json_encode([(int) $target['fs_id']]),
        'dlink' => 1,
    ]);
    $dlink = (string) (($meta['list'][0]['dlink'] ?? ''));
    if ($dlink === '') {
        failMix(502, 'Netdisk dlink unavailable');
    }
    return $dlink;
}

$id = (int) ($_GET['id'] ?? 0);
$variant = trim((string) ($_GET['variant'] ?? 'preview'));
if ($id <= 0) {
    failMix(400, 'Invalid mix job id');
}

$pdo = getPdo();
$stmt = $pdo->prepare('SELECT id, preview_archive_path, mix_file_archive_path FROM mix_jobs WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$job) {
    failMix(404, 'Mix job not found');
}

$archivePath = $variant === 'mix_file'
    ? (string) ($job['mix_file_archive_path'] ?? '')
    : (string) ($job['preview_archive_path'] ?? '');
if ($archivePath === '') {
    failMix(404, 'Mix netdisk archive path missing');
}

$token = netdiskTokenMix();
$freshDlink = fetchFreshDlinkMix($archivePath, $token);
$authorizedDlink = buildAuthorizedDlinkMix($freshDlink, $token);

logMessage('Netdisk mix stream proxied: mix_job_id=' . $id . ', variant=' . $variant . ', archive_path=' . $archivePath);
proxyRemoteFileMix($authorizedDlink, guessMixMimeType($archivePath));
