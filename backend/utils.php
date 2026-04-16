<?php
// utils.php - 公共帮助函数

require_once __DIR__ . '/config.php';

$sessionDir = __DIR__ . '/../storage/sessions';
if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0755, true);
}

function isHttpsRequest(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function cookieDomain(): string {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $host = preg_replace('/:\d+$/', '', $host);
    if ($host === 'starwaves.com.cn' || $host === 'www.starwaves.com.cn') {
        return '.starwaves.com.cn';
    }
    return '';
}

$https = isHttpsRequest();

ini_set('session.save_path', $sessionDir);
ini_set('session.gc_maxlifetime', (string) (30 * 86400));
ini_set('session.cookie_lifetime', '0');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');

if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => cookieDomain(),
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

session_start();
require_once __DIR__ . '/db.php';

function restoreRememberedLogin(): void {
    if (!empty($_SESSION['user_id'])) {
        return;
    }
    if (empty($_COOKIE['remember_selector']) || empty($_COOKIE['remember_token'])) {
        return;
    }

    try {
        $pdo = getPdo();
        $selector = (int) $_COOKIE['remember_selector'];
        $token = (string) $_COOKIE['remember_token'];
        $stmt = $pdo->prepare('SELECT id, username, remember_token, remember_expires FROM users WHERE id = ?');
        $stmt->execute([$selector]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || empty($user['remember_token']) || empty($user['remember_expires'])) {
            return;
        }
        if (strtotime($user['remember_expires']) < time()) {
            return;
        }
        if (!hash_equals($user['remember_token'], hash('sha256', $token))) {
            return;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];

        $newToken = bin2hex(random_bytes(32));
        $expires = strtotime($user['remember_expires']);
        $pdo->prepare('UPDATE users SET remember_token = ? WHERE id = ?')->execute([hash('sha256', $newToken), $user['id']]);
        $secure = isHttpsRequest();
        $domain = cookieDomain();
        setcookie('remember_selector', (string) $user['id'], $expires, '/', $domain, $secure, true);
        setcookie('remember_token', $newToken, $expires, '/', $domain, $secure, true);
    } catch (Throwable $e) {
        logMessage('自动恢复登录失败: ' . $e->getMessage());
    }
}

function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfInput(): string {
    $token = htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8');
    return "<input type=\"hidden\" name=\"csrf_token\" value=\"{$token}\">";
}

function verifyCsrf(): bool {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return true;
    }
    $posted = $_POST['csrf_token'] ?? '';
    $session = $_SESSION['csrf_token'] ?? '';
    return $session !== '' && hash_equals($session, $posted);
}

function logMessage(string $msg): void {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/app.log';
    $date = date('Y-m-d H:i:s');
    $entry = "[$date] $msg" . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function siteAssetUrl(string $path): string {
    return staticUrl($path);
}

function mediaPathUrl(string $path): string {
    return mediaUrl($path);
}

function netdiskSongProxyUrl(int $songId, string $context = 'backend', string $variant = 'original'): string {
    $query = ['id' => $songId];
    if ($variant !== 'original') {
        $query['variant'] = $variant;
    }
    $path = 'backend/netdisk_stream.php?' . http_build_query($query);
    return $context === 'absolute' ? mediaUrl($path) : '/' . ltrim($path, '/');
}

function normalizeProjectRelativePath(string $path): string {
    $path = ltrim($path, '/');
    if (str_starts_with($path, 'starwaves_project/')) {
        return substr($path, strlen('starwaves_project/'));
    }
    return $path;
}

function resolveStoredAudioPath(?string $rawPath, string $context = 'backend'): string {
    $rawPath = trim((string) $rawPath);
    if ($rawPath === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $rawPath)) {
        return $rawPath;
    }

    $normalized = normalizeProjectRelativePath($rawPath);
    if (str_starts_with($normalized, 'backend/netdisk_stream.php') || str_starts_with($normalized, 'backend/netdisk_mix_stream.php')) {
        return $context === 'absolute' ? mediaUrl($normalized) : '/' . $normalized;
    }

    if (str_starts_with($normalized, 'backend/')) {
        return $context === 'absolute' ? siteUrl($normalized) : '/' . $normalized;
    }

    $uploadsPath = 'backend/uploads/' . rawurlencode(basename($normalized));
    return $context === 'absolute' ? siteUrl($uploadsPath) : '/' . $uploadsPath;
}

function resolveSongAudioUrl(array $song, string $context = 'backend'): string {
    $isNetdiskSong = (($song['storage_type'] ?? 'local') === 'baidu_netdisk' && !empty($song['archive_path']));
    if ($isNetdiskSong && !empty($song['id'])) {
        return netdiskSongProxyUrl((int) $song['id'], $context);
    }

    return resolveStoredAudioPath((string) ($song['file_path'] ?? ''), $context);
}

function absoluteAudioUrl(string $rawUrl): string {
    $rawUrl = trim($rawUrl);
    if ($rawUrl === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $rawUrl)) {
        return $rawUrl;
    }
    return siteUrl($rawUrl);
}

function mixJobProxyUrl(int $jobId, string $variant = 'preview', string $context = 'absolute'): string {
    $path = 'backend/netdisk_mix_stream.php?id=' . $jobId . '&variant=' . rawurlencode($variant);
    return $context === 'absolute' ? mediaUrl($path) : '/' . $path;
}

function formatAudioDuration(?float $seconds): string {
    $seconds = is_numeric($seconds) ? max(0, (int) round((float) $seconds)) : 0;
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;
    if ($hours > 0) {
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }
    return sprintf('%02d:%02d', $minutes, $secs);
}

function songDurationLabel(array $song, string $fallback = '--:--'): string {
    $label = trim((string) ($song['duration_label'] ?? ''));
    if ($label !== '') {
        return $label;
    }

    $seconds = $song['duration_seconds'] ?? null;
    if (is_numeric($seconds) && (float) $seconds > 0) {
        return formatAudioDuration((float) $seconds);
    }

    return $fallback;
}

function detectAudioDuration(string $localPath): ?float {
    if (!is_file($localPath)) {
        return null;
    }
    $command = sprintf(
        'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null',
        escapeshellarg($localPath)
    );
    $output = trim((string) shell_exec($command));
    if ($output === '' || !is_numeric($output)) {
        return null;
    }
    return (float) $output;
}

function uploadSongToNetdisk(string $localPath, string $remoteDir): ?string {
    $base = '/root/.openclaw/workspace/skills/baidu-netdisk-skills';
    $command = 'cd ' . escapeshellarg($base)
        . ' && python3 netdisk.py upload '
        . escapeshellarg($localPath) . ' ' . escapeshellarg($remoteDir) . ' 2>&1';
    $output = shell_exec($command);
    if (!is_string($output) || strpos($output, 'Upload successful!') === false) {
        logMessage('百度网盘上传失败：' . trim((string) $output));
        return null;
    }
    if (preg_match('/Path:\s*(.+)$/m', $output, $matches)) {
        return trim($matches[1]);
    }
    return rtrim($remoteDir, '/') . '/' . basename($localPath);
}

function uploadRemoteFileToNetdisk(string $remoteUrl, string $remoteDir, string $preferredName = 'remote-file.mp3'): ?string {
    $tmpDir = sys_get_temp_dir();
    $tmpPath = $tmpDir . '/' . bin2hex(random_bytes(8)) . '-' . basename($preferredName);
    $context = stream_context_create([
        'http' => ['timeout' => 120, 'follow_location' => 1, 'user_agent' => 'StarwavesNetdisk/1.0'],
        'https' => ['timeout' => 120, 'follow_location' => 1, 'user_agent' => 'StarwavesNetdisk/1.0'],
    ]);
    $data = @file_get_contents($remoteUrl, false, $context);
    if ($data === false) {
        logMessage('远程文件下载失败：' . $remoteUrl);
        return null;
    }
    if (file_put_contents($tmpPath, $data) === false) {
        logMessage('远程文件临时写入失败：' . $remoteUrl);
        return null;
    }
    try {
        return uploadSongToNetdisk($tmpPath, $remoteDir);
    } finally {
        @unlink($tmpPath);
    }
}

function loginUrlWithReturn(?string $target = null, bool $expired = false): string {
    $target = $target ?: ($_SERVER['REQUEST_URI'] ?? '../index.php');
    $query = ['redirect' => $target];
    if ($expired) {
        $query['expired'] = '1';
    }
    return 'login.php?' . http_build_query($query);
}

function requireLoginPage(?string $target = null): void {
    if (!empty($_SESSION['user_id'])) {
        return;
    }
    header('Location: ' . loginUrlWithReturn($target, true));
    exit;
}

function requireLoginJson(string $message = '请先登录后再继续操作'): void {
    if (!empty($_SESSION['user_id'])) {
        return;
    }
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => $message,
        'login_required' => true,
        'login_url' => loginUrlWithReturn('../star-ai.php', true),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function starwavesCreditCosts(): array {
    return [
        'upload_song' => 0,
        'star_ai_generate' => 1,
        'cover' => 1,
        'remaster' => 1,
        'extend' => 1,
        'split_vocal' => 2,
        'split_stem' => 10,
        'mix_software' => 100,
        'mix_hardware' => 4000,
        'master_software_1' => 10,
        'master_software_2' => 10,
        'master_hardware' => 500,
    ];
}

function starwavesCreditCost(string $key, int $default = 0): int {
    $costs = starwavesCreditCosts();
    return isset($costs[$key]) ? (int) $costs[$key] : $default;
}

function chargeUserCredits(PDO $pdo, int $userId, string $costKey, string $serviceLabel): array {
    $cost = starwavesCreditCost($costKey);
    $stmt = $pdo->prepare('SELECT credits FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new RuntimeException('用户未找到');
    }

    $currentCredits = (int) ($user['credits'] ?? 0);
    if ($cost <= 0) {
        return [
            'charged' => 0,
            'before' => $currentCredits,
            'after' => $currentCredits,
        ];
    }
    if ($currentCredits < $cost) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => '积分不足，' . $serviceLabel . '需要 ' . $cost . ' 积分，当前剩余 ' . $currentCredits . ' 积分',
            'required_credits' => $cost,
            'remaining_credits' => $currentCredits,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $update = $pdo->prepare('UPDATE users SET credits = credits - ? WHERE id = ?');
    $update->execute([$cost, $userId]);
    $afterCredits = $currentCredits - $cost;
    logMessage('用户 ID ' . $userId . ' 扣除 ' . $cost . ' 积分用于' . $serviceLabel . '，剩余 ' . $afterCredits);

    return [
        'charged' => $cost,
        'before' => $currentCredits,
        'after' => $afterCredits,
    ];
}

restoreRememberedLogin();

set_exception_handler(function (Throwable $e) {
    logMessage('未捕获异常: ' . $e->getMessage());
    http_response_code(500);
    echo '<h2>服务器内部错误</h2><p>抱歉，系统出现问题。</p>';
    exit;
});

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    logMessage("错误: {$message} in {$file}:{$line}");
    http_response_code(500);
    echo '<h2>服务器错误</h2><p>出现错误，请稍后再试。</p>';
    exit;
});
?>
