<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/utils.php';

// Simple API: expects POST with song_id
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['song_id'])) {
    http_response_code(400);
    echo 'Invalid request';
    exit;
}

$songId = (int)$_POST['song_id'];
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$now = time();

// Load recent play logs to enforce limits
$logPath = __DIR__.'/../logs/play.log';
$hourAgo = $now - 3600;
$todayStart = strtotime('today', $now);
$hourCount = 0;
$dayCount = 0;
if (file_exists($logPath)) {
    $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Expected format: ISO8601 | song_id=XX | ip=YYY
        if (preg_match('/^(.*?) \| song_id=\d+ \| ip=([^|]+)/', $line, $m)) {
            $timestamp = strtotime($m[1]);
            $logIp = $m[2];
            if ($logIp === $ip) {
                if ($timestamp >= $hourAgo) $hourCount++;
                if ($timestamp >= $todayStart) $dayCount++;
            }
        }
    }
}

// Enforce limits: max 1 play per hour per client, max 12 plays per day per IP
if ($hourCount >= 1) {
    http_response_code(429);
    echo 'Play limit reached: only one play per hour for this client.';
    exit;
}
if ($dayCount >= 12) {
    http_response_code(429);
    echo 'Daily IP play limit reached (12 per day).';
    exit;
}

$pdo = getPdo();
$stmt = $pdo->prepare('UPDATE songs SET play_count = play_count + 1 WHERE id = :id');
$stmt->execute([':id' => $songId]);

// Log play event
$logLine = date('c') . " | song_id=$songId | ip=$ip\n";
file_put_contents($logPath, $logLine, FILE_APPEND | LOCK_EX);

logMessage("Play increment: song_id=$songId ip=$ip");

echo 'OK';
?>