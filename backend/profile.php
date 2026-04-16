<?php
require_once 'utils.php';
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function normalizeAvatarBinary(string $binary, string $extension): string {
    if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) {
        return $binary;
    }

    $source = @imagecreatefromstring($binary);
    if (!$source) {
        return $binary;
    }

    $width = imagesx($source);
    $height = imagesy($source);
    $minX = $width;
    $minY = $height;
    $maxX = -1;
    $maxY = -1;

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $rgba = imagecolorat($source, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F;
            if ($alpha < 120) {
                if ($x < $minX) $minX = $x;
                if ($y < $minY) $minY = $y;
                if ($x > $maxX) $maxX = $x;
                if ($y > $maxY) $maxY = $y;
            }
        }
    }

    if ($maxX < $minX || $maxY < $minY) {
        imagedestroy($source);
        return $binary;
    }

    $cropWidth = $maxX - $minX + 1;
    $cropHeight = $maxY - $minY + 1;
    $size = max($cropWidth, $cropHeight);
    $srcX = max(0, (int) floor($minX - ($size - $cropWidth) / 2));
    $srcY = max(0, (int) floor($minY - ($size - $cropHeight) / 2));
    if ($srcX + $size > $width) {
        $srcX = max(0, $width - $size);
    }
    if ($srcY + $size > $height) {
        $srcY = max(0, $height - $size);
    }

    $dest = imagecreatetruecolor(512, 512);
    imagealphablending($dest, false);
    imagesavealpha($dest, true);
    $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
    imagefill($dest, 0, 0, $transparent);
    imagecopyresampled($dest, $source, 0, 0, $srcX, $srcY, 512, 512, min($size, $width), min($size, $height));

    ob_start();
    if ($extension === '.png' && function_exists('imagepng')) {
        imagepng($dest);
    } elseif ($extension === '.webp' && function_exists('imagewebp')) {
        imagewebp($dest, null, 92);
    } elseif (function_exists('imagejpeg')) {
        imagejpeg($dest, null, 92);
    } else {
        imagedestroy($dest);
        imagedestroy($source);
        return $binary;
    }
    $output = ob_get_clean();
    imagedestroy($dest);
    imagedestroy($source);
    return $output !== false ? $output : $binary;
}

function saveAvatarBinary(string $binary, string $extension, ?string &$error): ?string {
    $dir = __DIR__ . '/avatars';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $filename = 'avatar_' . bin2hex(random_bytes(10)) . $extension;
    $target = $dir . '/' . $filename;
    $binary = normalizeAvatarBinary($binary, $extension);
    if (file_put_contents($target, $binary) === false) {
        $error = '头像保存失败';
        return null;
    }
    return 'avatars/' . $filename;
}

function handleProfileAvatar(?array $file, ?string $croppedData, ?string &$error): ?string {
    if ($croppedData) {
        if (!preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,(.+)$/', $croppedData, $matches)) {
            $error = '裁剪头像数据无效';
            return null;
        }
        $format = strtolower($matches[1]);
        $binary = base64_decode($matches[2], true);
        if ($binary === false) {
            $error = '裁剪头像读取失败';
            return null;
        }
        if (strlen($binary) > 5 * 1024 * 1024) {
            $error = '裁剪后的头像不能超过 5MB';
            return null;
        }
        $extension = $format === 'png' ? '.png' : ($format === 'webp' ? '.webp' : '.jpg');
        return saveAvatarBinary($binary, $extension, $error);
    }

    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $error = '头像上传失败';
        return null;
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        $error = '头像大小不能超过 5MB';
        return null;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = [
        'image/jpeg' => '.jpg',
        'image/png' => '.png',
        'image/webp' => '.webp',
        'image/gif' => '.gif'
    ];
    if (!isset($allowed[$mime])) {
        $error = '头像只支持 JPG、PNG、WEBP、GIF';
        return null;
    }
    $binary = file_get_contents($file['tmp_name']);
    if ($binary === false) {
        $error = '头像读取失败';
        return null;
    }
    return saveAvatarBinary($binary, $allowed[$mime], $error);
}

$pdo = getPdo();
$userId = (int) $_SESSION['user_id'];
$stmt = $pdo->prepare('SELECT username, email, mobile, full_name, bio, avatar_path FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$error = '';
$success = '';

if (!$user) {
    $error = '用户不存在';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    if (!verifyCsrf()) {
        $error = '无效的 CSRF token';
    } else {
        $email = trim($_POST['email'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $newPwd = $_POST['new_password'] ?? '';
        $confirmPwd = $_POST['confirm_password'] ?? '';
        $croppedAvatar = trim($_POST['avatar_cropped'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = '邮箱格式不正确';
        } elseif (!preg_match('/^1[3-9]\d{9}$/', $mobile)) {
            $error = '手机号码格式不正确';
        } elseif ($newPwd !== '' && $newPwd !== $confirmPwd) {
            $error = '两次输入的新密码不一致';
        } elseif (mb_strlen($bio) > 300) {
            $error = '个人简介不能超过 300 字';
        } else {
            $avatarPath = handleProfileAvatar($_FILES['avatar'] ?? null, $croppedAvatar !== '' ? $croppedAvatar : null, $error);
            if ($error === '') {
                $avatarValue = $avatarPath ?: ($user['avatar_path'] ?? null);
                $upd = $pdo->prepare('UPDATE users SET email = ?, mobile = ?, full_name = ?, bio = ?, avatar_path = ? WHERE id = ?');
                $upd->execute([$email, $mobile, $fullName, $bio, $avatarValue, $userId]);
                if ($newPwd !== '') {
                    $hash = password_hash($newPwd, PASSWORD_BCRYPT);
                    $pwdUpd = $pdo->prepare('UPDATE users SET password = ?, password_hash = ? WHERE id = ?');
                    $pwdUpd->execute([$hash, $hash, $userId]);
                }
                $success = '个人资料已更新';
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
    }
}

$avatarSrc = resolveAvatarUrl(!empty($user['avatar_path']) ? $user['avatar_path'] : '../images/starwaves-logo.svg');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>个人资料 - 音乐后台</title>
    <link rel="stylesheet" href="../css/backend.css?v=20260405-1000">
    <style>
    @media (max-width: 900px) {
        .backend-mobile-toggle {
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            align-self: flex-end;
            border: 0;
            border-radius: 14px;
            background: rgba(255,255,255,0.08);
            color: #fff;
            width: 46px;
            height: 46px;
            font-size: 24px;
        }
        .backend-shell {
            padding: 18px 12px 44px;
        }
        .backend-card {
            padding: 18px 14px;
            border-radius: 20px;
        }
        .backend-links { display: none !important; width: 100%; gap: 10px; }
        .backend-links.open { display: grid !important; }
        .backend-links a {
            display: block;
            width: 100%;
            padding: 12px 14px;
            border-radius: 14px;
            background: rgba(255,255,255,0.06);
            font-size: 16px;
        }
        .backend-brand strong,
        .user-meta h2 {
            font-size: 22px;
        }
        .backend-brand span,
        .muted,
        .user-meta p,
        .backend-form label,
        .backend-form input,
        .backend-form textarea,
        .backend-form button,
        .primary-btn,
        .secondary-btn,
        .avatar-editor-note {
            font-size: 16px;
        }
        .user-hero {
            align-items: flex-start;
            gap: 14px;
        }
        .profile-avatar.large,
        .avatar-preview.large {
            width: 92px;
            height: 92px;
        }
        .form-grid {
            grid-template-columns: 1fr !important;
            gap: 14px;
        }
        .form-grid > div,
        .form-row.full {
            grid-column: 1 / -1;
        }
        .backend-form input[type="text"],
        .backend-form input[type="email"],
        .backend-form input[type="password"],
        .backend-form input[type="file"],
        .backend-form textarea {
            padding: 16px;
            border-radius: 14px;
        }
        .avatar-preview-wrap {
            flex-direction: column;
            align-items: flex-start;
        }
        .avatar-editor-panel {
            padding: 14px;
        }
        .avatar-editor-stage {
            width: 100%;
            max-width: none;
        }
        .avatar-editor-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
        }
        .avatar-editor-actions button,
        .primary-btn {
            width: 100%;
            min-width: 0;
            padding: 14px 16px;
        }
    }
    .avatar-editor-panel {
        margin-top: 14px;
        padding: 16px;
        border-radius: 18px;
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.08);
    }
    .avatar-editor-stage {
        position: relative;
        width: min(320px, 100%);
        aspect-ratio: 1 / 1;
        margin-top: 14px;
        border-radius: 24px;
        overflow: hidden;
        background: #0b1016;
        touch-action: none;
        user-select: none;
    }
    .avatar-editor-image {
        position: absolute;
        top: 0;
        left: 0;
        max-width: none;
        transform-origin: top left;
        cursor: grab;
    }
    .avatar-editor-image.dragging {
        cursor: grabbing;
    }
    .avatar-editor-mask {
        position: absolute;
        inset: 0;
        pointer-events: none;
        box-shadow: inset 0 0 0 9999px rgba(0,0,0,0.42);
        border-radius: 24px;
    }
    .avatar-editor-mask::after {
        content: '';
        position: absolute;
        inset: 12%;
        border-radius: 999px;
        border: 2px solid rgba(230,182,92,0.95);
        box-shadow: 0 0 0 9999px rgba(0,0,0,0.40);
    }
    .avatar-editor-controls {
        margin-top: 14px;
        display: grid;
        gap: 12px;
    }
    .avatar-editor-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 12px;
    }
    .avatar-editor-actions button {
        width: auto;
        min-width: 120px;
    }
    .avatar-editor-note {
        margin-top: 10px;
        color: rgba(255,255,255,0.68);
        font-size: 13px;
        line-height: 1.7;
    }
    .avatar-editor-panel[hidden] {
        display: none;
    }
    </style>
</head>
<body>
<div class="backend-shell">
    <div class="backend-topbar">
        <div class="backend-brand">
            <img src="<?php echo e($avatarSrc); ?>" alt="avatar">
            <div>
                <strong><?php echo e($user['full_name'] ?: $user['username']); ?></strong>
                <span>个人资料与头像设置</span>
            </div>
        </div>
        <button type="button" class="backend-mobile-toggle" id="backendMobileToggle" aria-expanded="false" aria-controls="backendLinks">☰</button>
        <div class="backend-links" id="backendLinks">
            <a href="../index.php">返回首页</a>
            <a href="admin.php">返回后台</a>
        </div>
    </div>

    <div class="backend-card">
        <span class="backend-kicker">Profile</span>
        <div class="user-hero">
            <img class="profile-avatar large" src="<?php echo e($avatarSrc); ?>" alt="avatar">
            <div class="user-meta">
                <h2><?php echo e($user['full_name'] ?: $user['username']); ?></h2>
                <p class="muted">用户名：<?php echo e($user['username']); ?></p>
                <p class="muted">未上传头像时默认使用站点 Logo，上传后自动切换为你的个人头像。</p>
                <p><?php echo e($user['bio'] ?: '还没有填写个人简介。'); ?></p>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="error-msg"><?php echo e($error); ?></div>
        <?php elseif ($success): ?>
            <div class="success-msg"><?php echo e($success); ?></div>
        <?php endif; ?>

        <form class="backend-form" method="post" enctype="multipart/form-data" action="">
            <?php echo csrfInput(); ?>
            <div class="form-grid">
                <div>
                    <label>姓名 / 昵称</label>
                    <input type="text" name="full_name" value="<?php echo e($user['full_name'] ?? ''); ?>">
                </div>
                <div>
                    <label>邮箱</label>
                    <input type="email" name="email" required value="<?php echo e($user['email'] ?? ''); ?>">
                </div>
                <div>
                    <label>手机号</label>
                    <input type="text" name="mobile" required value="<?php echo e($user['mobile'] ?? ''); ?>">
                </div>
                <div>
                    <label>上传 / 修改头像</label>
                    <input id="avatarInput" type="file" name="avatar" accept="image/*">
                    <input id="avatarCroppedInput" type="hidden" name="avatar_cropped" value="">
                    <div class="avatar-preview-wrap">
                        <img id="avatarPreview" class="avatar-preview large" src="<?php echo e($avatarSrc); ?>" alt="头像预览">
                        <div>
                            <div class="muted">头像会按圆形展示。选图后可以先拖动、缩放、裁剪，再保存。</div>
                            <div class="muted" style="margin-top:8px;">现在支持：拖拽位置、缩放大小、圆形预览后保存。</div>
                        </div>
                    </div>
                    <div id="avatarEditorPanel" class="avatar-editor-panel" hidden>
                        <strong>头像编辑</strong>
                        <div id="avatarEditorStage" class="avatar-editor-stage">
                            <img id="avatarEditorImage" class="avatar-editor-image" alt="头像编辑">
                            <div class="avatar-editor-mask"></div>
                        </div>
                        <div class="avatar-editor-controls">
                            <div>
                                <label for="avatarZoomRange">缩放大小</label>
                                <input id="avatarZoomRange" type="range" min="1" max="3" step="0.01" value="1">
                            </div>
                        </div>
                        <div class="avatar-editor-actions">
                            <button type="button" class="primary-btn" id="applyAvatarCrop">确定裁剪</button>
                            <button type="button" class="secondary-btn" id="resetAvatarCrop">重置位置</button>
                        </div>
                        <div class="avatar-editor-note">拖动图片调整位置，圆框内就是最终头像区域。</div>
                    </div>
                </div>
                <div class="form-row full">
                    <label>个人简介</label>
                    <textarea name="bio"><?php echo e($user['bio'] ?? ''); ?></textarea>
                </div>
                <div>
                    <label>新密码（留空则不修改）</label>
                    <input type="password" name="new_password">
                </div>
                <div>
                    <label>确认新密码</label>
                    <input type="password" name="confirm_password">
                </div>
            </div>
            <button type="submit" class="primary-btn">保存资料</button>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('avatarInput');
    var preview = document.getElementById('avatarPreview');
    var croppedInput = document.getElementById('avatarCroppedInput');
    var editorPanel = document.getElementById('avatarEditorPanel');
    var editorStage = document.getElementById('avatarEditorStage');
    var editorImage = document.getElementById('avatarEditorImage');
    var zoomRange = document.getElementById('avatarZoomRange');
    var applyCrop = document.getElementById('applyAvatarCrop');
    var resetCrop = document.getElementById('resetAvatarCrop');
    var toggle = document.getElementById('backendMobileToggle');
    var links = document.getElementById('backendLinks');
    var imageState = null;
    var dragging = false;
    var dragStartX = 0;
    var dragStartY = 0;
    var startX = 0;
    var startY = 0;

    if (toggle && links) {
        toggle.addEventListener('click', function () {
            var open = links.classList.toggle('open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    function clampPosition() {
        if (!imageState || !editorStage) {
            return;
        }
        var stageRect = editorStage.getBoundingClientRect();
        var stageSize = Math.min(stageRect.width, stageRect.height);
        var cropPadding = stageSize * 0.12;
        var cropSize = stageSize - cropPadding * 2;
        var renderWidth = imageState.width * imageState.scale;
        var renderHeight = imageState.height * imageState.scale;
        var minX = cropPadding + cropSize - renderWidth;
        var maxX = cropPadding;
        var minY = cropPadding + cropSize - renderHeight;
        var maxY = cropPadding;
        imageState.x = Math.min(maxX, Math.max(minX, imageState.x));
        imageState.y = Math.min(maxY, Math.max(minY, imageState.y));
    }

    function renderEditor() {
        if (!imageState || !editorImage) {
            return;
        }
        clampPosition();
        editorImage.style.width = imageState.width + 'px';
        editorImage.style.height = imageState.height + 'px';
        editorImage.style.transform = 'translate(' + imageState.x + 'px,' + imageState.y + 'px) scale(' + imageState.scale + ')';
    }

    function resetEditorPosition() {
        if (!imageState || !editorStage) {
            return;
        }
        var stageRect = editorStage.getBoundingClientRect();
        var stageSize = Math.min(stageRect.width, stageRect.height);
        var cropPadding = stageSize * 0.12;
        var cropSize = stageSize - cropPadding * 2;
        var baseScale = Math.max(cropSize / imageState.width, cropSize / imageState.height);
        imageState.scale = Math.max(baseScale, parseFloat(zoomRange.value || '1'));
        imageState.x = cropPadding + (cropSize - imageState.width * imageState.scale) / 2;
        imageState.y = cropPadding + (cropSize - imageState.height * imageState.scale) / 2;
        zoomRange.min = String(baseScale.toFixed(2));
        zoomRange.value = String(imageState.scale.toFixed(2));
        renderEditor();
    }

    function getPoint(event) {
        if (event.touches && event.touches[0]) {
            return { x: event.touches[0].clientX, y: event.touches[0].clientY };
        }
        return { x: event.clientX, y: event.clientY };
    }

    function startDrag(event) {
        if (!imageState) {
            return;
        }
        dragging = true;
        editorImage.classList.add('dragging');
        var point = getPoint(event);
        dragStartX = point.x;
        dragStartY = point.y;
        startX = imageState.x;
        startY = imageState.y;
        event.preventDefault();
    }

    function moveDrag(event) {
        if (!dragging || !imageState) {
            return;
        }
        var point = getPoint(event);
        imageState.x = startX + (point.x - dragStartX);
        imageState.y = startY + (point.y - dragStartY);
        renderEditor();
        event.preventDefault();
    }

    function endDrag() {
        dragging = false;
        if (editorImage) {
            editorImage.classList.remove('dragging');
        }
    }

    if (editorStage) {
        editorStage.addEventListener('mousedown', startDrag);
        editorStage.addEventListener('touchstart', startDrag, { passive: false });
        window.addEventListener('mousemove', moveDrag);
        window.addEventListener('touchmove', moveDrag, { passive: false });
        window.addEventListener('mouseup', endDrag);
        window.addEventListener('touchend', endDrag);
    }

    if (zoomRange) {
        zoomRange.addEventListener('input', function () {
            if (!imageState) {
                return;
            }
            imageState.scale = parseFloat(zoomRange.value || '1');
            renderEditor();
        });
    }

    if (resetCrop) {
        resetCrop.addEventListener('click', function () {
            resetEditorPosition();
        });
    }

    if (applyCrop) {
        applyCrop.addEventListener('click', function () {
            if (!imageState || !editorStage) {
                return;
            }
            var stageRect = editorStage.getBoundingClientRect();
            var stageSize = Math.min(stageRect.width, stageRect.height);
            var cropPadding = stageSize * 0.12;
            var cropSize = stageSize - cropPadding * 2;
            var canvas = document.createElement('canvas');
            canvas.width = 512;
            canvas.height = 512;
            var ctx = canvas.getContext('2d');
            if (!ctx) {
                return;
            }
            ctx.beginPath();
            ctx.arc(256, 256, 256, 0, Math.PI * 2);
            ctx.closePath();
            ctx.clip();
            var scaleRatio = 512 / cropSize;
            ctx.drawImage(
                imageState.image,
                (cropPadding - imageState.x) / imageState.scale,
                (cropPadding - imageState.y) / imageState.scale,
                cropSize / imageState.scale,
                cropSize / imageState.scale,
                0,
                0,
                512,
                512
            );
            var dataUrl = canvas.toDataURL('image/png');
            preview.src = dataUrl;
            croppedInput.value = dataUrl;
            editorPanel.hidden = true;
        });
    }

    if (!input || !preview || !editorPanel || !editorImage || !zoomRange || !croppedInput) {
        return;
    }

    input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (!file) {
            return;
        }
        var reader = new FileReader();
        reader.onload = function (event) {
            var image = new Image();
            image.onload = function () {
                imageState = {
                    image: image,
                    width: image.width,
                    height: image.height,
                    scale: 1,
                    x: 0,
                    y: 0
                };
                editorImage.src = event.target.result;
                editorPanel.hidden = false;
                croppedInput.value = '';
                requestAnimationFrame(function () {
                    resetEditorPosition();
                });
            };
            image.src = event.target.result;
        };
        reader.readAsDataURL(file);
    });
});
</script>
<script src="<?php echo e(resolvePublicAssetUrl('js/xingzai-widget.js')); ?>" data-api="/backend/xingzai_chat.php" data-avatar="<?php echo e(resolvePublicAssetUrl('images/xingzai-avatar.jpg')); ?>"></script>
</body>
</html>
