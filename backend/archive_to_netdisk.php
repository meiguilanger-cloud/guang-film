<?php
require_once 'utils.php';
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function buildArtistFolder(array $song): string {
    $artist = trim((string) ($song['full_name'] ?: $song['username'] ?: 'unknown'));
    $artist = preg_replace('/[\\\/\:\*\?"<>\|]+/', '_', $artist);
    return $artist !== '' ? $artist : 'unknown';
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: manage_songs.php?archive=invalid');
    exit;
}

$pdo = getPdo();
$stmt = $pdo->prepare('SELECT s.id, s.title, s.file_path, s.lrc_path, s.storage_type, u.username, u.full_name FROM songs s LEFT JOIN users u ON u.id = s.user_id WHERE s.id = ?');
$stmt->execute([$id]);
$song = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$song) {
    header('Location: manage_songs.php?archive=missing');
    exit;
}

$artistFolder = buildArtistFolder($song);
$remoteDir = '/starwaves music/' . $artistFolder;
$base = '/root/.openclaw/workspace/skills/baidu-netdisk-skills';
$audioLocal = __DIR__ . '/uploads/' . $song['file_path'];
$lrcLocal = !empty($song['lrc_path']) ? (__DIR__ . '/' . $song['lrc_path']) : null;

if (!is_file($audioLocal)) {
    $pdo->prepare("UPDATE songs SET lyrics_note = '归档失败：本地音频文件不存在' WHERE id = ?")->execute([$id]);
    header('Location: manage_songs.php?archive=failed');
    exit;
}

$commands = [];
$commands[] = 'cd ' . escapeshellarg($base) . ' && python3 netdisk.py upload ' . escapeshellarg($audioLocal) . ' ' . escapeshellarg($remoteDir);
if ($lrcLocal && is_file($lrcLocal)) {
    $commands[] = 'cd ' . escapeshellarg($base) . ' && python3 netdisk.py upload ' . escapeshellarg($lrcLocal) . ' ' . escapeshellarg($remoteDir);
}

foreach ($commands as $command) {
    $output = shell_exec($command . ' 2>&1');
    if (strpos((string) $output, 'Upload successful') === false && strpos((string) $output, 'Done') === false) {
        $pdo->prepare("UPDATE songs SET lyrics_note = ? WHERE id = ?")->execute(['归档失败：' . trim((string) $output), $id]);
        logMessage('百度网盘归档失败：song_id=' . $id . ', output=' . trim((string) $output));
        header('Location: manage_songs.php?archive=failed');
        exit;
    }
}

$archivePath = $remoteDir . '/' . basename($song['file_path']);
$pdo->prepare("UPDATE songs SET storage_type = 'baidu_netdisk', archive_path = ?, archived_at = CURRENT_TIMESTAMP, lyrics_note = ? WHERE id = ?")
    ->execute([$archivePath, '已归档到百度网盘：' . $remoteDir, $id]);
logMessage('百度网盘归档成功：song_id=' . $id . ', archive_path=' . $archivePath);
header('Location: manage_songs.php?archive=success');
exit;
