<?php
require_once __DIR__ . '/backend/utils.php';
require_once __DIR__ . '/backend/config.php';

const STARWAVES_MIX_API_BASE = 'https://tonn.roexaudio.com';
const STARWAVES_MIX_API_KEY = 'AIzaSyCAUtMkNJDzPJ2pierx3EDiAp-WI6BcNuo';
const STARWAVES_MIX_WEBHOOK = 'https://starwaves.com.cn/starwaves-mix-webhook-placeholder';

$isLoggedIn = !empty($_SESSION['user_id']);
$currentUser = null;
$mixMessage = null;
$mixError = null;
$previewUrl = null;
$previewTaskId = null;
$recentMixJobs = [];

if (!isset($_SESSION['starwaves_mix_tracks']) || !is_array($_SESSION['starwaves_mix_tracks'])) {
    $_SESSION['starwaves_mix_tracks'] = [];
}
$uploadedTracks = &$_SESSION['starwaves_mix_tracks'];

function starwaves_mix_storage_dir(): string {
    $dir = __DIR__ . '/storage/starwaves_mix_uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function starwaves_mix_keyword_match(string $name, array $keywords): bool {
    $name = mb_strtolower($name);
    foreach ($keywords as $keyword) {
        if (mb_strpos($name, $keyword) !== false) {
            return true;
        }
    }
    return false;
}

function starwaves_mix_normalize_content_type(string $mimeType, string $filename): string {
    $mimeType = strtolower($mimeType);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array($mimeType, ['audio/x-wav', 'audio/wave'], true) || $ext === 'wav') {
        return 'audio/wav';
    }
    if (in_array($mimeType, ['audio/x-flac'], true) || $ext === 'flac') {
        return 'audio/flac';
    }
    if (in_array($mimeType, ['audio/x-aiff'], true) || in_array($ext, ['aif', 'aiff'], true)) {
        return 'audio/wav';
    }
    if ($ext === 'mp3') {
        return 'audio/mpeg';
    }
    return $mimeType;
}

function starwaves_mix_invalid_for_channel(string $filename, string $channel): bool {
    $vocalKeywords = ['vocal', 'vox', 'leadvox', 'lead_vocal', 'backingvox', 'backing_vocal', 'harmony', 'adlib', 'hook', 'chorusvox', 'versevox', '人声', '主唱', '和声'];
    $musicKeywords = ['drum', 'kick', 'snare', 'hat', 'perc', 'bass', '808', 'guitar', 'piano', 'keys', 'synth', 'pad', 'fx', 'strings', 'music', 'beat', '乐器', '鼓', '贝斯', '吉他', '钢琴'];
    if ($channel === 'vocal') {
        return starwaves_mix_keyword_match($filename, $musicKeywords);
    }
    return starwaves_mix_keyword_match($filename, $vocalKeywords);
}

function starwaves_mix_detect_group(array $track): array {
    $name = mb_strtolower($track['original_name'] ?? '');
    $channel = $track['channel'] ?? 'music';
    if ($channel === 'vocal') {
        if (starwaves_mix_keyword_match($name, ['backing', 'harmony', 'double', 'adlib', 'hook', '和声'])) {
            return ['BACKING_VOX_GROUP', 'BACKGROUND'];
        }
        return ['VOCAL_GROUP', 'LEAD'];
    }
    $map = [
        'KICK_GROUP' => ['kick'],
        'SNARE_GROUP' => ['snare'],
        'DRUMS_GROUP' => ['drum', 'hat', 'clap', 'perc', 'loop', '鼓'],
        'BASS_GROUP' => ['bass', '808', 'sub', '贝斯'],
        'KEYS_GROUP' => ['piano', 'keys', 'organ', '钢琴'],
        'SYNTH_GROUP' => ['synth', 'pad'],
        'E_GUITAR_GROUP' => ['guitar', '吉他'],
        'FX_GROUP' => ['fx', 'atmo', 'sfx'],
        'STRINGS_GROUP' => ['string'],
        'BRASS_GROUP' => ['brass', 'horn'],
    ];
    foreach ($map as $group => $keywords) {
        if (starwaves_mix_keyword_match($name, $keywords)) {
            return [$group, 'NORMAL'];
        }
    }
    return ['OTHER_GROUP1', 'NORMAL'];
}

function starwaves_mix_request(string $path, array $payload): array {
    $url = STARWAVES_MIX_API_BASE . $path . '?key=' . rawurlencode(STARWAVES_MIX_API_KEY);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nX-API-Key: " . STARWAVES_MIX_API_KEY . "\r\n",
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'ignore_errors' => true,
            'timeout' => 45,
        ],
    ]);
    $body = file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $m);
    $status = isset($m[1]) ? (int) $m[1] : 0;
    $data = json_decode((string) $body, true);
    return ['status' => $status, 'body' => $body, 'json' => is_array($data) ? $data : null];
}

function starwaves_mix_put_file(string $signedUrl, string $filePath, string $contentType): bool {
    $data = file_get_contents($filePath);
    $context = stream_context_create([
        'http' => [
            'method' => 'PUT',
            'header' => "Content-Type: {$contentType}\r\n",
            'content' => $data,
            'ignore_errors' => true,
            'timeout' => 120,
        ],
    ]);
    $result = file_get_contents($signedUrl, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $m);
    $status = isset($m[1]) ? (int) $m[1] : 0;
    return $status >= 200 && $status < 300;
}

function starwaves_mix_build_track_data(array $uploadedTracks): array {
    $trackData = [];
    foreach ($uploadedTracks as $track) {
        if (!is_file($track['path'] ?? '')) {
            continue;
        }
        $uploadResp = starwaves_mix_request('/upload', [
            'filename' => basename((string) ($track['original_name'] ?? 'track.wav')),
            'contentType' => (string) ($track['mime_type'] ?? 'audio/wav'),
        ]);
        if (($uploadResp['status'] ?? 0) !== 200 || empty($uploadResp['json']['signed_url']) || empty($uploadResp['json']['readable_url'])) {
            throw new RuntimeException('申请上传地址失败：' . ((string) ($track['original_name'] ?? 'unknown')));
        }
        if (!starwaves_mix_put_file((string) $uploadResp['json']['signed_url'], (string) $track['path'], (string) $track['mime_type'])) {
            throw new RuntimeException('上传分轨失败：' . ((string) ($track['original_name'] ?? 'unknown')));
        }
        [$instrumentGroup, $presence] = starwaves_mix_detect_group($track);
        $trackData[] = [
            'trackURL' => (string) $uploadResp['json']['readable_url'],
            'instrumentGroup' => $instrumentGroup,
            'presenceSetting' => $presence,
            'panPreference' => $presence === 'LEAD' ? 'CENTRE' : 'NO_PREFERENCE',
            'reverbPreference' => ($track['channel'] ?? '') === 'vocal' ? 'LOW' : 'NONE',
        ];
    }
    return $trackData;
}

function starwaves_mix_poll_preview(string $taskId, int $attempts = 18, int $sleepSeconds = 5): ?string {
    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        if ($attempt > 0) {
            sleep($sleepSeconds);
        }
        $retrieveResp = starwaves_mix_request('/retrievepreviewmix', [
            'multitrackData' => [
                'multitrackTaskId' => $taskId,
                'retrieveFXSettings' => false,
            ],
        ]);
        if (($retrieveResp['status'] ?? 0) === 200 && !empty($retrieveResp['json']['previewMixTaskResults']['download_url_preview_mixed'])) {
            return (string) $retrieveResp['json']['previewMixTaskResults']['download_url_preview_mixed'];
        }
    }
    return null;
}

function starwaves_mix_is_ajax(): bool {
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || strpos(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json') !== false;
}

function starwaves_mix_parse_size(string $value): int {
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $number = (float) $value;
    switch ($unit) {
        case 'g':
            return (int) round($number * 1024 * 1024 * 1024);
        case 'm':
            return (int) round($number * 1024 * 1024);
        case 'k':
            return (int) round($number * 1024);
        default:
            return (int) round((float) $value);
    }
}

try {
    if ($isLoggedIn) {
        $pdo = getPdo();
        $userStmt = $pdo->prepare('SELECT username, full_name, avatar_path FROM users WHERE id = ?');
        $userStmt->execute([(int) $_SESSION['user_id']]);
        $currentUser = $userStmt->fetch();

        $jobStmt = $pdo->prepare('SELECT id, mix_mode, project_name, artist_name, song_style, track_count, status, charged_credits, preview_url, mix_file_url, created_at FROM mix_jobs WHERE user_id = ? ORDER BY created_at DESC LIMIT 6');
        $jobStmt->execute([(int) $_SESSION['user_id']]);
        $recentMixJobs = $jobStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $currentUser = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['mix_action'] ?? '';

    if ($action === 'upload_tracks') {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $runtimePostLimit = starwaves_mix_parse_size((string) ini_get('post_max_size'));
        if ($runtimePostLimit > 0 && $contentLength > $runtimePostLimit) {
            $message = '当前服务器实际上传限制还没放开到目标值，暂时会在超出 PHP 上限时被拦。现在规则先按单条音轨 100MB、整个工程 2GB 执行。';
            if (starwaves_mix_is_ajax()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $mixError = $message;
        }
    }

    if ($action === 'upload_tracks') {
        $channel = $_POST['channel'] ?? '';
        $trackRole = trim((string) ($_POST['track_role'] ?? ''));
        if (!in_array($channel, ['vocal', 'music'], true)) {
            $mixError = '上传通道无效。';
        } elseif (empty($_FILES['tracks']['name'][0])) {
            $mixError = '请先选择要上传的分轨文件。';
        } else {
            $storageDir = starwaves_mix_storage_dir();
            $allowed = ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/flac', 'audio/x-flac', 'audio/aiff', 'audio/x-aiff', 'audio/mpeg', 'application/zip', 'application/x-zip-compressed'];
            $maxTrackSize = 100 * 1024 * 1024;
            $maxProjectSize = 2 * 1024 * 1024 * 1024;
            $currentProjectSize = 0;
            foreach ($uploadedTracks as $existingTrack) {
                $currentProjectSize += (int) ($existingTrack['size'] ?? 0);
            }
            $added = 0;
            foreach ($_FILES['tracks']['name'] as $idx => $originalName) {
                if (empty($originalName) || ($_FILES['tracks']['error'][$idx] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $fileSize = (int) ($_FILES['tracks']['size'][$idx] ?? 0);
                if ($fileSize > $maxTrackSize) {
                    $mixError = '文件 `' . $originalName . '` 超过单条 100MB 上限。';
                    continue;
                }
                if (($currentProjectSize + $fileSize) > $maxProjectSize) {
                    $mixError = '当前工程累计文件大小不能超过 2GB。';
                    continue;
                }
                if (starwaves_mix_invalid_for_channel($originalName, $channel)) {
                    $mixError = '文件 `' . $originalName . '` 和当前通道不匹配，已拦下。';
                    continue;
                }
                $tmpName = $_FILES['tracks']['tmp_name'][$idx];
                $mimeType = mime_content_type($tmpName) ?: 'application/octet-stream';
                $mimeType = starwaves_mix_normalize_content_type($mimeType, $originalName);
                if (!in_array($mimeType, $allowed, true)) {
                    $mixError = '文件 `' . $originalName . '` 格式暂不支持。';
                    continue;
                }
                $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($originalName));
                $targetName = uniqid($channel . '_', true) . '_' . $safeName;
                $targetPath = $storageDir . '/' . $targetName;
                if (!move_uploaded_file($tmpName, $targetPath)) {
                    $mixError = '文件 `' . $originalName . '` 保存失败。';
                    continue;
                }
                $savedSize = filesize($targetPath) ?: 0;
                $uploadedTracks[] = [
                    'channel' => $channel,
                    'role' => $trackRole,
                    'original_name' => $originalName,
                    'stored_name' => $targetName,
                    'path' => $targetPath,
                    'mime_type' => $mimeType,
                    'size' => $savedSize,
                    'uploaded_at' => date('Y-m-d H:i:s'),
                ];
                $currentProjectSize += (int) $savedSize;
                $added++;
            }
            if ($added > 0 && !$mixError) {
                $label = $channel === 'vocal' ? ($trackRole !== '' ? $trackRole : '人声') : '乐器';
                $mixMessage = '已上传 ' . $added . ' 个' . $label . '分轨。';
            }
        }

        if (starwaves_mix_is_ajax()) {
            $vocalCountAjax = count(array_filter($uploadedTracks, static fn($t) => ($t['channel'] ?? '') === 'vocal'));
            $musicCountAjax = count(array_filter($uploadedTracks, static fn($t) => ($t['channel'] ?? '') === 'music'));
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => $mixError === null,
                'message' => $mixError === null ? $mixMessage : $mixError,
                'error' => $mixError,
                'counts' => [
                    'vocal' => $vocalCountAjax,
                    'music' => $musicCountAjax,
                    'total' => count($uploadedTracks),
                ],
                'tracks' => array_map(static function ($track) {
                    return [
                        'original_name' => (string) ($track['original_name'] ?? ''),
                        'channel' => (string) ($track['channel'] ?? ''),
                        'role' => (string) ($track['role'] ?? ''),
                        'mime_type' => (string) ($track['mime_type'] ?? ''),
                        'size' => (int) ($track['size'] ?? 0),
                    ];
                }, $uploadedTracks),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if ($action === 'preview_mix') {
        if (count($uploadedTracks) < 2) {
            $mixError = '至少需要上传 2 条分轨后，才能生成免费 MP3 Mix。';
        } else {
            try {
                $trackData = starwaves_mix_build_track_data($uploadedTracks);
                $previewResp = starwaves_mix_request('/mixpreview', [
                    'multitrackData' => [
                        'trackData' => $trackData,
                        'musicalStyle' => 'POP',
                        'returnStems' => false,
                        'sampleRate' => '44100',
                        'webhookURL' => STARWAVES_MIX_WEBHOOK,
                    ],
                ]);
                if (($previewResp['status'] ?? 0) !== 200 || empty($previewResp['json']['multitrack_task_id'])) {
                    throw new RuntimeException('免费 MP3 Mix 任务创建失败。');
                }
                $previewTaskId = (string) $previewResp['json']['multitrack_task_id'];
                $previewUrl = starwaves_mix_poll_preview($previewTaskId);
                if ($previewUrl) {
                    $mixMessage = '免费 MP3 Mix 已生成，可以直接试听。';
                } else {
                    $mixError = '免费 MP3 Mix 任务已提交，但当前还没拿到结果。你可以稍后再试一次。';
                }
            } catch (Throwable $e) {
                $mixError = $e->getMessage();
            }
        }
    }

    if ($action === 'submit_mix_job') {
        if (!$isLoggedIn) {
            $mixError = '请先登录后再提交混音任务。';
        } elseif (count($uploadedTracks) < 2) {
            $mixError = '至少先上传 2 条有效分轨，再提交混音任务。';
        } else {
            $mixMode = ($_POST['mix_mode'] ?? 'software') === 'hardware' ? 'hardware' : 'software';
            $projectName = trim((string) ($_POST['project_name'] ?? ''));
            $artistName = trim((string) ($_POST['artist_name'] ?? ''));
            $songStyle = trim((string) ($_POST['song_style'] ?? 'POP'));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $chargeKey = $mixMode === 'hardware' ? 'mix_hardware' : 'mix_software';
            $chargeLabel = $mixMode === 'hardware' ? '硬件混音' : '软件混音';

            try {
                $pdo = getPdo();
                $chargeResult = chargeUserCredits($pdo, (int) $_SESSION['user_id'], $chargeKey, $chargeLabel);
                $status = $mixMode === 'hardware' ? 'awaiting_manual' : 'queued';
                $previewLink = null;
                $mixFileLink = null;

                $previewArchivePath = null;
                $mixFileArchivePath = null;
                if ($mixMode === 'software') {
                    $trackData = starwaves_mix_build_track_data($uploadedTracks);
                    $previewResp = starwaves_mix_request('/mixpreview', [
                        'multitrackData' => [
                            'trackData' => $trackData,
                            'musicalStyle' => strtoupper(str_replace([' / ', ' '], ['_', '_'], $songStyle !== '' ? $songStyle : 'POP')),
                            'returnStems' => false,
                            'sampleRate' => '44100',
                            'webhookURL' => STARWAVES_MIX_WEBHOOK,
                        ],
                    ]);
                    if (($previewResp['status'] ?? 0) !== 200 || empty($previewResp['json']['multitrack_task_id'])) {
                        throw new RuntimeException('软件混音任务创建失败。');
                    }
                    $previewTaskId = (string) $previewResp['json']['multitrack_task_id'];
                    $previewLink = starwaves_mix_poll_preview($previewTaskId, 12, 5);
                    $status = $previewLink ? 'preview_ready' : 'processing';
                }

                $insert = $pdo->prepare('INSERT INTO mix_jobs (user_id, mix_mode, project_name, artist_name, song_style, notes, track_count, status, charged_credits, preview_url, mix_file_url, preview_archive_path, mix_file_archive_path, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
                $insert->execute([
                    (int) $_SESSION['user_id'],
                    $mixMode,
                    $projectName !== '' ? $projectName : '未命名混音工程',
                    $artistName,
                    $songStyle,
                    $notes,
                    count($uploadedTracks),
                    $status,
                    (int) ($chargeResult['charged'] ?? 0),
                    $previewLink,
                    $mixFileLink,
                    $previewArchivePath,
                    $mixFileArchivePath,
                ]);
                $jobId = (int) $pdo->lastInsertId();
                if ($previewLink) {
                    $previewArchivePath = uploadRemoteFileToNetdisk($previewLink, '/工作/starwaves/starwaves mix music', 'mix-preview-' . $jobId . '.mp3');
                    if ($previewArchivePath) {
                        $previewLink = 'backend/netdisk_mix_stream.php?id=' . $jobId . '&variant=preview';
                        $pdo->prepare('UPDATE mix_jobs SET preview_url = ?, preview_archive_path = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                            ->execute([$previewLink, $previewArchivePath, $jobId]);
                    }
                }
                $mixMessage = ($mixMode === 'hardware' ? '硬件混音' : '软件混音') . '任务已提交，已扣除 ' . (int) ($chargeResult['charged'] ?? 0) . ' 积分。';
                if ($previewLink) {
                    $mixMessage .= ' 试听结果也已经拿到，并已回传百度网盘。';
                }
                $jobStmt = $pdo->prepare('SELECT id, mix_mode, project_name, artist_name, song_style, track_count, status, charged_credits, preview_url, mix_file_url, created_at FROM mix_jobs WHERE user_id = ? ORDER BY created_at DESC LIMIT 6');
                $jobStmt->execute([(int) $_SESSION['user_id']]);
                $recentMixJobs = $jobStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $mixError = $e->getMessage();
            }
        }
    }
}

$vocalCount = count(array_filter($uploadedTracks, static fn($t) => ($t['channel'] ?? '') === 'vocal'));
$musicCount = count(array_filter($uploadedTracks, static fn($t) => ($t['channel'] ?? '') === 'music'));
$totalCount = count($uploadedTracks);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <title>Starwaves Mix | 星浪音乐</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta name="description" content="星浪音乐混音工程页，支持新建工程、上传分轨、自动混音和人工混音双通道入口。" />
    <meta name="keywords" content="星浪音乐,Starwaves Mix,混音,分轨上传,自动混音,人工混音" />
    <link href="<?php echo htmlspecialchars(siteAssetUrl('css/bootstrap.css')); ?>" rel="stylesheet" type="text/css" media="all" />
    <link href="<?php echo htmlspecialchars(siteAssetUrl('css/style.css')); ?>" rel="stylesheet" type="text/css" media="all" />
    <link href="<?php echo htmlspecialchars(siteAssetUrl('css/starwaves.css')); ?>" rel="stylesheet" type="text/css" media="all" />
    <link href="<?php echo htmlspecialchars(siteAssetUrl('css/font-awesome.css')); ?>" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Raleway:400,600,700,800" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:400,600,700" rel="stylesheet">
    <style>
        :root {
            --mix-page-bg: #f6efe6;
            --mix-card: rgba(255, 255, 255, 0.9);
            --mix-card-strong: #ffffff;
            --mix-ink: #1d1914;
            --mix-muted: #685e56;
            --mix-line: rgba(61, 42, 26, 0.12);
            --mix-accent: #d89c4a;
            --mix-accent-dark: #b8731a;
            --mix-shadow: 0 22px 60px rgba(82, 58, 29, 0.12);
            --mix-deep-shadow: 0 32px 80px rgba(44, 28, 12, 0.18);
        }

        body {
            background:
                radial-gradient(circle at top right, rgba(216, 156, 74, 0.18), transparent 26%),
                linear-gradient(180deg, #fbf7f1 0%, var(--mix-page-bg) 58%, #f0e4d3 100%);
            color: var(--mix-ink);
        }

        .mix-page-wrap {
            padding-top: 138px;
            padding-bottom: 84px;
        }

        .mix-shell {
            max-width: 1220px;
            margin: 0 auto;
        }

        .mix-hero {
            position: relative;
            overflow: hidden;
            border-radius: 34px;
            padding: 46px;
            background:
                radial-gradient(circle at 15% 20%, rgba(255,255,255,0.08), transparent 24%),
                linear-gradient(135deg, rgba(19, 17, 15, 0.96), rgba(73, 46, 19, 0.9));
            box-shadow: var(--mix-deep-shadow);
            color: #fff;
        }

        .mix-hero:before,
        .mix-hero:after {
            content: "";
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            pointer-events: none;
        }

        .mix-hero:before {
            width: 280px;
            height: 280px;
            top: -130px;
            right: -80px;
        }

        .mix-hero:after {
            width: 180px;
            height: 180px;
            bottom: -70px;
            right: 140px;
        }

        .mix-hero-grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(300px, 0.9fr);
            gap: 26px;
            align-items: stretch;
        }

        .mix-kicker {
            display: inline-block;
            margin-bottom: 16px;
            padding: 7px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            font-size: 12px;
            letter-spacing: .16em;
            text-transform: uppercase;
        }

        .mix-hero h1 {
            margin: 0 0 16px;
            font-size: 46px;
            line-height: 1.12;
            color: #fff;
            font-weight: 800;
        }

        .mix-hero p {
            margin: 0;
            max-width: 760px;
            color: rgba(255, 255, 255, 0.84);
            font-size: 17px;
            line-height: 1.9;
        }

        .mix-hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 24px;
        }

        .mix-hero-ghost {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.16);
            box-shadow: none;
        }

        .mix-hero-strip {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }

        .mix-hero-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: rgba(255, 255, 255, 0.88);
            font-size: 13px;
            line-height: 1;
        }

        .mix-hero-pill i {
            color: #f3c57f;
        }

        .mix-hero-points {
            margin-top: 28px;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .mix-hero-point {
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.08);
            border-radius: 22px;
            padding: 16px 18px;
        }

        .mix-hero-point strong {
            display: block;
            font-size: 28px;
            line-height: 1;
            color: #fff;
            margin-bottom: 8px;
        }

        .mix-hero-point span {
            color: rgba(255, 255, 255, 0.74);
            font-size: 13px;
            line-height: 1.7;
        }

        .mix-side-card {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 28px;
            padding: 26px;
            backdrop-filter: blur(8px);
        }

        .mix-console {
            margin-top: 18px;
            border-radius: 22px;
            padding: 16px;
            background: rgba(11, 10, 9, 0.48);
            border: 1px solid rgba(255,255,255,0.08);
        }

        .mix-console-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .mix-console-title {
            color: rgba(255,255,255,0.88);
            font-size: 13px;
            letter-spacing: .1em;
            text-transform: uppercase;
            font-weight: 700;
        }

        .mix-console-dots {
            display: flex;
            gap: 6px;
        }

        .mix-console-dots span {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: rgba(255,255,255,0.28);
        }

        .mix-console-line {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }

        .mix-console-line:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .mix-console-key {
            color: rgba(255,255,255,0.66);
            font-size: 13px;
        }

        .mix-console-value {
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            text-align: right;
        }

        .mix-side-card h3 {
            margin: 0 0 12px;
            color: #fff;
            font-size: 24px;
            font-weight: 800;
        }

        .mix-side-card p {
            color: rgba(255, 255, 255, 0.76);
            margin: 0 0 18px;
            line-height: 1.85;
            font-size: 15px;
        }

        .mix-checklist {
            padding: 0;
            margin: 0;
            list-style: none;
        }

        .mix-checklist li {
            position: relative;
            padding-left: 24px;
            color: #fff;
            margin-bottom: 12px;
            line-height: 1.7;
        }

        .mix-checklist li:before {
            content: "";
            position: absolute;
            left: 0;
            top: 8px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: linear-gradient(135deg, #f5c677, #d89c4a);
        }

        .mix-section {
            margin-top: 26px;
            border-radius: 30px;
            background: var(--mix-card);
            border: 1px solid var(--mix-line);
            box-shadow: var(--mix-shadow);
            padding: 34px;
        }

        .mix-section-head {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 22px;
        }

        .mix-section-head h2 {
            margin: 0;
            font-size: 32px;
            color: var(--mix-ink);
            font-weight: 800;
        }

        .mix-section-head p {
            margin: 10px 0 0;
            color: var(--mix-muted);
            line-height: 1.85;
            max-width: 720px;
        }

        .mix-project-layout {
            display: grid;
            grid-template-columns: minmax(280px, 0.82fr) minmax(0, 1.18fr);
            gap: 20px;
            align-items: start;
        }

        .mix-project-aside {
            border: 1px solid var(--mix-line);
            border-radius: 28px;
            padding: 24px;
            background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(244,233,219,0.78));
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.72);
        }

        .mix-project-aside h3 {
            margin: 0 0 10px;
            font-size: 24px;
            font-weight: 800;
            color: var(--mix-ink);
        }

        .mix-project-aside p {
            margin: 0;
            color: var(--mix-muted);
            line-height: 1.85;
        }

        .mix-project-stack {
            display: grid;
            gap: 14px;
            margin-top: 20px;
        }

        .mix-project-chip {
            border-radius: 20px;
            padding: 16px 18px;
            background: rgba(255,255,255,0.84);
            border: 1px solid rgba(70, 49, 30, 0.08);
        }

        .mix-project-chip strong {
            display: block;
            font-size: 15px;
            color: var(--mix-ink);
            margin-bottom: 6px;
        }

        .mix-project-chip span {
            display: block;
            color: var(--mix-muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .mix-project-tip {
            margin-top: 18px;
            padding: 14px 16px;
            border-radius: 18px;
            background: rgba(216, 156, 74, 0.1);
            color: var(--mix-ink);
            font-size: 13px;
            line-height: 1.75;
        }

        .mix-form-shell {
            border: 1px solid rgba(70, 49, 30, 0.08);
            border-radius: 28px;
            padding: 22px;
            background: rgba(255,255,255,0.74);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.7);
        }

        .mix-badge {
            display: inline-block;
            padding: 7px 12px;
            border-radius: 999px;
            background: rgba(216, 156, 74, 0.14);
            color: var(--mix-accent-dark);
            font-size: 12px;
            letter-spacing: .14em;
            text-transform: uppercase;
            font-weight: 700;
        }

        .mix-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .mix-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .mix-field-full {
            grid-column: 1 / -1;
        }

        .mix-field-hint {
            color: var(--mix-muted);
            font-size: 12px;
            line-height: 1.7;
        }

        .mix-auto-detect-box {
            margin-top: 18px;
            border-radius: 22px;
            padding: 16px 18px;
            background: linear-gradient(180deg, rgba(216,156,74,0.12), rgba(216,156,74,0.06));
            border: 1px solid rgba(216, 156, 74, 0.18);
        }

        .mix-auto-detect-box strong {
            display: block;
            margin-bottom: 6px;
            color: var(--mix-ink);
            font-size: 15px;
        }

        .mix-auto-detect-box p {
            margin: 0;
            color: var(--mix-muted);
            font-size: 13px;
            line-height: 1.8;
        }

        .mix-flash {
            margin-top: 18px;
            border-radius: 18px;
            padding: 16px 18px;
            font-size: 14px;
            line-height: 1.8;
        }

        .mix-flash-success {
            background: rgba(69, 134, 78, 0.12);
            color: #2f6c39;
            border: 1px solid rgba(69, 134, 78, 0.18);
        }

        .mix-flash-error {
            background: rgba(180, 61, 36, 0.12);
            color: #7c2f22;
            border: 1px solid rgba(180, 61, 36, 0.16);
        }

        .mix-field label {
            font-size: 14px;
            font-weight: 700;
            color: var(--mix-ink);
        }

        .mix-input,
        .mix-select,
        .mix-textarea {
            width: 100%;
            border: 1px solid rgba(70, 49, 30, 0.12);
            border-radius: 18px;
            background: #fff;
            color: var(--mix-ink);
            padding: 15px 16px;
            font-size: 15px;
            transition: border-color .2s ease, box-shadow .2s ease;
        }

        .mix-input:focus,
        .mix-select:focus,
        .mix-textarea:focus {
            border-color: rgba(216, 156, 74, 0.7);
            box-shadow: 0 0 0 4px rgba(216, 156, 74, 0.14);
            outline: none;
        }

        .mix-textarea {
            min-height: 140px;
            resize: vertical;
        }

        .mix-upload-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(280px, 0.9fr);
            gap: 20px;
            margin-top: 20px;
        }

        .mix-stem-split {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            margin-top: 18px;
        }

        .mix-upload-box,
        .mix-note-card,
        .mix-channel-card,
        .mix-flow-card,
        .mix-stem-card {
            border: 1px solid var(--mix-line);
            border-radius: 26px;
            background: var(--mix-card-strong);
            padding: 24px;
        }

        .mix-upload-box {
            border-style: dashed;
            background:
                radial-gradient(circle at top, rgba(216,156,74,0.12), transparent 35%),
                linear-gradient(180deg, rgba(255,255,255,1), rgba(246,239,230,0.78));
            min-height: 220px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
        }

        .mix-stem-card {
            position: relative;
            overflow: hidden;
        }

        .mix-stem-card:before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 5px;
            background: linear-gradient(135deg, #f0c47d, #d89c4a);
        }

        .mix-stem-card--music:before {
            background: linear-gradient(135deg, #d7b495, #9d6122);
        }

        .mix-stem-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }

        .mix-stem-title {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 22px;
            font-weight: 800;
            color: var(--mix-ink);
        }

        .mix-stem-title i {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(216, 156, 74, 0.14);
            color: var(--mix-accent-dark);
            font-size: 18px;
        }

        .mix-stem-card--music .mix-stem-title i {
            background: rgba(184, 115, 26, 0.12);
            color: #8e5618;
        }

        .mix-stem-rule {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            border-radius: 999px;
            background: rgba(29, 25, 20, 0.06);
            color: var(--mix-ink);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .mix-stem-card p {
            color: var(--mix-muted);
            line-height: 1.85;
            margin: 0 0 16px;
        }

        .mix-stem-list {
            list-style: none;
            padding: 0;
            margin: 0 0 18px;
        }

        .mix-stem-list li {
            position: relative;
            padding-left: 22px;
            margin-bottom: 10px;
            color: var(--mix-ink);
            line-height: 1.75;
        }

        .mix-stem-list li:before {
            content: "";
            position: absolute;
            left: 0;
            top: 9px;
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: var(--mix-accent);
        }

        .mix-stem-card--music .mix-stem-list li:before {
            background: #9d6122;
        }

        .mix-hidden-input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
            width: 1px;
            height: 1px;
        }

        .mix-validation-box {
            margin-top: 14px;
            padding: 14px 16px;
            border-radius: 18px;
            background: rgba(29, 25, 20, 0.05);
            color: var(--mix-muted);
            font-size: 13px;
            line-height: 1.75;
        }

        .mix-route-box {
            margin-top: 16px;
            border: 1px solid rgba(70, 49, 30, 0.08);
            border-radius: 20px;
            padding: 16px;
            background: linear-gradient(180deg, rgba(255,255,255,0.9), rgba(246,239,230,0.72));
        }

        .mix-track-adder {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            border-radius: 14px;
            border: 1px dashed rgba(216, 156, 74, 0.5);
            background: rgba(216, 156, 74, 0.08);
            color: var(--mix-accent-dark);
            font-size: 22px;
            font-weight: 800;
            margin-left: 10px;
        }

        .mix-upload-inline {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 12px;
            flex-wrap: wrap;
        }

        .mix-upload-inline .mix-input {
            margin: 0;
        }

        .mix-upload-stack {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }

        .mix-subtrack-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 14px;
            margin-bottom: 8px;
        }

        .mix-subtrack-head strong {
            color: var(--mix-ink);
            font-size: 14px;
            font-weight: 800;
        }

        .mix-upload-progress {
            margin-top: 10px;
            display: none;
            gap: 8px;
        }

        .mix-upload-progress.is-visible {
            display: grid;
        }

        .mix-upload-progress-bar {
            height: 10px;
            border-radius: 999px;
            background: rgba(29, 25, 20, 0.08);
            overflow: hidden;
        }

        .mix-upload-progress-fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #d89c4a, #f0bf6d);
            transition: width .18s ease;
        }

        .mix-upload-progress-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            color: var(--mix-muted);
            font-size: 12px;
        }

        .mix-file-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px;
            align-items: center;
        }

        .mix-file-row .mix-input {
            margin: 0;
        }

        .mix-file-remove {
            width: 42px;
            height: 42px;
            border: 0;
            border-radius: 14px;
            background: rgba(29, 25, 20, 0.08);
            color: var(--mix-muted);
            font-size: 18px;
            font-weight: 700;
        }

        .mix-route-box h4 {
            margin: 0 0 8px;
            font-size: 18px;
            color: var(--mix-ink);
            font-weight: 800;
        }

        .mix-route-box p {
            margin: 0 0 14px;
            color: var(--mix-muted);
            line-height: 1.8;
            font-size: 14px;
        }

        .mix-route-note {
            margin-top: 12px;
            color: var(--mix-muted);
            font-size: 12px;
            line-height: 1.7;
        }

        .mix-validation-box strong {
            display: block;
            color: var(--mix-ink);
            font-size: 14px;
            margin-bottom: 4px;
        }

        .mix-validation-box.is-error {
            background: rgba(180, 61, 36, 0.12);
            color: #7c2f22;
        }

        .mix-validation-box.is-ok {
            background: rgba(69, 134, 78, 0.12);
            color: #2f6c39;
        }

        .mix-upload-icon {
            width: 72px;
            height: 72px;
            border-radius: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(216, 156, 74, 0.18), rgba(216, 156, 74, 0.08));
            color: var(--mix-accent-dark);
            font-size: 30px;
            margin-bottom: 16px;
        }

        .mix-upload-box h3,
        .mix-note-card h3,
        .mix-channel-card h3,
        .mix-flow-card h3 {
            margin: 0 0 10px;
            font-size: 24px;
            color: var(--mix-ink);
            font-weight: 800;
        }

        .mix-upload-box p,
        .mix-note-card p,
        .mix-channel-card p,
        .mix-flow-card p {
            margin: 0;
            color: var(--mix-muted);
            line-height: 1.85;
        }

        .mix-track-list {
            list-style: none;
            padding: 0;
            margin: 18px 0 0;
        }

        .mix-track-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 16px;
            background: rgba(246, 239, 230, 0.9);
            color: var(--mix-ink);
            margin-bottom: 10px;
            font-size: 14px;
        }

        .mix-track-list small {
            color: var(--mix-muted);
        }

        .mix-track-topline {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 16px;
            margin-bottom: 14px;
        }

        .mix-track-count {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(216, 156, 74, 0.12);
            color: var(--mix-accent-dark);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .mix-track-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 14px;
        }

        .mix-track-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 12px;
            border-radius: 999px;
            background: #fff;
            border: 1px solid rgba(70, 49, 30, 0.08);
            color: var(--mix-ink);
            font-size: 12px;
            font-weight: 700;
        }

        .mix-channel-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .mix-channel-card {
            position: relative;
            overflow: hidden;
            transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
        }

        .mix-channel-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 26px 50px rgba(72, 50, 23, 0.12);
            border-color: rgba(216, 156, 74, 0.22);
        }

        .mix-channel-card:before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 5px;
            background: linear-gradient(135deg, #edc27a, #d89c4a);
        }

        .mix-channel-card:after {
            content: "";
            position: absolute;
            inset: auto -40px -40px auto;
            width: 140px;
            height: 140px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(216,156,74,0.12), transparent 70%);
            pointer-events: none;
        }

        .mix-channel-card--manual:before {
            background: linear-gradient(135deg, #d7b495, #b8731a);
        }

        .mix-channel-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .mix-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 12px;
            border-radius: 999px;
            background: rgba(216, 156, 74, 0.14);
            color: var(--mix-accent-dark);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .12em;
        }

        .mix-channel-card--manual .mix-tag {
            background: rgba(184, 115, 26, 0.12);
            color: #905611;
        }

        .mix-price {
            font-size: 14px;
            color: var(--mix-muted);
            font-weight: 700;
        }

        .mix-channel-list {
            list-style: none;
            padding: 0;
            margin: 18px 0 20px;
        }

        .mix-channel-list li {
            position: relative;
            padding-left: 22px;
            margin-bottom: 12px;
            color: var(--mix-ink);
            line-height: 1.75;
        }

        .mix-channel-list li:before {
            content: "";
            position: absolute;
            left: 0;
            top: 9px;
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: var(--mix-accent);
        }

        .mix-btn-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .mix-button {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            min-height: 48px;
            padding: 0 20px;
            border-radius: 999px;
            border: 0;
            cursor: pointer;
            font-size: 15px;
            font-weight: 700;
            transition: transform .2s ease, box-shadow .2s ease, opacity .2s ease;
            text-decoration: none;
        }

        .mix-button:hover {
            transform: translateY(-2px);
            text-decoration: none;
        }

        .mix-button-primary {
            background: linear-gradient(135deg, #f0c47d, var(--mix-accent));
            color: #281a10;
            box-shadow: 0 15px 24px rgba(216, 156, 74, 0.22);
        }

        .mix-button-secondary {
            background: #fff;
            color: var(--mix-ink);
            border: 1px solid rgba(70, 49, 30, 0.12);
        }

        .mix-button-manual {
            background: linear-gradient(135deg, #d5b293, #b8731a);
            color: #fff;
            box-shadow: 0 15px 24px rgba(184, 115, 26, 0.24);
        }

        .mix-status {
            margin-top: 16px;
            padding: 14px 16px;
            border-radius: 18px;
            background: linear-gradient(180deg, rgba(216, 156, 74, 0.1), rgba(216, 156, 74, 0.06));
            color: var(--mix-ink);
            font-size: 14px;
            line-height: 1.7;
        }

        .mix-status strong {
            display: block;
            margin-bottom: 4px;
            font-size: 14px;
        }

        .mix-flow-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-top: 18px;
            position: relative;
        }

        .mix-flow-card {
            min-height: 220px;
            position: relative;
            background: linear-gradient(180deg, rgba(255,255,255,1), rgba(248,242,234,0.88));
            box-shadow: 0 18px 34px rgba(78, 55, 29, 0.08);
        }

        .mix-flow-card:after {
            content: '';
            position: absolute;
            top: 40px;
            right: -10px;
            width: 20px;
            height: 2px;
            background: rgba(184, 115, 26, 0.28);
        }

        .mix-flow-card:last-child:after {
            display: none;
        }

        .mix-flow-no {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            background: linear-gradient(135deg, rgba(216, 156, 74, 0.18), rgba(216, 156, 74, 0.08));
            color: var(--mix-accent-dark);
            font-size: 20px;
            font-weight: 800;
        }

        .mix-mini-note {
            margin-top: 16px;
            color: var(--mix-muted);
            font-size: 13px;
            line-height: 1.8;
        }

        @media (max-width: 1199px) {
            .mix-hero-grid,
            .mix-upload-grid,
            .mix-flow-grid,
            .mix-project-layout,
            .mix-stem-split {
                grid-template-columns: 1fr;
            }

            .mix-flow-card:after {
                display: none;
            }

            .mix-hero-point strong {
                font-size: 24px;
            }
        }

        @media (max-width: 991px) {
            .mix-page-wrap {
                padding-top: 112px;
                padding-bottom: 60px;
            }

            .mix-hero,
            .mix-section {
                padding: 24px 20px;
                border-radius: 24px;
            }

            .mix-hero h1 {
                font-size: 34px;
            }

            .mix-hero-points,
            .mix-form-grid,
            .mix-channel-grid {
                grid-template-columns: 1fr;
            }

            .mix-form-shell {
                padding: 18px;
            }

            .mix-section-head {
                display: block;
            }
        }

        @media (max-width: 767px) {
            .mix-page-wrap {
                padding-top: 98px;
            }

            .mix-hero {
                padding: 22px 16px;
            }

            .mix-hero h1 {
                font-size: 28px;
                line-height: 1.2;
            }

            .mix-hero p,
            .mix-section-head p,
            .mix-upload-box p,
            .mix-note-card p,
            .mix-channel-card p,
            .mix-flow-card p {
                font-size: 14px;
                line-height: 1.8;
            }

            .mix-section {
                padding: 20px 16px;
            }

            .mix-section-head h2 {
                font-size: 25px;
            }

            .mix-upload-box,
            .mix-note-card,
            .mix-channel-card,
            .mix-flow-card {
                padding: 18px 16px;
                border-radius: 22px;
            }

            .mix-button {
                width: 100%;
            }

            .mix-hero-actions {
                flex-direction: column;
            }

            .mix-hero-strip {
                gap: 8px;
            }

            .mix-btn-row {
                flex-direction: column;
            }

            .mix-track-list li {
                display: block;
            }

            .mix-track-list small {
                display: block;
                margin-top: 6px;}
    </style>
</head>
<body>
    <div class="main_section_agile site-shell" id="home">
        <div class="agileits_w3layouts_banner_nav">
            <nav class="navbar navbar-default">
                <div class="navbar-header navbar-left">
                    <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#bs-example-navbar-collapse-1">
                        <span class="sr-only">切换导航</span>
                        <span class="icon-bar"></span>
                        <span class="icon-bar"></span>
                        <span class="icon-bar"></span>
                    </button>
                    <h1>
                        <a class="navbar-brand starwaves-brand" href="index.php">
                            <img src="<?php echo htmlspecialchars(siteAssetUrl('images/starwaves-logo.svg')); ?>" alt="星浪音乐" class="brand-mark" />
                            <span class="brand-text-wrap"><strong>星浪音乐</strong><em>STARWAVES MUSIC</em></span>
                        </a>
                    </h1>
                </div>
                <div class="collapse navbar-collapse navbar-right" id="bs-example-navbar-collapse-1">
                    <nav class="menu-hover-effect menu-hover-effect-4">
                        <ul class="nav navbar-nav">
                            <li><a href="index.php" class="hvr-ripple-in">首页</a></li>
                            <li><a href="star-ai.php" class="hvr-ripple-in">STAR.AI</a></li>
                            <li><a href="top_songs.php" class="hvr-ripple-in">STAR TOP音乐榜</a></li>
                            <li class="active"><a href="starwaves-mix.php" class="hvr-ripple-in">混音</a></li>
                            <li><a href="starwaves-master.php" class="hvr-ripple-in">母带</a></li>
                        </ul>
                    </nav>
                    <?php if ($isLoggedIn && $currentUser): ?>
                        <?php $navAvatar = resolveAvatarUrl(!empty($currentUser['avatar_path']) ? 'backend/' . ltrim($currentUser['avatar_path'], '/') : 'images/starwaves-logo.svg'); ?>
                        <a class="site-user-chip" href="backend/admin.php">
                            <img src="<?php echo htmlspecialchars($navAvatar); ?>" alt="avatar">
                            <span><?php echo htmlspecialchars($currentUser['full_name'] ?: $currentUser['username']); ?></span>
                        </a>
                    <?php else: ?>
                        <div class="site-entry-actions site-entry-actions-single">
                            <a class="site-login-pill" href="backend/login.php">登录</a>
                        </div>
                    <?php endif; ?>
                </div>
            </nav>
            <div class="clearfix"></div>
        </div>
    </div>

    <div class="container mix-page-wrap">
        <div class="mix-shell">
            <section class="mix-hero">
                <div class="mix-hero-grid">
                    <div>
                        <span class="mix-kicker">Starwaves Mix / Engineering Flow</span>
                        <h1>新建工程，上传分轨，再决定走自动混音还是人工混音。</h1>
                        <p>这一页不是静态介绍，而是星浪音乐的混音工程入口。你可以先新建工程、补全曲目信息、上传分轨，然后一键进入自动混音通道；如果项目要更细的审美判断和人工处理，也可以直接切到人工混音通道，后续后台任务接口再接上就行。</p>
                        <div class="mix-hero-actions">
                            <a class="mix-button mix-button-primary" href="#new-project"><i class="fa fa-plus-circle" aria-hidden="true"></i> 先建一个工程</a>
                            <a class="mix-button mix-hero-ghost" href="#mix-channels"><i class="fa fa-random" aria-hidden="true"></i> 直接看双通道</a>
                        </div>
                        <div class="mix-hero-strip">
                            <span class="mix-hero-pill"><i class="fa fa-folder-open-o" aria-hidden="true"></i> 分轨工程入口</span>
                            <span class="mix-hero-pill"><i class="fa fa-bolt" aria-hidden="true"></i> 自动混音预留</span>
                            <span class="mix-hero-pill"><i class="fa fa-headphones" aria-hidden="true"></i> 人工混音任务位</span>
                        </div>
                        <div class="mix-hero-points">
                            <div class="mix-hero-point">
                                <strong>01</strong>
                                <span>新建工程并整理歌曲信息</span>
                            </div>
                            <div class="mix-hero-point">
                                <strong>02</strong>
                                <span>上传分轨并确认工程素材</span>
                            </div>
                            <div class="mix-hero-point">
                                <strong>03</strong>
                                <span>选择自动或人工混音通道</span>
                            </div>
                        </div>
                    </div>
                    <aside class="mix-side-card">
                        <h3>这一页先做好的能力</h3>
                        <p>先把混音工程入口、上传区域、自动通道按钮和人工通道预留位搭好。后续不管接自动引擎还是后台派单，都有落点。</p>
                        <ul class="mix-checklist">
                            <li>顶部风格与站内现有页面保持统一</li>
                            <li>工程表单与上传区都能在手机端自然折叠</li>
                            <li>人工混音按钮预留任务入口，后续再接后台</li>
                            <li>不改动别的页面，只处理这一页</li>
                        </ul>
                        <div class="mix-console">
                            <div class="mix-console-top">
                                <span class="mix-console-title">Session Preview</span>
                                <div class="mix-console-dots"><span></span><span></span><span></span></div>
                            </div>
                            <div class="mix-console-line">
                                <span class="mix-console-key">Project</span>
                                <span class="mix-console-value">Starwaves Mix / V1</span>
                            </div>
                            <div class="mix-console-line">
                                <span class="mix-console-key">Input</span>
                                <span class="mix-console-value">Stems + Notes</span>
                            </div>
                            <div class="mix-console-line">
                                <span class="mix-console-key">Output</span>
                                <span class="mix-console-value">Auto / Manual Channel</span>
                            </div>
                        </div>
                    </aside>
                </div>
                    </div>
                </div>
            </section>

            <?php if ($mixMessage): ?>
                <div class="mix-flash mix-flash-success"><?php echo htmlspecialchars($mixMessage); ?><?php if ($previewUrl): ?> <a href="<?php echo htmlspecialchars($previewUrl); ?>" target="_blank" rel="noopener">打开 MP3 试听</a><?php endif; ?></div>
            <?php endif; ?>
            <?php if ($mixError): ?>
                <div class="mix-flash mix-flash-error"><?php echo htmlspecialchars($mixError); ?></div>
            <?php endif; ?>

            <section class="mix-section" id="new-project">
                <div class="mix-section-head">
                    <div>
                        <span class="mix-badge">New Project</span>
                        <h2>新建工程</h2>
                        <p>先把这首歌的基础信息建出来。后面不管走自动混音还是人工混音，都从这个工程继续。</p>
                    </div>
                </div>
                <div class="mix-project-layout">
                    <aside class="mix-project-aside">
                        <h3>一个好工程，应该一开始就说清楚</h3>
                        <p>现在这块我觉得比原来好看，也更实用。左边不再只是装饰，而是先把用户脑子里的事理顺：这是给谁做、做到什么程度、先走哪条路径。</p>
                        <div class="mix-project-stack">
                            <div class="mix-project-chip">
                                <strong>先交代歌的用途</strong>
                                <span>是正式发行、内容分发、提案样带，还是先做内部试听，决定后面走自动还是人工。</span>
                            </div>
                            <div class="mix-project-chip">
                                <strong>再说明想要的听感</strong>
                                <span>比如人声更靠前、低频更稳、鼓组更软、更像欧美流行还是华语抒情。</span>
                            </div>
                            <div class="mix-project-chip">
                                <strong>最后再上传分轨</strong>
                                <span>把主唱、和声、鼓组、Bass、Pad、FX 这些素材放进工程，后面才能真正生成版本。</span>
                            </div>
                        </div>
                        <div class="mix-project-tip">我把这一块做成了“工程预设卡”，这样不会一上来全是表单，视觉上更像产品页，也更像真的开工面板。</div>
                    </aside>
                    <div class="mix-form-shell">
                <div class="mix-form-grid">
                    <div class="mix-field">
                        <label for="projectName">工程名称</label>
                        <input id="projectName" name="project_name" class="mix-input" type="text" placeholder="例如：海风写成诗 / Mix V1" value="<?php echo htmlspecialchars((string) ($_POST['project_name'] ?? '')); ?>">
                    </div>
                    <div class="mix-field">
                        <label for="artistName">艺人 / 项目名</label>
                        <input id="artistName" name="artist_name" class="mix-input" type="text" placeholder="填写歌手名、厂牌名或项目代号" value="<?php echo htmlspecialchars((string) ($_POST['artist_name'] ?? '')); ?>">
                    </div>
                    <div class="mix-field">
                        <label for="songStyle">音乐风格</label>
                        <select id="songStyle" name="song_style" class="mix-select">
                            <?php $currentSongStyle = (string) ($_POST['song_style'] ?? '流行 Pop'); ?>
                            <option<?php echo $currentSongStyle === '流行 Pop' ? ' selected' : ''; ?>>流行 Pop</option>
                            <option<?php echo $currentSongStyle === 'R&B' ? ' selected' : ''; ?>>R&B</option>
                            <option<?php echo $currentSongStyle === '电子 / Dance' ? ' selected' : ''; ?>>电子 / Dance</option>
                            <option<?php echo $currentSongStyle === '说唱 / Hip-Hop' ? ' selected' : ''; ?>>说唱 / Hip-Hop</option>
                            <option<?php echo $currentSongStyle === '摇滚 / Band' ? ' selected' : ''; ?>>摇滚 / Band</option>
                            <option<?php echo $currentSongStyle === '影视 / 氛围' ? ' selected' : ''; ?>>影视 / 氛围</option>
                            <option<?php echo $currentSongStyle === '其他风格' ? ' selected' : ''; ?>>其他风格</option>
                        </select>
                    </div>
                    <div class="mix-field">
                        <label for="mixTarget">工程目标</label>
                        <select id="mixTarget" class="mix-select">
                            <option>正式发行</option>
                            <option>试听样带</option>
                            <option>提案版本</option>
                            <option>短视频内容</option>
                            <option>内部审听</option>
                        </select>
                    </div>
                    <div class="mix-field">
                        <label for="songKey">Key</label>
                        <input id="songKey" class="mix-input" type="text" placeholder="例如：C Major / A minor">
                        <div class="mix-field-hint">可手动填写；如果你留空，保存工程时系统自动识别。</div>
                    </div>
                    <div class="mix-field">
                        <label for="songBpm">BPM</label>
                        <input id="songBpm" class="mix-input" type="number" min="40" max="240" placeholder="例如：128">
                        <div class="mix-field-hint">可手动填写；如果你留空，保存工程时系统自动识别。</div>
                    </div>
                    <div class="mix-field mix-field-full">
                        <label for="mixReference">参考说明</label>
                        <textarea id="mixReference" name="notes" class="mix-textarea" placeholder="可以写参考歌、想要的听感、希望突出的人声、低频或空间感，比如：主唱靠前一点，副歌要更打开，鼓组不要太硬，整体更像温暖的流行唱片。"><?php echo htmlspecialchars((string) ($_POST['notes'] ?? '')); ?></textarea>
                    </div>
                </div>
            </section>

            <section class="mix-section" id="upload-tracks">
                <div class="mix-section-head">
                    <div>
                        <span class="mix-badge">Track Upload</span>
                        <h2>上传分轨</h2>
                        <p>这里先把上传区和工程素材位留好。后面接真实上传逻辑时，直接把这里连到后台即可。</p>
                    </div>
                </div>
                <div class="mix-upload-grid">
                    <div>
                        <div class="mix-upload-box">
                            <div class="mix-upload-icon"><i class="fa fa-cloud-upload" aria-hidden="true"></i></div>
                            <h3>先按通道把分轨分清楚</h3>
                            <p>这里不再是一个混着传的入口，而是明确拆成两类：人声只能进人声通道，乐器只能进乐器通道。这样后面做自动混音和人工混音时，逻辑会更清楚。</p>
                            <div class="mix-btn-row" style="margin-top:18px; width:100%; max-width:360px;">
                                <button class="mix-button mix-button-primary" type="button"><i class="fa fa-plus-circle" aria-hidden="true"></i> 新增分轨</button>
                                <button class="mix-button mix-button-secondary" type="button"><i class="fa fa-folder-open-o" aria-hidden="true"></i> 选择工程包</button>
                            </div>
                        </div>
                        <div class="mix-stem-split">
                            <article class="mix-stem-card">
                                <div class="mix-stem-top">
                                    <div class="mix-stem-title"><i class="fa fa-microphone" aria-hidden="true"></i> 人声分轨</div>
                                    <span class="mix-stem-rule">仅限 Vocal</span>
                                </div>
                                <p>这个通道只能上传人声类素材，避免和乐器分轨混在一起。你先把主唱、和声、Double、Adlib 这些丢进来。</p>
                                <ul class="mix-stem-list">
                                    <li>主唱 Lead Vocal</li>
                                    <li>和声 Backing Vocals</li>
                                    <li>Double / Adlib / Hook</li>
                                    <li>人声效果轨、和声补轨</li>
                                </ul>
                                <form method="post" enctype="multipart/form-data" class="js-mix-upload-form" data-upload-kind="vocal-main">
                                    <input type="hidden" name="mix_action" value="upload_tracks">
                                    <input type="hidden" name="channel" value="vocal">
                                    <input type="hidden" name="track_role" value="主唱">
                                    <div class="mix-subtrack-head">
                                        <strong>主唱</strong>
                                        <button class="mix-track-adder" type="button" data-add-track="vocal-main">+</button>
                                    </div>
                                    <div class="mix-upload-stack" data-upload-stack="vocal-main">
                                        <div class="mix-file-row">
                                            <input class="mix-input" type="file" name="tracks[]" multiple accept=".wav,.flac,.aif,.aiff,.mp3,.zip,audio/*">
                                            <button class="mix-file-remove" type="button">-</button>
                                        </div>
                                    </div>
                                    <div class="mix-upload-inline">
                                        <button class="mix-button mix-button-primary" type="submit"><i class="fa fa-microphone" aria-hidden="true"></i> 上传主唱分轨</button>
                                    </div>
                                    <div class="mix-upload-progress" data-upload-progress>
                                        <div class="mix-upload-progress-bar"><div class="mix-upload-progress-fill" data-upload-progress-fill></div></div>
                                        <div class="mix-upload-progress-meta"><span data-upload-percent>0%</span><span data-upload-speed>0 KB/s</span><span data-upload-eta>剩余 --</span></div>
                                    </div>
                                </form>
                                <form method="post" enctype="multipart/form-data" class="js-mix-upload-form" data-upload-kind="vocal-harmony">
                                    <input type="hidden" name="mix_action" value="upload_tracks">
                                    <input type="hidden" name="channel" value="vocal">
                                    <input type="hidden" name="track_role" value="和声">
                                    <div class="mix-subtrack-head">
                                        <strong>和声</strong>
                                        <button class="mix-track-adder" type="button" data-add-track="vocal-harmony">+</button>
                                    </div>
                                    <div class="mix-upload-stack" data-upload-stack="vocal-harmony">
                                        <div class="mix-file-row">
                                            <input class="mix-input" type="file" name="tracks[]" multiple accept=".wav,.flac,.aif,.aiff,.mp3,.zip,audio/*">
                                            <button class="mix-file-remove" type="button">-</button>
                                        </div>
                                    </div>
                                    <div class="mix-upload-inline">
                                        <button class="mix-button mix-button-primary" type="submit"><i class="fa fa-microphone" aria-hidden="true"></i> 上传和声分轨</button>
                                    </div>
                                    <div class="mix-upload-progress" data-upload-progress>
                                        <div class="mix-upload-progress-bar"><div class="mix-upload-progress-fill" data-upload-progress-fill></div></div>
                                        <div class="mix-upload-progress-meta"><span data-upload-percent>0%</span><span data-upload-speed>0 KB/s</span><span data-upload-eta>剩余 --</span></div>
                                    </div>
                                </form>
                                <div class="mix-validation-box">
                                    <strong>人声通道校验</strong>
                                    文件名里如果出现 drum、bass、guitar、piano、synth 这类乐器关键词，会直接拦下，不让混传。
                                </div>
                            </article>
                            <article class="mix-stem-card mix-stem-card--music">
                                <div class="mix-stem-top">
                                    <div class="mix-stem-title"><i class="fa fa-music" aria-hidden="true"></i> 乐器分轨</div>
                                    <span class="mix-stem-rule">仅限 Music</span>
                                </div>
                                <p>这个通道只能上传乐器类素材。鼓组、Bass、Pad、Synth、吉他、钢琴、FX 等都走这里，不和人声混传。</p>
                                <ul class="mix-stem-list">
                                    <li>Drums / Percussion / Loop</li>
                                    <li>Bass / 808 / Sub</li>
                                    <li>Piano / Guitar / Synth / Pad</li>
                                    <li>FX / Atmos / Instrument Bus</li>
                                </ul>
                                <form method="post" enctype="multipart/form-data" class="js-mix-upload-form" data-upload-kind="music">
                                    <input type="hidden" name="mix_action" value="upload_tracks">
                                    <input type="hidden" name="channel" value="music">
                                    <input type="hidden" name="track_role" value="乐器">
                                    <div class="mix-subtrack-head">
                                        <strong>乐器轨道</strong>
                                        <button class="mix-track-adder" type="button" data-add-track="music">+</button>
                                    </div>
                                    <div class="mix-upload-stack" data-upload-stack="music">
                                        <div class="mix-file-row">
                                            <input class="mix-input" type="file" name="tracks[]" multiple accept=".wav,.flac,.aif,.aiff,.mp3,.zip,audio/*">
                                            <button class="mix-file-remove" type="button">-</button>
                                        </div>
                                    </div>
                                    <div class="mix-upload-inline">
                                        <button class="mix-button mix-button-manual" type="submit"><i class="fa fa-music" aria-hidden="true"></i> 上传乐器分轨</button>
                                    </div>
                                    <div class="mix-upload-progress" data-upload-progress>
                                        <div class="mix-upload-progress-bar"><div class="mix-upload-progress-fill" data-upload-progress-fill></div></div>
                                        <div class="mix-upload-progress-meta"><span data-upload-percent>0%</span><span data-upload-speed>0 KB/s</span><span data-upload-eta>剩余 --</span></div>
                                    </div>
                                </form>
                                <div class="mix-validation-box">
                                    <strong>乐器通道校验</strong>
                                    文件名里如果出现 vocal、leadvox、backingvox、adlib、hook 这类人声关键词，也会直接拦下。
                                </div>
                            </article>
                        </div>
                        <div class="mix-route-box">
                            <h4>整首工程混音获取通道</h4>
                            <p>这里才是人声分轨和乐器分轨一起进入混音的地方。也就是两边轨道都准备好之后，再从这里拿免费 MP3 预览，或者后面走付费 WAV 成品。</p>
                            <form method="post">
                                <input type="hidden" name="mix_action" value="preview_mix">
                                <div class="mix-btn-row">
                                    <button class="mix-button mix-button-secondary" type="submit"><i class="fa fa-gift" aria-hidden="true"></i> 免费获取 MP3 Mix（32轨以内）</button>
                                    <button class="mix-button mix-button-primary" type="button"><i class="fa fa-diamond" aria-hidden="true"></i> 付费获取 WAV Mix</button>
                                </div>
                            </form>
                            <div class="mix-route-note">免费按钮已经接到 RoEx 预览混音链路；付费按钮暂时还是预留位。这里的混音会把人声和乐器轨道一起处理，不是只处理人声。</div>
                        </div>
                    </div>
                    <div class="mix-note-card">
                        <div class="mix-track-topline">
                            <h3>当前工程素材</h3>
                            <span class="mix-track-count"><i class="fa fa-database" aria-hidden="true"></i> <?php echo (int) $totalCount; ?> tracks loaded</span>
                        </div>
                        <div class="mix-track-pills">
                            <span class="mix-track-pill"><i class="fa fa-microphone" aria-hidden="true"></i> Vocal <?php echo (int) $vocalCount; ?></span>
                            <span class="mix-track-pill"><i class="fa fa-music" aria-hidden="true"></i> Music <?php echo (int) $musicCount; ?></span>
                        </div>
                        <p>这里现在已经接到实际上传状态了。你上传完分轨后，会在这里看到当前工程素材列表。</p>
                        <?php if (empty($uploadedTracks)): ?>
                            <div class="mix-mini-note">现在还没有上传任何分轨。先上传至少 2 条有效轨道，再点免费 MP3 Mix 测试。</div>
                        <?php else: ?>
                            <ul class="mix-track-list">
                                <?php foreach ($uploadedTracks as $track): ?>
                                    <li><span><?php echo htmlspecialchars($track['original_name']); ?></span><small><?php echo ($track['channel'] === 'vocal' ? (($track['role'] ?? '') !== '' ? $track['role'] . '分轨' : '人声分轨') : '乐器分轨'); ?> / <?php echo htmlspecialchars($track['mime_type']); ?> / <?php echo round(((int) $track['size']) / 1024 / 1024, 2); ?> MB</small></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <div class="mix-mini-note">上传支持鼠标一次选择多个文件，并会自动拆成多条独立轨道显示；单条音轨上限 100MB，整个工程累计上限 2GB。上传时会显示实时速度和预计剩余时间。</div>
                    </div>
                </div>
            </section>

            <section class="mix-section" id="mix-channels">
                <div class="mix-section-head">
                    <div>
                        <span class="mix-badge">Mix Channels</span>
                        <h2>软件混音 / 硬件混音</h2>
                        <p>上面是软件混音通道，下面是硬件混音通道。软件混音偏效率，硬件混音偏更重的人工与制作链路。</p>
                    </div>
                </div>
                <div class="mix-channel-grid">
                    <article class="mix-channel-card">
                        <div class="mix-channel-meta">
                            <span class="mix-tag"><i class="fa fa-bolt" aria-hidden="true"></i> 软件混音</span>
                            <span class="mix-price">100 积分 / 次</span>
                        </div>
                        <h3>点一下，进入软件混音</h3>
                        <p>这个通道是软件混音入口。当前先把价格、入口和状态位连上，后续再继续接自动混音引擎和结果回传。</p>
                        <ul class="mix-channel-list">
                            <li>适合先快速出一版可听版本</li>
                            <li>适合 demo、短周期内容和内部试听</li>
                            <li>当前规则：软件混音 100 积分 / 次</li>
                        </ul>
                        <form method="post" class="mix-btn-row">
                            <input type="hidden" name="mix_action" value="submit_mix_job">
                            <input type="hidden" name="mix_mode" value="software">
                            <input type="hidden" name="project_name" value="<?php echo htmlspecialchars((string) ($_POST['project_name'] ?? '')); ?>">
                            <input type="hidden" name="artist_name" value="<?php echo htmlspecialchars((string) ($_POST['artist_name'] ?? '')); ?>">
                            <input type="hidden" name="song_style" value="<?php echo htmlspecialchars((string) ($_POST['song_style'] ?? 'POP')); ?>">
                            <input type="hidden" name="notes" value="<?php echo htmlspecialchars((string) ($_POST['notes'] ?? '')); ?>">
                            <button class="mix-button mix-button-primary" type="submit"><i class="fa fa-play-circle" aria-hidden="true"></i> 软件混音（100 积分）</button>
                            <button class="mix-button mix-button-secondary" type="button" onclick="location.hash='new-project'"><i class="fa fa-refresh" aria-hidden="true"></i> 重新填写工程</button>
                        </form>
                        <div class="mix-status">
                            <strong>软件混音状态位</strong>
                            当前先把入口和交互位置留出来。后面接真实逻辑时，这里可以显示：排队中、处理中、混音完成、试听入口、下载入口。
                        </div>
                    </article>

                    <article class="mix-channel-card mix-channel-card--manual">
                        <div class="mix-channel-meta">
                            <span class="mix-tag"><i class="fa fa-user-circle-o" aria-hidden="true"></i> 硬件混音</span>
                            <span class="mix-price">4000 积分 / 次</span>
                        </div>
                        <h3>点一下，进入硬件混音通道</h3>
                        <p>这个通道走更重的人工与制作链路，适合正式发行、重点项目和更高审美要求的歌。</p>
                        <ul class="mix-channel-list">
                            <li>适合正式发行、重点项目、审美要求更高的歌</li>
                            <li>适合需要一对一沟通和更细处理的工程</li>
                            <li>当前规则：硬件混音 4000 积分 / 次</li>
                        </ul>
                        <form method="post" class="mix-btn-row">
                            <input type="hidden" name="mix_action" value="submit_mix_job">
                            <input type="hidden" name="mix_mode" value="hardware">
                            <input type="hidden" name="project_name" value="<?php echo htmlspecialchars((string) ($_POST['project_name'] ?? '')); ?>">
                            <input type="hidden" name="artist_name" value="<?php echo htmlspecialchars((string) ($_POST['artist_name'] ?? '')); ?>">
                            <input type="hidden" name="song_style" value="<?php echo htmlspecialchars((string) ($_POST['song_style'] ?? 'POP')); ?>">
                            <input type="hidden" name="notes" value="<?php echo htmlspecialchars((string) ($_POST['notes'] ?? '')); ?>">
                            <button class="mix-button mix-button-manual" type="submit"><i class="fa fa-headphones" aria-hidden="true"></i> 硬件混音（4000 积分）</button>
                            <button class="mix-button mix-button-secondary" type="button" onclick="location.hash='new-project'"><i class="fa fa-sticky-note-o" aria-hidden="true"></i> 回去补备注</button>
                        </form>
                        <div class="mix-status">
                            <strong>硬件混音状态位</strong>
                            这里后续可以直接挂后台提交接口。你现在不用管后台逻辑，我已经把前端任务入口和说明位留好了。
                        </div>
                    </article>
                </div>
            </section>

            <section class="mix-section" id="mix-jobs">
                <div class="mix-section-head">
                    <div>
                        <span class="mix-badge">My Mix Jobs</span>
                        <h2>最近混音任务</h2>
                        <p><?php echo $isLoggedIn ? '这里会显示你最近提交的软件混音 / 硬件混音任务。' : '登录后可以查看自己的混音任务。'; ?></p>
                    </div>
                </div>
                <div class="mix-channel-grid">
                    <?php if ($isLoggedIn && $recentMixJobs): ?>
                        <?php foreach ($recentMixJobs as $job): ?>
                            <article class="mix-channel-card<?php echo ($job['mix_mode'] ?? 'software') === 'hardware' ? ' mix-channel-card--manual' : ''; ?>">
                                <div class="mix-channel-meta">
                                    <span class="mix-tag"><i class="fa <?php echo ($job['mix_mode'] ?? 'software') === 'hardware' ? 'fa-headphones' : 'fa-bolt'; ?>" aria-hidden="true"></i> <?php echo ($job['mix_mode'] ?? 'software') === 'hardware' ? '硬件混音' : '软件混音'; ?></span>
                                    <span class="mix-price"><?php echo (int) ($job['charged_credits'] ?? 0); ?> 积分</span>
                                </div>
                                <h3><?php echo htmlspecialchars((string) ($job['project_name'] ?? '未命名混音工程')); ?></h3>
                                <p>艺人 / 项目：<?php echo htmlspecialchars((string) ($job['artist_name'] ?: '未填写')); ?> ｜ 风格：<?php echo htmlspecialchars((string) ($job['song_style'] ?: 'POP')); ?></p>
                                <ul class="mix-channel-list">
                                    <li>分轨数量：<?php echo (int) ($job['track_count'] ?? 0); ?> 条</li>
                                    <li>状态：<?php echo htmlspecialchars((string) ($job['status'] ?? 'queued')); ?></li>
                                    <li>提交时间：<?php echo htmlspecialchars((string) ($job['created_at'] ?? '')); ?></li>
                                </ul>
                                <div class="mix-status">
                                    <strong>任务说明</strong>
                                    <?php echo ($job['mix_mode'] ?? 'software') === 'hardware' ? '已进入硬件混音人工排队。' : '已进入软件混音队列，后续可继续接自动渲染结果。'; ?>
                                    <?php if (!empty($job['preview_url'])): ?>
                                        <div style="margin-top:10px;"><a class="mix-button mix-button-secondary" href="<?php echo htmlspecialchars((string) $job['preview_url']); ?>" target="_blank" rel="noopener">打开试听</a></div>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <article class="mix-channel-card">
                            <h3>还没有混音任务</h3>
                            <p>先上传分轨，再从上面的软件混音或硬件混音入口提交第一条任务。</p>
                        </article>
                    <?php endif; ?>
                </div>
            </section>

            <section class="mix-section" id="mix-flow">
                <div class="mix-section-head">
                    <div>
                        <span class="mix-badge">Workflow Preview</span>
                        <h2>页面流程一眼看清</h2>
                        <p>这个流程是按你说的业务顺序排的：新建工程 -> 上传分轨 -> 软件混音或硬件混音。手机端会自动从四列变一列，不会挤爆。</p>
                    </div>
                </div>
                <div class="mix-flow-grid">
                    <article class="mix-flow-card">
                        <div class="mix-flow-no">01</div>
                        <h3>新建工程</h3>
                        <p>先收歌名、艺人名、风格、用途和参考说明，把工程基础信息建立起来。</p>
                    </article>
                    <article class="mix-flow-card">
                        <div class="mix-flow-no">02</div>
                        <h3>上传分轨</h3>
                        <p>把主唱、和声、鼓组、Bass 和其他分轨丢进当前工程，作为后续混音的素材池。</p>
                    </article>
                    <article class="mix-flow-card">
                        <div class="mix-flow-no">03</div>
                        <h3>软件混音</h3>
                        <p>需要效率时，直接点击“软件混音”，先进入软件混音队列。</p>
                    </article>
                    <article class="mix-flow-card">
                        <div class="mix-flow-no">04</div>
                        <h3>硬件混音</h3>
                        <p>需要更高级的人工判断时，点击“硬件混音”，把任务抛进后续后台流程。</p>
                    </article>
                </div>
            </section>
        </div>
    </div>

    <script type="text/javascript" src="js/jquery-2.1.4.min.js"></script>
    <script type="text/javascript" src="js/bootstrap-3.1.1.min.js"></script>
    <script>
        (function () {
            var projectName = document.getElementById('projectName');
            if (projectName && !projectName.value) {
                projectName.value = '我的新工程';
            }

            function bindTrackInput(input) {
                if (!input || input.dataset.bound === '1') return;
                input.dataset.bound = '1';
                input.addEventListener('change', function () {
                    if (!input.files || !input.files.length || input.files.length === 1) {
                        return;
                    }
                    var stack = input.closest('[data-upload-stack]');
                    if (!stack) return;
                    var files = Array.prototype.slice.call(input.files);
                    files.forEach(function (file, index) {
                        var targetInput = index === 0 ? input : createTrackInput(stack);
                        var dt = new DataTransfer();
                        dt.items.add(file);
                        targetInput.files = dt.files;
                    });
                });
            }

            function createTrackRow() {
                var row = document.createElement('div');
                row.className = 'mix-file-row';
                var input = document.createElement('input');
                input.className = 'mix-input';
                input.type = 'file';
                input.name = 'tracks[]';
                input.accept = '.wav,.flac,.aif,.aiff,.mp3,.zip,audio/*';
                input.multiple = true;
                bindTrackInput(input);
                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'mix-file-remove';
                removeBtn.textContent = '-';
                removeBtn.addEventListener('click', function () {
                    row.remove();
                });
                row.appendChild(input);
                row.appendChild(removeBtn);
                return row;
            }

            function createTrackInput(stack) {
                var row = createTrackRow();
                stack.appendChild(row);
                return row.querySelector('input[type="file"]');
            }

            function addTrackInput(channel) {
                var stack = document.querySelector('[data-upload-stack="' + channel + '"]');
                if (!stack) return;
                createTrackInput(stack);
            }

            function formatBytesPerSecond(bytes) {
                if (!bytes || bytes <= 0) return '0 KB/s';
                if (bytes >= 1024 * 1024) return (bytes / 1024 / 1024).toFixed(2) + ' MB/s';
                return Math.round(bytes / 1024) + ' KB/s';
            }

            function formatEta(seconds) {
                if (!isFinite(seconds) || seconds < 0) return '剩余 --';
                var mins = Math.floor(seconds / 60);
                var secs = Math.ceil(seconds % 60);
                if (mins <= 0) return '剩余 ' + secs + ' 秒';
                return '剩余 ' + mins + ' 分 ' + secs + ' 秒';
            }

            function renderTrackList(payload) {
                var countWrap = document.querySelector('.mix-track-count');
                var pills = document.querySelectorAll('.mix-track-pill');
                var list = document.querySelector('.mix-track-list');
                var emptyNote = document.querySelector('.mix-note-card .mix-mini-note');
                if (countWrap && payload.counts) {
                    countWrap.innerHTML = '<i class="fa fa-database" aria-hidden="true"></i> ' + payload.counts.total + ' tracks loaded';
                }
                if (pills.length >= 2 && payload.counts) {
                    pills[0].innerHTML = '<i class="fa fa-microphone" aria-hidden="true"></i> Vocal ' + payload.counts.vocal;
                    pills[1].innerHTML = '<i class="fa fa-music" aria-hidden="true"></i> Music ' + payload.counts.music;
                }
                if (!list) return;
                list.innerHTML = '';
                (payload.tracks || []).forEach(function (track) {
                    var li = document.createElement('li');
                    var label = track.channel === 'vocal' ? ((track.role || '人声') + '分轨') : '乐器分轨';
                    li.innerHTML = '<span>' + track.original_name + '</span><small>' + label + ' / ' + track.mime_type + ' / ' + (track.size / 1024 / 1024).toFixed(2) + ' MB</small>';
                    list.appendChild(li);
                });
                if (emptyNote) {
                    emptyNote.style.display = (payload.tracks || []).length ? 'none' : '';
                }
            }

            document.querySelectorAll('.js-mix-upload-form').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    event.preventDefault();
                    syncMixJobForms();
                    var progress = form.querySelector('[data-upload-progress]');
                    var fill = form.querySelector('[data-upload-progress-fill]');
                    var percent = form.querySelector('[data-upload-percent]');
                    var speed = form.querySelector('[data-upload-speed]');
                    var eta = form.querySelector('[data-upload-eta]');
                    var formData = new FormData(form);
                    var xhr = new XMLHttpRequest();
                    var startedAt = Date.now();
                    xhr.open('POST', window.location.pathname, true);
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                    if (progress) progress.classList.add('is-visible');
                    xhr.upload.addEventListener('progress', function (e) {
                        if (!e.lengthComputable) return;
                        var ratio = e.total > 0 ? e.loaded / e.total : 0;
                        var pct = Math.round(ratio * 100);
                        var elapsed = Math.max((Date.now() - startedAt) / 1000, 0.1);
                        var bytesPerSecond = e.loaded / elapsed;
                        var remainSeconds = bytesPerSecond > 0 ? (e.total - e.loaded) / bytesPerSecond : Infinity;
                        if (fill) fill.style.width = pct + '%';
                        if (percent) percent.textContent = pct + '%';
                        if (speed) speed.textContent = formatBytesPerSecond(bytesPerSecond);
                        if (eta) eta.textContent = formatEta(remainSeconds);
                    });
                    xhr.onreadystatechange = function () {
                        if (xhr.readyState !== 4) return;
                        try {
                            var payload = JSON.parse(xhr.responseText || '{}');
                            if (!payload.ok) {
                                alert(payload.error || payload.message || '上传失败');
                                return;
                            }
                            if (fill) fill.style.width = '100%';
                            if (percent) percent.textContent = '100%';
                            if (eta) eta.textContent = '上传完成';
                            renderTrackList(payload);
                            alert(payload.message || '上传完成');
                        } catch (error) {
                            if (xhr.status === 413) {
                                alert('上传体积超过当前服务器实际限制。当前规则：单条音轨 100MB，整个工程 2GB。');
                            } else {
                                alert('上传返回异常，请刷新页面后再试。');
                            }
                        }
                    };
                    xhr.send(formData);
                });
            });

            document.querySelectorAll('.mix-file-remove').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var row = btn.closest('.mix-file-row');
                    var stack = btn.closest('[data-upload-stack]');
                    if (!row || !stack) return;
                    if (stack.querySelectorAll('.mix-file-row').length <= 1) {
                        var input = row.querySelector('input[type="file"]');
                        if (input) input.value = '';
                        return;
                    }
                    row.remove();
                });
            });

            document.querySelectorAll('.mix-upload-stack input[type="file"]').forEach(function (input) {
                bindTrackInput(input);
            });

            Array.prototype.forEach.call(document.querySelectorAll('[data-add-track]'), function (btn) {
                btn.addEventListener('click', function () {
                    addTrackInput(btn.getAttribute('data-add-track'));
                });
            });

            function syncMixJobForms() {
                var projectNameValue = (document.getElementById('projectName') || {}).value || '';
                var artistNameValue = (document.getElementById('artistName') || {}).value || '';
                var songStyleValue = (document.getElementById('songStyle') || {}).value || '流行 Pop';
                var notesValue = (document.getElementById('mixReference') || {}).value || '';
                document.querySelectorAll('form input[name="project_name"]').forEach(function (input) { input.value = projectNameValue; });
                document.querySelectorAll('form input[name="artist_name"]').forEach(function (input) { input.value = artistNameValue; });
                document.querySelectorAll('form input[name="song_style"]').forEach(function (input) { input.value = songStyleValue; });
                document.querySelectorAll('form input[name="notes"]').forEach(function (input) { input.value = notesValue; });
            }

            ['projectName', 'artistName', 'songStyle', 'mixReference'].forEach(function (id) {
                var field = document.getElementById(id);
                if (!field) return;
                field.addEventListener('input', syncMixJobForms);
                field.addEventListener('change', syncMixJobForms);
            });
            document.querySelectorAll('form').forEach(function (form) {
                form.addEventListener('submit', syncMixJobForms);
            });
            syncMixJobForms();
        })();
    </script>
<script src="<?php echo htmlspecialchars(siteAssetUrl('js/xingzai-widget.js')); ?>" data-api="/backend/xingzai_chat.php" data-avatar="<?php echo htmlspecialchars(siteAssetUrl('images/xingzai-avatar.jpg')); ?>"></script>
</body>
</html>
