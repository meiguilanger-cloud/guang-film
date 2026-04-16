<?php
require_once 'utils.php';
require_once 'db.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli && !isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function formatLrcTimestamp(int $seconds): string {
    $minutes = floor($seconds / 60);
    $remain = $seconds % 60;
    return sprintf('[%02d:%02d.00]', $minutes, $remain);
}

function normalizeLyricsText(string $lyrics): string {
    return str_replace(["\r\n", "\r", "\\n"], "\n", trim($lyrics));
}

function shouldSkipLyricsLine(string $line): bool {
    if ($line === '') {
        return true;
    }
    if (preg_match('/^(voice|style|genre|mood|tempo|instrumentation)\s*:/iu', $line)) {
        return true;
    }
    if (preg_match('/^[\[(（【].{1,24}[\])）】]$/u', $line)) {
        return true;
    }
    if (preg_match('/^(词曲|作词|作曲|词|曲)\s*[：:].*$/u', $line)) {
        return true;
    }
    return false;
}

function splitLyricsLines(string $lyrics): array {
    $normalized = normalizeLyricsText($lyrics);
    $lines = array_map('trim', preg_split('/\n/', $normalized));
    return array_values(array_filter($lines, static function ($line) {
        return !shouldSkipLyricsLine($line);
    }));
}

function lyricTimingWeight(string $line): int {
    $plain = preg_replace('/\s+/u', '', $line) ?? $line;
    $plain = preg_replace('/[,.!?;:，。！？；：、~\-—()\[\]{}"“”‘’]+/u', '', $plain) ?? $plain;
    $length = function_exists('mb_strlen') ? mb_strlen($plain, 'UTF-8') : strlen($plain);
    return max(2, (int) $length);
}

function buildLrcFromLyrics(string $lyrics, ?float $durationSeconds = null): string {
    $lines = splitLyricsLines($lyrics);
    if (empty($lines)) {
        return '';
    }

    $lrc = [];
    if (is_numeric($durationSeconds) && (float) $durationSeconds > 0) {
        $weights = array_map('lyricTimingWeight', $lines);
        $totalWeight = max(1, array_sum($weights));
        $usableDuration = max(1.0, (float) $durationSeconds - 1.5);
        $elapsedWeight = 0.0;
        foreach ($lines as $index => $line) {
            $seconds = ($elapsedWeight / $totalWeight) * $usableDuration;
            $lrc[] = formatLrcTimestamp((int) round($seconds)) . $line;
            $elapsedWeight += $weights[$index];
        }
        return implode(PHP_EOL, $lrc) . PHP_EOL;
    }

    $seconds = 0;
    foreach ($lines as $line) {
        $lrc[] = formatLrcTimestamp($seconds) . $line;
        $seconds += 5;
    }
    return implode(PHP_EOL, $lrc) . PHP_EOL;
}

function buildAlignedLrcFromOriginalLyrics(string $lyrics, array $timeline): string {
    $lines = splitLyricsLines($lyrics);
    $timeline = array_values(array_filter($timeline, static function ($item) {
        return is_array($item) && isset($item['start']) && is_numeric($item['start']);
    }));
    if (empty($lines) || empty($timeline)) {
        return '';
    }

    $lineCount = count($lines);
    $timeCount = count($timeline);
    $lrc = [];
    foreach ($lines as $index => $line) {
        $timeIndex = (int) floor(($index * $timeCount) / max(1, $lineCount));
        $timeIndex = min($timeCount - 1, max(0, $timeIndex));
        $seconds = max(0, (float) ($timeline[$timeIndex]['start'] ?? 0));
        $minutes = floor($seconds / 60);
        $remain = $seconds - ($minutes * 60);
        $lrc[] = sprintf('[%02d:%05.2f]%s', $minutes, $remain, $line);
    }
    return implode(PHP_EOL, $lrc) . PHP_EOL;
}

function downloadRemoteAudioToTemp(string $url, string $suffix = '.mp3'): ?string {
    $tmpPath = tempnam(sys_get_temp_dir(), 'sw_lyrics_');
    if ($tmpPath === false) {
        return null;
    }
    if ($suffix !== '') {
        $renamedPath = $tmpPath . $suffix;
        @rename($tmpPath, $renamedPath);
        $tmpPath = $renamedPath;
    }

    $fp = fopen($tmpPath, 'wb');
    if (!$fp) {
        @unlink($tmpPath);
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_USERAGENT => 'Starwaves-Lyrics-Recognizer/1.0',
    ]);
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($ok === false || $status >= 400 || filesize($tmpPath) === 0) {
        @unlink($tmpPath);
        logMessage('歌词识别拉取远端音频失败：url=' . $url . ', status=' . $status . ', error=' . $error);
        return null;
    }
    return $tmpPath;
}

function resolveAudioPathForRecognition(array $song): array {
    $rawPath = trim((string) ($song['file_path'] ?? ''));
    $localPath = __DIR__ . '/uploads/' . $rawPath;
    if ($rawPath !== '' && is_file($localPath)) {
        return [$localPath, null];
    }

    if (($song['storage_type'] ?? 'local') === 'baidu_netdisk' && !empty($song['id'])) {
        $suffix = pathinfo((string) ($song['archive_path'] ?? $rawPath), PATHINFO_EXTENSION);
        $suffix = $suffix !== '' ? '.' . strtolower($suffix) : '.mp3';
        $streamUrl = 'http://127.0.0.1:8080/backend/netdisk_stream.php?id=' . (int) $song['id'];
        $tempPath = downloadRemoteAudioToTemp($streamUrl, $suffix);
        if ($tempPath) {
            return [$tempPath, $tempPath];
        }
    }

    return [null, null];
}

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? ($argv[1] ?? 0));
if ($id <= 0) {
    http_response_code(400);
    echo '无效的歌曲 ID';
    exit;
}

$pdo = getPdo();
$stmt = $pdo->prepare('SELECT id, title, lyrics, lrc_path, file_path, storage_type, archive_path FROM songs WHERE id = ?');
$stmt->execute([$id]);
$song = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$song) {
    http_response_code(404);
    echo '歌曲不存在';
    exit;
}

$dir = __DIR__ . '/lyrics';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

[$audioPath, $tempAudioPath] = resolveAudioPathForRecognition($song);

if (!empty($song['lyrics'])) {
    $lrcContent = '';
    $lyricsNote = '已根据原歌词生成 LRC';
    if ($audioPath && is_file($audioPath)) {
        $alignedScript = __DIR__ . '/scripts/transcribe_song.py';
        $alignedTemp = tempnam(sys_get_temp_dir(), 'sw_align_');
        if ($alignedTemp !== false) {
            $alignedOutputPath = $alignedTemp . '.lrc';
            @rename($alignedTemp, $alignedOutputPath);
            $alignedCommand = sprintf('python3 %s %s %s 2>&1', escapeshellarg($alignedScript), escapeshellarg($audioPath), escapeshellarg($alignedOutputPath));
            $alignedOutput = shell_exec($alignedCommand);
            $alignedResult = json_decode(trim((string) $alignedOutput), true);
            if (is_array($alignedResult) && !empty($alignedResult['ok']) && !empty($alignedResult['timeline']) && is_array($alignedResult['timeline'])) {
                $lrcContent = buildAlignedLrcFromOriginalLyrics((string) $song['lyrics'], $alignedResult['timeline']);
                if ($lrcContent !== '') {
                    $lyricsNote = (($song['source'] ?? '') === 'ai')
                        ? '已按原歌词对齐主唱时间生成 LRC'
                        : '已按主唱时间对齐上传歌词生成 LRC';
                }
            }
            if (is_file($alignedOutputPath)) {
                @unlink($alignedOutputPath);
            }
        }
    }
    if ($lrcContent === '') {
        $durationSeconds = is_numeric($song['duration_seconds'] ?? null) ? (float) $song['duration_seconds'] : null;
        if ((!is_numeric($durationSeconds) || $durationSeconds <= 0) && $audioPath && is_file($audioPath)) {
            $durationSeconds = detectAudioDuration($audioPath);
        }
        $lrcContent = buildLrcFromLyrics((string) $song['lyrics'], $durationSeconds);
        if ($lrcContent !== '') {
            $lyricsNote = '已按原歌词和整首时长重建 LRC';
        }
    }
    if ($lrcContent === '') {
        $pdo->prepare("UPDATE songs SET lyrics_status = 'pending', lyrics_note = '歌词为空，无法生成 LRC' WHERE id = ?")->execute([$id]);
        header('Location: manage_songs.php?queued=' . $id);
        exit;
    }

    $filename = 'auto_' . $id . '_' . bin2hex(random_bytes(6)) . '.lrc';
    $path = $dir . '/' . $filename;
    file_put_contents($path, $lrcContent);

    $pdo->prepare("UPDATE songs SET lrc_path = ?, lyrics_status = 'generated', lyrics_note = ?, lrc_generated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute(['lyrics/' . $filename, $lyricsNote, $id]);

    if (!empty($tempAudioPath) && is_file($tempAudioPath)) {
        @unlink($tempAudioPath);
    }
    logMessage('自动生成 LRC：song_id=' . $id . ', title=' . $song['title']);
    if ($isCli) {
        echo json_encode(['ok' => true, 'id' => $id, 'mode' => 'lrc_from_lyrics'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: manage_songs.php?generated=' . $id);
    exit;
}

if (!$audioPath || !is_file($audioPath)) {
    $pdo->prepare("UPDATE songs SET lyrics_status = 'pending_recognition', lyrics_note = '音频文件不存在，无法进行自动识别' WHERE id = ?")
        ->execute([$id]);
    logMessage('自动识别失败：音频文件不存在，song_id=' . $id);
    if ($isCli) {
        echo json_encode(['ok' => false, 'id' => $id, 'error' => 'audio_missing'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: manage_songs.php?queued=' . $id);
    exit;
}

$pdo->prepare("UPDATE songs SET lyrics_status = 'pending_recognition', lyrics_note = 'Whisper 正在尝试自动识别歌词' WHERE id = ?")
    ->execute([$id]);
$filename = 'auto_' . $id . '_' . bin2hex(random_bytes(6)) . '.lrc';
$lrcPath = $dir . '/' . $filename;
$script = __DIR__ . '/scripts/transcribe_song.py';
$command = sprintf('python3 %s %s %s 2>&1', escapeshellarg($script), escapeshellarg($audioPath), escapeshellarg($lrcPath));
$output = shell_exec($command);
if (!empty($tempAudioPath) && is_file($tempAudioPath)) {
    @unlink($tempAudioPath);
}
$result = json_decode(trim((string) $output), true);

if (!is_array($result) || empty($result['ok'])) {
    $pdo->prepare("UPDATE songs SET lyrics_status = 'pending_recognition', lyrics_note = '识别失败，可稍后重试或手动补充歌词' WHERE id = ?")
        ->execute([$id]);
    logMessage('自动识别失败：song_id=' . $id . ', output=' . trim((string) $output));
    if ($isCli) {
        echo json_encode(['ok' => false, 'id' => $id, 'error' => 'recognition_failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: manage_songs.php?queued=' . $id);
    exit;
}

$lyrics = trim((string) ($result['lyrics'] ?? ''));
$pdo->prepare("UPDATE songs SET lyrics = ?, lrc_path = ?, lyrics_status = 'generated', lyrics_note = ?, lrc_generated_at = CURRENT_TIMESTAMP WHERE id = ?")
    ->execute([$lyrics, 'lyrics/' . $filename, 'Whisper 自动识别完成，并已生成 LRC', $id]);
logMessage('Whisper 自动识别成功：song_id=' . $id . ', title=' . $song['title'] . ', segments=' . ($result['segments'] ?? 0));
if ($isCli) {
    echo json_encode(['ok' => true, 'id' => $id, 'mode' => 'whisper', 'segments' => ($result['segments'] ?? 0)], JSON_UNESCAPED_UNICODE);
    exit;
}
header('Location: manage_songs.php?generated=' . $id);
exit;
