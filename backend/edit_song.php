<?php
require_once 'utils.php';
require_once 'db.php';

// 必须已登录
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = getPdo();
$userId = $_SESSION['user_id'];

$songId = $_GET['id'] ?? null;
if (!$songId || !ctype_digit($songId)) {
    die('无效的歌曲 ID');
}

// 检查权限：只能编辑本人上传的歌曲（或管理员）
$stmt = $pdo->prepare('SELECT * FROM songs WHERE id = ?');
$stmt->execute([$songId]);
$song = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$song) {
    die('歌曲不存在');
}
if ($song['user_id'] != $userId) {
    // 简单权限示例：这里不区分 admin，只有本人可以编辑
    die('没有权限编辑此歌曲');
}

$error = '';
$success = '';
$currentAudioUrl = resolveSongAudioUrl($song, 'backend');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = '无效的 CSRF token';
        logMessage('edit_song.php: CSRF 校验失败，IP=' . $_SERVER['REMOTE_ADDR']);
    } else {
        $title = trim($_POST['title'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $newFilePath = $song['file_path'] ?? '';

        if (!empty($_FILES['replacement_audio']['name'] ?? '')) {
            $file = $_FILES['replacement_audio'];
            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $error = '替换歌曲上传失败，错误码：' . ($file['error'] ?? -1);
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
                    $error = '替换歌曲只允许 MP3 或 WAV 格式';
                } elseif (($file['size'] ?? 0) > 100 * 1024 * 1024) {
                    $error = '替换歌曲文件大小不能超过 100 MB';
                } else {
                    $uploadDir = __DIR__ . '/uploads';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $newName = bin2hex(random_bytes(12)) . $allowed[$mime];
                    $dest = $uploadDir . '/' . $newName;
                    if (!move_uploaded_file($file['tmp_name'], $dest)) {
                        $error = '替换歌曲保存失败，请检查 uploads 目录权限';
                    } else {
                        if ($allowed[$mime] === '.wav') {
                            $mp3Name = preg_replace('/\.wav$/i', '.mp3', $newName);
                            $mp3Dest = $uploadDir . '/' . $mp3Name;
                            $command = sprintf('ffmpeg -y -i %s -codec:a libmp3lame -b:a 192k %s 2>/dev/null', escapeshellarg($dest), escapeshellarg($mp3Dest));
                            @exec($command, $out, $code);
                            if ($code === 0 && is_file($mp3Dest)) {
                                $newName = $mp3Name;
                            }
                        }
                        $newFilePath = $newName;
                    }
                }
            }
        }

        if ($error === '' && ($title === '' || $desc === '')) {
            $error = '标题和描述不能为空';
        }

        if ($error === '') {
            $upd = $pdo->prepare('UPDATE songs SET title = ?, description = ?, file_path = ? WHERE id = ?');
            $upd->execute([$title, $desc, $newFilePath, $songId]);
            $success = !empty($_FILES['replacement_audio']['name'] ?? '') ? '歌曲信息和音频已更新' : '歌曲信息已更新';
            logMessage("用户 {$_SESSION['username']} 编辑歌曲 ID {$songId}");
            $stmt->execute([$songId]);
            $song = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>编辑歌曲 - 星浪音乐后台</title>
    <link rel="stylesheet" href="../css/backend.css">
</head>
<body>
<div class="backend-shell">
    <div class="backend-topbar">
        <div class="backend-brand">
            <img src="../images/starwaves-logo.svg" alt="logo">
            <div>
                <strong>编辑歌曲</strong>
                <span><?php echo e($song['title'] ?? ''); ?></span>
            </div>
        </div>
        <div class="backend-links">
            <a href="admin.php">后台首页</a>
            <a href="manage_songs.php">歌曲管理</a>
        </div>
    </div>

    <div class="backend-card">
        <span class="backend-kicker">Edit Song</span>
        <h2>编辑歌曲</h2>
        <?php if (!empty($error)): ?>
            <div class="error-msg"><?php echo e($error); ?></div>
        <?php elseif (!empty($success)): ?>
            <div class="success-msg"><?php echo e($success); ?></div>
        <?php endif; ?>
        <div class="backend-card" style="margin-bottom:20px; background:rgba(255,255,255,0.04);">
            <span class="backend-kicker">Current Song</span>
            <h3 style="margin-top:0;">当前歌曲</h3>
            <p class="muted">替换前可以先试听、下载，确认无误后再上传新文件。</p>
            <audio class="audio-preview song-player" data-title="<?php echo e($song['title'] ?? '当前歌曲'); ?>" controls preload="none" src="<?php echo e($currentAudioUrl); ?>" style="width:100%; max-width:none;"></audio>
            <div class="inline-actions" style="margin-top:14px;">
                <a class="button-link secondary-btn" href="<?php echo e($currentAudioUrl); ?>" target="_blank" rel="noopener">试听原歌曲</a>
                <a class="button-link secondary-btn" href="<?php echo e($currentAudioUrl); ?>" download>下载原歌曲</a>
            </div>
        </div>

        <div id="editSongMessage"></div>
        <form id="editSongForm" class="backend-form" method="post" action="" enctype="multipart/form-data">
            <?php echo csrfInput(); ?>
            <div class="form-grid">
                <div class="form-row full">
                    <label>标题</label>
                    <input type="text" name="title" required value="<?php echo e($song['title'] ?? ''); ?>">
                </div>
                <div class="form-row full">
                    <label>描述</label>
                    <textarea name="description" required><?php echo e($song['description'] ?? ''); ?></textarea>
                </div>
                <div class="form-row full">
                    <label>上传替换歌曲（可选，MP3/WAV）</label>
                    <input id="replacementAudioInput" type="file" name="replacement_audio" accept=".mp3,.wav,audio/mpeg,audio/wav,audio/x-wav">
                </div>
            </div>

            <div id="editProgressWrap" style="display:none; margin-top:14px;">
                <div style="height:14px; background:rgba(255,255,255,0.08); border-radius:999px; overflow:hidden;">
                    <div id="editProgressBar" style="height:14px; width:0%; background:#e6b65c; transition:width .2s ease;"></div>
                </div>
                <div id="editProgressText" class="muted" style="margin-top:8px;">准备上传...</div>
                <div id="editProgressMeta" class="muted" style="margin-top:6px;"></div>
            </div>

            <div class="inline-actions">
                <button type="submit" class="primary-btn">保存修改</button>
                <button type="button" id="replacementUploadBtn" class="button-link secondary-btn">上传替换歌曲</button>
                <a href="manage_songs.php" class="button-link secondary-btn">返回歌曲管理</a>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('editSongForm');
    var replacementInput = document.getElementById('replacementAudioInput');
    var uploadBtn = document.getElementById('replacementUploadBtn');
    var message = document.getElementById('editSongMessage');
    var progressWrap = document.getElementById('editProgressWrap');
    var progressBar = document.getElementById('editProgressBar');
    var progressText = document.getElementById('editProgressText');
    var progressMeta = document.getElementById('editProgressMeta');

    if (!form || !replacementInput || !uploadBtn) {
        return;
    }

    uploadBtn.addEventListener('click', function () {
        replacementInput.click();
    });

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

    form.addEventListener('submit', function (event) {
        if (!(replacementInput.files && replacementInput.files[0])) {
            return;
        }
        event.preventDefault();
        var xhr = new XMLHttpRequest();
        var formData = new FormData(form);
        var startedAt = Date.now();
        progressWrap.style.display = 'block';
        progressBar.style.width = '0%';
        progressText.textContent = '准备上传...';
        progressMeta.textContent = '';
        message.innerHTML = '';

        xhr.open('POST', form.getAttribute('action') || window.location.href, true);
        xhr.upload.addEventListener('progress', function (event) {
            if (!event.lengthComputable) return;
            var percent = Math.round((event.loaded / event.total) * 100);
            var elapsedSeconds = Math.max((Date.now() - startedAt) / 1000, 0.1);
            var bytesPerSecond = event.loaded / elapsedSeconds;
            var remainingSeconds = bytesPerSecond > 0 ? (event.total - event.loaded) / bytesPerSecond : 0;
            progressBar.style.width = percent + '%';
            progressText.textContent = '上传中：' + percent + '%';
            progressMeta.textContent = '速度：' + formatSpeed(bytesPerSecond) + ' · 已上传：' + formatBytes(event.loaded) + ' / ' + formatBytes(event.total) + ' · 预计剩余：' + formatSeconds(remainingSeconds);
        });
        xhr.onload = function () {
            if (xhr.status >= 200 && xhr.status < 300) {
                progressBar.style.width = '100%';
                progressText.textContent = '上传完成：100%';
                progressMeta.textContent = '歌曲替换已完成，正在刷新页面...';
                setTimeout(function () { window.location.reload(); }, 600);
            } else {
                message.innerHTML = '<div class="error-msg">替换歌曲上传失败，请稍后重试。</div>';
            }
        };
        xhr.onerror = function () {
            message.innerHTML = '<div class="error-msg">网络异常，替换歌曲上传未完成。</div>';
        };
        xhr.send(formData);
    });
});
</script>
<script src="../js/global-player.js"></script>
<script src="/js/xingzai-widget.js" data-api="/backend/xingzai_chat.php" data-avatar="/images/xingzai-avatar.jpg"></script>
</body>
</html>
