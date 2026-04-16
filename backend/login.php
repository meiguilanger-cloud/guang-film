<?php
require_once 'utils.php';
require_once 'db.php';

$error = '';
$expired = !empty($_GET['expired']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = '无效的 CSRF token';
        logMessage('登录失败：CSRF 校验不通过，IP=' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $rememberDays = (int) ($_POST['remember_days'] ?? 0);

        if ($username === '' || $password === '') {
            $error = '用户名和密码不能为空';
        } else {
            $pdo = getPdo();
            $stmt = $pdo->prepare('SELECT id, username, password_hash, password FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $hash = $row['password_hash'] ?: ($row['password'] ?? '');

            if ($row && $hash !== '' && password_verify($password, $hash)) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['username'] = $row['username'];

                $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
                $domain = cookieDomain();
                if (in_array($rememberDays, [7, 30], true)) {
                    $rememberUntil = time() + ($rememberDays * 86400);
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);
                    $pdo->prepare('UPDATE users SET remember_token = ?, remember_expires = datetime(?, "unixepoch") WHERE id = ?')
                        ->execute([$tokenHash, $rememberUntil, $row['id']]);
                    setcookie('remember_selector', (string) $row['id'], $rememberUntil, '/', $domain, $secure, true);
                    setcookie('remember_token', $token, $rememberUntil, '/', $domain, $secure, true);
                } else {
                    $pdo->prepare('UPDATE users SET remember_token = NULL, remember_expires = NULL WHERE id = ?')->execute([$row['id']]);
                    setcookie('remember_selector', '', time() - 3600, '/', $domain, $secure, true);
                    setcookie('remember_token', '', time() - 3600, '/', $domain, $secure, true);
                }

                logMessage("用户登录成功：{$username}, IP=" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
                header('Location: admin.php');
                exit;
            }

            $error = '用户名或密码错误';
            logMessage("登录失败尝试：{$username}, IP=" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>登录 - 音乐后台</title>
    <link rel="stylesheet" href="../css/backend.css">
</head>
<body>
<div class="backend-shell">
    <div class="backend-card" style="max-width:560px; margin:60px auto;">
        <span class="backend-kicker">Login</span>
        <h1>登录后台</h1>
        <p>登录一次后可保持 7 天或 30 天，上传大文件时也尽量不掉登录。</p>
        <?php if ($expired): ?>
            <div class="error-msg">登录状态已失效，请重新登录。</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-msg"><?php echo e($error); ?></div>
        <?php endif; ?>
        <form class="backend-form" method="post" action="">
            <?php echo csrfInput(); ?>
            <div>
                <label>用户名</label>
                <input type="text" name="username" required value="<?php echo e($_POST['username'] ?? ''); ?>">
            </div>
            <div>
                <label>密码</label>
                <input type="password" name="password" required>
            </div>
            <div>
                <label>登录保持</label>
                <select name="remember_days" style="width:100%; box-sizing:border-box; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12); color:#fff; border-radius:16px; padding:14px 16px; font-size:15px;">
                    <option value="0">仅本次会话</option>
                    <option value="7">记住我 7 天</option>
                    <option value="30" selected>记住我 30 天</option>
                </select>
            </div>
            <button type="submit" class="primary-btn">登录</button>
        </form>
        <p><a href="register.php">← 没有账号，去注册</a></p>
        <p><a href="password_reset_request.php">忘记密码？</a></p>
    </div>
</div>
<script src="/js/xingzai-widget.js" data-api="/backend/xingzai_chat.php" data-avatar="/images/xingzai-avatar.jpg"></script>
</body>
</html>
