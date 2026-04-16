<?php
require_once 'utils.php';
require_once 'db.php';

$error = '';
$success = '';
$defaultAvatar = '../images/starwaves-logo.svg';

function handleAvatarUpload(?array $file, ?string &$error): ?string {
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
    $avatarDir = __DIR__ . '/avatars';
    if (!is_dir($avatarDir)) {
        mkdir($avatarDir, 0755, true);
    }
    $filename = 'avatar_' . bin2hex(random_bytes(10)) . $allowed[$mime];
    $target = $avatarDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        $error = '头像保存失败';
        return null;
    }
    return 'avatars/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = '无效的 CSRF token';
        logMessage('注册失败：CSRF 校验不通过，IP=' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    } else {
        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPwd = $_POST['confirm_password'] ?? '';

        if ($username === '' || $mobile === '' || $email === '' || $password === '' || $confirmPwd === '') {
            $error = '用户名、手机号、邮箱和密码不能为空';
        } elseif ($password !== $confirmPwd) {
            $error = '两次输入的密码不一致';
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
            $error = '用户名只能是 3-20 位字母、数字、下划线';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = '邮件地址格式不正确';
        } elseif (!preg_match('/^1[3-9]\d{9}$/', $mobile)) {
            $error = '手机号码格式不正确';
        } elseif (mb_strlen($bio) > 300) {
            $error = '个人简介不能超过 300 字';
        } else {
            $pdo = getPdo();
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
            $stmt->execute([$username, $email]);
            if ($stmt->fetchColumn()) {
                $error = '用户名或邮箱已存在';
            } else {
                $avatarPath = handleAvatarUpload($_FILES['avatar'] ?? null, $error);
                if ($error === '') {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare('INSERT INTO users (username, email, mobile, full_name, bio, avatar_path, password, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$username, $email, $mobile, $fullName, $bio, $avatarPath, $hash, $hash]);
                    $success = '注册成功，正在跳转登录页...';
                    logMessage("新用户注册成功：{$username}");
                    header('Refresh:2; url=login.php');
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>注册 - 音乐后台</title>
    <link rel="stylesheet" href="../css/backend.css">
</head>
<body>
<div class="backend-shell">
    <div class="backend-topbar">
        <div class="backend-brand">
            <img src="<?php echo e($defaultAvatar); ?>" alt="logo">
            <div>
                <strong>音乐人入驻</strong>
                <span>创建你的专属后台</span>
            </div>
        </div>
        <div class="backend-links">
            <a href="login.php">已有账号，去登录</a>
        </div>
    </div>

    <div class="backend-card">
        <span class="backend-kicker">Register</span>
        <h1>注册你的音乐主页</h1>
        <p>支持上传头像、填写昵称和个人简介，后面上传歌曲时客户能更快识别你的身份。</p>

        <?php if ($error): ?>
            <div class="error-msg"><?php echo e($error); ?></div>
        <?php elseif ($success): ?>
            <div class="success-msg"><?php echo e($success); ?></div>
        <?php endif; ?>

        <form class="backend-form" method="post" enctype="multipart/form-data" action="">
            <?php echo csrfInput(); ?>
            <div class="form-grid">
                <div>
                    <label>用户名</label>
                    <input type="text" name="username" required value="<?php echo e($_POST['username'] ?? ''); ?>" placeholder="例如：mo_ge_music">
                </div>
                <div>
                    <label>姓名 / 昵称</label>
                    <input type="text" name="full_name" value="<?php echo e($_POST['full_name'] ?? ''); ?>" placeholder="可填艺名或真实姓名">
                </div>
                <div>
                    <label>手机号</label>
                    <input type="text" name="mobile" required value="<?php echo e($_POST['mobile'] ?? ''); ?>">
                </div>
                <div>
                    <label>邮箱</label>
                    <input type="email" name="email" required value="<?php echo e($_POST['email'] ?? ''); ?>">
                </div>
                <div>
                    <label>密码</label>
                    <input type="password" name="password" required>
                </div>
                <div>
                    <label>确认密码</label>
                    <input type="password" name="confirm_password" required>
                </div>
                <div>
                    <label>头像（方的圆的都可以）</label>
                    <input type="file" name="avatar" accept="image/*">
                </div>
                <div class="form-row full">
                    <label>个人简介</label>
                    <textarea name="bio" placeholder="介绍一下你的风格、擅长领域、联系方式等。最多 300 字。"><?php echo e($_POST['bio'] ?? ''); ?></textarea>
                </div>
            </div>
            <button type="submit" class="primary-btn">完成注册</button>
        </form>
    </div>
</div>
<script src="/js/xingzai-widget.js" data-api="/backend/xingzai_chat.php" data-avatar="/images/xingzai-avatar.jpg"></script>
</body>
</html>
