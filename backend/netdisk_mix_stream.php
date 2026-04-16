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
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'Starwaves-Mix-Proxy/1.0']);
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

function redirectToRemoteFileMix(string $url, string $token): void {
    $queryGlue = str_contains($url, '?') ? '&' : '?';
    $finalUrl = $url . $queryGlue . 'access_token=' . rawurlencode($token);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Location: ' . $finalUrl, true, 302);
    exit;
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
$archivePath = $variant === 'mix_file' ? (string) ($job['mix_file_archive_path'] ?? '') : (string) ($job['preview_archive_path'] ?? '');
if ($archivePath === '') {
    failMix(404, 'Mix netdisk archive path missing');
}
$token = netdiskTokenMix();
$dir = dirname($archivePath);
$fileName = basename($archivePath);
$list = requestJsonMix('https://pan.baidu.com/rest/2.0/xpan/file', ['method' => 'list', 'access_token' => $token, 'dir' => $dir, 'limit' => 1000]);
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
$meta = requestJsonMix('https://pan.baidu.com/rest/2.0/xpan/multimedia', ['method' => 'filemetas', 'access_token' => $token, 'fsids' => json_encode([(int) $target['fs_id']]), 'dlink' => 1]);
$dlink = (string) (($meta['list'][0]['dlink'] ?? ''));
if ($dlink === '') {
    failMix(502, 'Netdisk dlink unavailable');
}
redirectToRemoteFileMix($dlink, $token);
