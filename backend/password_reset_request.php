<?php
require_once 'utils.php';
require_once 'db.php';

$info = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = '无效的 CSRF token';
        logMessage('密码重置请求失败：CSRF 校验不通过，IP=' . $_SERVER['REMOTE_ADDR']);
    } else {
        $email = trim($_POST['email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = '请输入有效的邮箱地址';
        } else {
            $pdo = getPdo();
            $stmt = $pdo->prepare('SELECT id, username FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                $error = '该邮箱未关联任何账户';
            } else {
                // 生成 token（12字节）
                $token = bin2hex(random_bytes(12));
                $expires = date('Y-m-d H:i:s', time() + 3600); // 1 小时有效
                $ins = $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)');
                $ins->execute([$user['id'], $token, $expires]);
                // 这里本应发送邮件，演示时直接展示 token
                $info = "重置链接（仅演示，请自行复制）：<a href='password_reset.php?token={$token}'>password_reset.php?token={$token}</a>（有效期 1 小时）";
                logMessage('用户请求密码重置：' . $user['username'] . ', email=' . $email);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>找回密码 - 星浪音乐</title>
    <link rel="stylesheet" href="../css/backend.css">
</head>
<body>
    <h2>找回密码</h2>
    <?php if ($error): ?>
        <div class="error-msg"><?php echo e($error); ?></div>
    <?php elseif ($info): ?>
        <div class="success-msg"><?php echo $info; // 已经包含 HTML 链接 ?></div>
    <?php endif; ?>
    <form class="backend-form" method="post" action="">
        <?php echo csrfInput(); ?>
        <div>
            <label>注册时使用的邮箱</label>
            <input type="email" name="email" required value="<?php echo e($_POST['email'] ?? ''); ?>">
        </div>
        <button type="submit" class="primary-btn">获取重置链接</button>
    </form>
    <p><a href="login.php">← 返回登录</a></p>
<script src="<?php echo e(resolvePublicAssetUrl('js/xingzai-widget.js')); ?>" data-api="/backend/xingzai_chat.php" data-avatar="<?php echo e(resolvePublicAssetUrl('images/xingzai-avatar.jpg')); ?>"></script>
</body>
</html>
