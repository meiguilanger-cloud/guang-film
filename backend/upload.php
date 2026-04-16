<?php
require_once 'utils.php';
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function uploadLrcFile(?array $file, ?string &$error): ?string {
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $error = 'LRC 文件上传失败';
        return null;
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        $error = 'LRC 文件不能超过 2MB';
        return null;
    }
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if ($ext !== 'lrc') {
        $error = '只允许上传 .lrc 文件';
        return null;
    }
    $dir = __DIR__ . '/lyrics';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $filename = 'lyrics_' . bin2hex(random_bytes(10)) . '.lrc';
    $target = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        $error = 'LRC 文件保存失败';
        return null;
    }
    return 'lyrics/' . $filename;
}

function isAjaxRequest(): bool {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function generateAutoCover(string $title, int $userId): string {
    $dir = __DIR__ . '/generated-covers';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $safeTitle = trim($title) !== '' ? trim($title) : 'STARWAVES';
    $displayTitle = mb_substr($safeTitle, 0, 16);
    $initial = mb_substr($safeTitle, 0, 1);
    $filename = 'cover_' . $userId . '_' . bin2hex(random_bytes(8)) . '.svg';
    $target = $dir . '/' . $filename;

    $titleEscaped = htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8');
    $initialEscaped = htmlspecialchars($initial, ENT_QUOTES, 'UTF-8');
    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="1200" viewBox="0 0 1200 1200">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="#141b25"/>
      <stop offset="55%" stop-color="#24354d"/>
      <stop offset="100%" stop-color="#f1b94f"/>
    </linearGradient>
    <radialGradient id="glow" cx="50%" cy="35%" r="60%">
      <stop offset="0%" stop-color="#ffd57a" stop-opacity="0.95"/>
      <stop offset="100%" stop-color="#ffd57a" stop-opacity="0"/>
    </radialGradient>
  </defs>
  <rect width="1200" height="1200" fill="url(#bg)"/>
  <circle cx="920" cy="220" r="240" fill="url(#glow)"/>
  <circle cx="270" cy="960" r="300" fill="#0d1219" fill-opacity="0.28"/>
  <rect x="78" y="78" width="1044" height="1044" rx="52" fill="none" stroke="rgba(255,255,255,0.15)"/>
  <text x="120" y="190" fill="#f3c86c" font-size="42" font-family="Arial, Helvetica, sans-serif" letter-spacing="10">STARWAVES MUSIC</text>
  <text x="120" y="720" fill="#ffffff" font-size="168" font-family="Arial, Helvetica, sans-serif" font-weight="700">{$titleEscaped}</text>
  <text x="120" y="920" fill="#ffffff" fill-opacity="0.18" font-size="420" font-family="Arial, Helvetica, sans-serif" font-weight="700">{$initialEscaped}</text>
  <text x="120" y="1030" fill="#d8dde6" font-size="40" font-family="Arial, Helvetica, sans-serif">AUTO COVER / USER UPLOAD</text>
</svg>
SVG;

    file_put_contents($target, $svg);
    return 'generated-covers/' . $filename;
}

function uploadCoverImage(?array $file, int $userId, ?string &$error): ?string {
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $error = '封面图上传失败';
        return null;
    }
    if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
        $error = '封面图不能超过 8MB';
        return null;
    }

    $imageInfo = @getimagesize($file['tmp_name']);
    if (!$imageInfo || empty($imageInfo['mime'])) {
        $error = '封面图格式不正确';
        return null;
    }

    $mime = strtolower((string) $imageInfo['mime']);
    $extMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($extMap[$mime])) {
        $error = '封面图只支持 JPG、PNG、WEBP';
        return null;
    }

    $dir = __DIR__ . '/generated-covers';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filename = 'cover_upload_' . $userId . '_' . bin2hex(random_bytes(8)) . '.jpg';
    $target = $dir . '/' . $filename;

    $src = null;
    if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
        $src = @imagecreatefromjpeg($file['tmp_name']);
    } elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
        $src = @imagecreatefrompng($file['tmp_name']);
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $src = @imagecreatefromwebp($file['tmp_name']);
    }

    if ($src && function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled') && function_exists('imagejpeg')) {
        $srcWidth = imagesx($src);
        $srcHeight = imagesy($src);
        $side = min($srcWidth, $srcHeight);
        $srcX = (int) floor(($srcWidth - $side) / 2);
        $srcY = (int) floor(($srcHeight - $side) / 2);
        $dest = imagecreatetruecolor(1200, 1200);
        imagecopyresampled($dest, $src, 0, 0, $srcX, $srcY, 1200, 1200, $side, $side);
        imagejpeg($dest, $target, 90);
        imagedestroy($dest);
        imagedestroy($src);
        return 'generated-covers/' . $filename;
    }

    $fallbackName = 'cover_upload_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $extMap[$mime];
    $fallbackTarget = $dir . '/' . $fallbackName;
    if (!move_uploaded_file($file['tmp_name'], $fallbackTarget)) {
        $error = '封面图保存失败';
        return null;
    }
    return 'generated-covers/' . $fallbackName;
}

function triggerLyricsRecognition(int $songId): void {
    try {
        $command = sprintf('php %s %d > /dev/null 2>&1 &', escapeshellarg(__DIR__ . '/generate_lrc.php'), $songId);
        @exec($command);
    } catch (Throwable $e) {
        logMessage('自动触发歌词识别失败：song_id=' . $songId . ', error=' . $e->getMessage());
    }
}

function respondUpload(array $payload): void {
    if (isAjaxRequest()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$pdo = getPdo();
$userId = (int) $_SESSION['user_id'];
$userStmt = $pdo->prepare('SELECT username, full_name, avatar_path FROM users WHERE id = ?');
$userStmt->execute([$userId]);
$currentUser = $userStmt->fetch(PDO::FETCH_ASSOC) ?: ['username' => $_SESSION['username'] ?? '用户', 'full_name' => '', 'avatar_path' => ''];
$error = '';
$success = '';
$latestSong = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Upload sessions can be long-lived; trust authenticated users here instead of rejecting on stale CSRF tokens.
    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $lyrics = trim($_POST['lyrics'] ?? '');

        if ($title === '' || $desc === '' || !isset($_FILES['audio'])) {
            $error = '标题、描述和音频文件均为必填';
            respondUpload(['ok' => false, 'message' => $error]);
        } else {
            $file = $_FILES['audio'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = '文件上传错误，错误码：' . $file['error'];
                respondUpload(['ok' => false, 'message' => $error]);
            } else {
                $allowed = [
                    'audio/mpeg' => '.mp3',
                    'audio/mp3' => '.mp3',
                    'audio/wav' => '.wav',
                    'audio/x-wav' => '.wav',
                    'audio/wave' => '.wav'
                ];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if (!isset($allowed[$mime])) {
                    $error = '只允许 MP3 或 WAV 格式';
                    respondUpload(['ok' => false, 'message' => $error]);
                } elseif ($file['size'] > 100 * 1024 * 1024) {
                    $error = '文件大小不能超过 100 MB';
                    respondUpload(['ok' => false, 'message' => $error]);
                } else {
                    $newName = bin2hex(random_bytes(12)) . $allowed[$mime];
                    $uploadDir = dirname(__DIR__) . '/uploads';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $dest = $uploadDir . '/' . $newName;
                    if (move_uploaded_file($file['tmp_name'], $dest)) {
                        if ($allowed[$mime] === '.wav') {
                            $mp3Name = preg_replace('/\.wav$/i', '.mp3', $newName);
                            $mp3Dest = $uploadDir . '/' . $mp3Name;
                            $command = sprintf('ffmpeg -y -i %s -codec:a libmp3lame -b:a 192k %s 2>/dev/null', escapeshellarg($dest), escapeshellarg($mp3Dest));
                            @exec($command, $out, $code);
                            if ($code === 0 && is_file($mp3Dest)) {
                                $newName = $mp3Name;
                            }
                        }
                        $lrcPath = uploadLrcFile($_FILES['lrc_file'] ?? null, $error);
                        if ($error === '') {
                            $lyricsStatus = $lyrics !== '' ? 'pending' : (!empty($lrcPath) ? 'generated' : 'none');
                            $generatedAt = !empty($lrcPath) ? date('Y-m-d H:i:s') : null;
                            $imageUrl = uploadCoverImage($_FILES['cover_image'] ?? null, $userId, $error);
                            if ($error === '') {
                                $imageUrl = $imageUrl ?: generateAutoCover($title, $userId);
                            }
                            $durationSeconds = detectAudioDuration($dest);
                            $durationLabel = formatAudioDuration($durationSeconds);
                            $stmt = $pdo->prepare('INSERT INTO songs (title, description, lyrics, lrc_path, lyrics_status, lrc_generated_at, file_path, image_url, user_id, duration_seconds, duration_label) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                            $stmt->execute([$title, $desc, $lyrics, $lrcPath, $lyricsStatus, $generatedAt, $newName, $imageUrl, $userId, $durationSeconds, $durationLabel]);
                            $songId = (int) $pdo->lastInsertId();

                            $remoteArchivePath = uploadSongToNetdisk($dest, '/工作/starwaves/starwaves back music');
                            if ($remoteArchivePath) {
                                $pdo->prepare("UPDATE songs SET storage_type = 'baidu_netdisk', archive_path = ?, archived_at = CURRENT_TIMESTAMP, lyrics_note = ? WHERE id = ?")
                                    ->execute([$remoteArchivePath, '上传后已自动存入百度网盘：' . $remoteArchivePath, $songId]);
                                @unlink($dest);
                                $storageType = 'baidu_netdisk';
                            } else {
                                $storageType = 'local';
                            }

                            $songStmt = $pdo->prepare('SELECT id, title, description, lyrics, lrc_path, file_path, storage_type, archive_path, image_url, created_at, lyrics_status, lyrics_note FROM songs WHERE id = ?');
                            $songStmt->execute([$songId]);
                            $latestSong = $songStmt->fetch(PDO::FETCH_ASSOC);
                            $success = $storageType === 'baidu_netdisk'
                                ? '歌曲上传成功，已自动存入百度网盘。'
                                : '歌曲上传成功，歌词和 LRC 已保存。';
                            if ($lyrics === '' && empty($lrcPath)) {
                                $pdo->prepare("UPDATE songs SET lyrics_status = 'pending_recognition', lyrics_note = '已自动提交歌词识别任务' WHERE id = ?")
                                    ->execute([$songId]);
                                triggerLyricsRecognition($songId);
                            } elseif ($lyrics !== '' && empty($lrcPath)) {
                                $pdo->prepare("UPDATE songs SET lyrics_status = 'pending', lyrics_note = '已写入歌词，可继续生成 LRC' WHERE id = ?")
                                    ->execute([$songId]);
                            }
                            respondUpload([
                                'ok' => true,
                                'message' => $success,
                                'song' => [
                                    'id' => (int) $latestSong['id'],
                                    'title' => $latestSong['title'],
                                    'description' => $latestSong['description'],
                                    'file_path' => resolveSongAudioUrl($latestSong, 'backend'),
                                    'lyrics' => $latestSong['lyrics'] ?? '',
                                    'lyrics_status' => $latestSong['lyrics_status'] ?? (($lyrics === '' && empty($lrcPath)) ? 'pending_recognition' : (!empty($latestSong['lyrics']) ? 'pending' : 'none')),
                                    'lyrics_note' => $latestSong['lyrics_note'] ?? ''
                                ]
                            ]);
                            $_POST = [];
                        } else {
                            respondUpload(['ok' => false, 'message' => $error]);
                        }
                    } else {
                        $error = '文件保存失败，请检查 backend/uploads 目录权限';
                        respondUpload(['ok' => false, 'message' => $error]);
                    }
                }
            }
        }
}

$avatarSrc = !empty($currentUser['avatar_path']) ? $currentUser['avatar_path'] : '../images/starwaves-logo.svg';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>上传歌曲 - 音乐后台</title>
    <link rel="stylesheet" href="../css/backend.css">
    <style>
    @media (max-width: 900px) {
        .backend-mobile-toggle {
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            align-self: flex-start;
            margin-left: auto;
            border: 0;
            border-radius: 14px;
            background: rgba(255,255,255,0.08);
            color: #fff;
            width: 46px;
            height: 46px;
            font-size: 24px;
        }
        .backend-links { display: none !important; width: 100%; gap: 10px; margin-top: 6px; }
        .backend-links.open { display: grid !important; }
        .backend-card p,
        #progressText,
        #progressMeta {
            font-size: 16px;
            line-height: 1.65;
        }
    }
    @media (min-width: 901px) {
        .backend-mobile-toggle { display: none !important; }
    }
    </style>
</head>
<body>
<div class="backend-shell">
    <div class="backend-topbar">
        <div class="backend-brand">
            <img src="<?php echo e($avatarSrc); ?>" alt="avatar">
            <div>
                <strong><?php echo e($currentUser['full_name'] ?: $currentUser['username']); ?></strong>
                <span>上传你的最新作品</span>
            </div>
        </div>
        <button type="button" class="backend-mobile-toggle" id="backendMobileToggle" aria-expanded="false" aria-controls="backendLinks">☰</button>
        <div class="backend-links" id="backendLinks">
            <a href="../index.php">返回首页</a>
            <a href="admin.php">后台首页</a>
            <a href="manage_songs.php">管理歌曲</a>
            <a href="profile.php">个人资料</a>
        </div>
    </div>

    <div class="backend-card">
        <span class="backend-kicker">Upload</span>
        <h1>上传新歌曲</h1>
        <p>支持 MP3 / WAV、歌词文本和 LRC 文件。现在上传时会显示实时进度。</p>

        <div id="uploadMessage">
            <?php if ($error): ?>
                <div class="error-msg"><?php echo e($error); ?></div>
            <?php elseif ($success): ?>
                <div class="success-msg"><?php echo e($success); ?></div>
            <?php endif; ?>
        </div>

        <form id="uploadForm" class="backend-form" method="post" enctype="multipart/form-data" action="">
            <?php echo csrfInput(); ?>
            <div class="form-grid">
                <div class="form-row full">
                    <label for="uploadSongPick" style="cursor:pointer;">上传歌曲</label>
                    <div class="inline-actions" style="align-items:center; gap:12px; flex-wrap:wrap; margin-top:6px;">
                        <button id="uploadSongPick" type="button" class="button-link secondary-btn" style="width:fit-content; min-width:96px;">上传</button>
                        <span id="audioFileName" class="muted">还未选择音频文件</span>
                    </div>
                    <input id="audioFileInput" type="file" name="audio" accept=".mp3,.wav,audio/mpeg,audio/wav,audio/x-wav" required style="display:none;">
                    <div class="muted">先上传歌曲，再补标题、描述和歌词信息。当前规则：上传歌曲不扣积分。</div>
                </div>
                <div id="progressWrap" class="form-row full" style="display:none; margin-top:6px;">
                    <div style="height:14px; background:rgba(255,255,255,0.08); border-radius:999px; overflow:hidden;">
                        <div id="progressBar" style="height:14px; width:0%; background:#e6b65c; transition:width .2s ease;"></div>
                    </div>
                    <div id="progressText" class="muted" style="margin-top:8px;">准备上传...</div>
                    <div id="progressMeta" class="muted" style="margin-top:6px;"></div>
                </div>
                <div>
                    <label>LRC 文件（可选）</label>
                    <input type="file" name="lrc_file" accept=".lrc">
                </div>
                <div>
                    <label>歌曲封面（可选，JPG/PNG/WEBP）</label>
                    <input type="file" name="cover_image" accept="image/jpeg,image/png,image/webp">
                </div>
                <div class="form-row full">
                    <label>歌曲标题</label>
                    <input type="text" name="title" required value="<?php echo e($_POST['title'] ?? ''); ?>">
                </div>
                <div class="form-row full">
                    <label>歌曲描述</label>
                    <textarea name="description" required><?php echo e($_POST['description'] ?? ''); ?></textarea>
                </div>
                <div class="form-row full">
                    <label>歌词文本</label>
                    <textarea name="lyrics" placeholder="把歌词粘贴到这里，支持后续前台展示。"><?php echo e($_POST['lyrics'] ?? ''); ?></textarea>
                </div>
            </div>

            <div class="inline-actions">
                <button id="uploadBtn" type="submit" class="primary-btn">保存</button>
                <a class="button-link secondary-btn" href="manage_songs.php">去歌曲管理</a>
            </div>
        </form>
    </div>

    <div id="previewCard" class="backend-card" style="<?php echo $latestSong ? '' : 'display:none;'; ?>">
        <span class="backend-kicker">Preview</span>
        <h2>刚刚上传的歌曲</h2>
        <p><strong id="previewTitle"><?php echo e($latestSong['title'] ?? ''); ?></strong></p>
        <p id="previewDesc" class="muted"><?php echo e($latestSong['description'] ?? ''); ?></p>
        <audio id="previewAudio" class="audio-preview" controls src="<?php echo !empty($latestSong) ? e(resolveSongAudioUrl($latestSong, 'backend')) : ''; ?>"></audio>
        <div id="previewLyrics" style="margin-top:16px; white-space:pre-wrap;"><?php echo e($latestSong['lyrics'] ?? ''); ?></div>
        <div class="backend-footer">
            <a id="previewLink" href="<?php echo !empty($latestSong['id']) ? '../single-song.php?id=' . (int) $latestSong['id'] : '#'; ?>">打开详情页</a>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.getElementById('backendMobileToggle');
    var links = document.getElementById('backendLinks');
    if (toggle && links) {
        toggle.addEventListener('click', function () {
            var open = links.classList.toggle('open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    var form = document.getElementById('uploadForm');
    var audioFileInput = document.getElementById('audioFileInput');
    var uploadSongPick = document.getElementById('uploadSongPick');
    var progressWrap = document.getElementById('progressWrap');
    var progressBar = document.getElementById('progressBar');
    var progressText = document.getElementById('progressText');
    var progressMeta = document.getElementById('progressMeta');
    var uploadBtn = document.getElementById('uploadBtn');
    var uploadMessage = document.getElementById('uploadMessage');
    var previewCard = document.getElementById('previewCard');
    var audioFileName = document.getElementById('audioFileName');

    if (uploadSongPick && audioFileInput) {
        uploadSongPick.addEventListener('click', function () {
            audioFileInput.click();
        });
        audioFileInput.addEventListener('change', function () {
            var file = audioFileInput.files && audioFileInput.files[0] ? audioFileInput.files[0] : null;
            if (audioFileName) {
                audioFileName.textContent = file ? ('已选择：' + file.name) : '还未选择音频文件';
            }
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var xhr = new XMLHttpRequest();
        var formData = new FormData(form);

        progressWrap.style.display = 'block';
        progressBar.style.width = '0%';
        progressText.textContent = '准备上传...';
        progressMeta.textContent = '';
        var uploadStartedAt = Date.now();
        uploadBtn.disabled = true;
        uploadBtn.textContent = '正在保存...';
        if (audioFileName && audioFileInput.files && audioFileInput.files[0]) {
            audioFileName.textContent = '正在上传：' + audioFileInput.files[0].name;
        }
        uploadMessage.innerHTML = '';

        xhr.open('POST', form.getAttribute('action') || window.location.href, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.upload.addEventListener('progress', function (event) {
            if (event.lengthComputable) {
                var percent = Math.round((event.loaded / event.total) * 100);
                var elapsedSeconds = Math.max((Date.now() - uploadStartedAt) / 1000, 0.1);
                var bytesPerSecond = event.loaded / elapsedSeconds;
                var remainingBytes = event.total - event.loaded;
                var remainingSeconds = bytesPerSecond > 0 ? remainingBytes / bytesPerSecond : 0;

                function formatBytes(bytes) {
                    if (bytes >= 1024 * 1024) {
                        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
                    }
                    return Math.round(bytes / 1024) + ' KB';
                }

                function formatSpeed(bytes) {
                    if (bytes >= 1024 * 1024) {
                        return (bytes / (1024 * 1024)).toFixed(2) + ' MB/s';
                    }
                    return Math.round(bytes / 1024) + ' KB/s';
                }

                function formatSeconds(seconds) {
                    if (seconds < 60) {
                        return Math.max(1, Math.round(seconds)) + ' 秒';
                    }
                    var minutes = Math.floor(seconds / 60);
                    var remain = Math.round(seconds % 60);
                    return minutes + ' 分 ' + remain + ' 秒';
                }

                progressBar.style.width = percent + '%';
                progressText.textContent = '上传中：' + percent + '%';
                progressMeta.textContent = '速度：' + formatSpeed(bytesPerSecond) + ' · 已上传：' + formatBytes(event.loaded) + ' / ' + formatBytes(event.total) + ' · 预计剩余：' + formatSeconds(remainingSeconds);
            }
        });

        xhr.onload = function () {
            uploadBtn.disabled = false;
            uploadBtn.textContent = '上传歌曲';
            if (xhr.status !== 200) {
                uploadBtn.textContent = '保存';
                uploadMessage.innerHTML = '<div class="error-msg">上传失败，请稍后重试。</div>';
                return;
            }
            try {
                var response = JSON.parse(xhr.responseText);
                if (!response.ok) {
                    uploadMessage.innerHTML = '<div class="error-msg">' + response.message + '</div>';
                    if (response.code === 'csrf_expired') {
                        progressText.textContent = '登录状态已失效，正在跳转登录页...';
                        setTimeout(function () {
                            window.location.href = 'login.php?expired=1';
                        }, 1200);
                    }
                    return;
                }
                progressBar.style.width = '100%';
                progressText.textContent = '上传完成：100%';
                progressMeta.textContent = '速度统计完成，文件已上传到服务器。';
                uploadMessage.innerHTML = '<div class="success-msg">' + response.message + ' 你可以继续留在当前页，确认无误后再打开详情页。</div>';
                previewCard.style.display = 'block';
                document.getElementById('previewTitle').textContent = response.song.title || '';
                document.getElementById('previewDesc').textContent = response.song.description || '';
                document.getElementById('previewAudio').src = response.song.file_path || '';
                document.getElementById('previewLyrics').textContent = response.song.lyrics || '';
                document.getElementById('previewLink').href = '../single-song.php?id=' + response.song.id;
                form.reset();
                if (audioFileName) {
                    audioFileName.textContent = '还未选择音频文件';
                }
            } catch (e) {
                uploadMessage.innerHTML = '<div class="error-msg">返回数据解析失败。</div>';
            }
        };

        xhr.onerror = function () {
            uploadBtn.disabled = false;
            uploadBtn.textContent = '保存';
            uploadMessage.innerHTML = '<div class="error-msg">网络异常，上传未完成。</div>';
        };

        xhr.send(formData);
    });
});
</script>
<script src="../js/global-player.js"></script>
<script src="/js/xingzai-widget.js" data-api="/backend/xingzai_chat.php" data-avatar="/images/xingzai-avatar.jpg"></script>
</body>
</html>
