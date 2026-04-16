<?php
require_once 'utils.php';
require_once 'db.php';

$token = $_GET['token'] ?? '';
$error = '';
$success = '';
$showForm = false;

if ($token === '') {
    $error = '缺少 token 参数';
} else {
    $pdo = getPdo();
    // 验证 token 是否存在且未过期
    $stmt = $pdo->prepare('SELECT pr.id, pr.user_id, u.username, pr.expires_at FROM password_resets pr JOIN users u ON pr.user_id = u.id WHERE pr.token = ?');
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $error = '无效的重置链接';
    } elseif (strtotime($row['expires_at']) < time()) {
        $error = '链接已过期，请重新请求';
    } else {
        $showForm = true; // token 有效，展示新密码表单
        $resetId = $row['id'];
        $userId  = $row['user_id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $showForm) {
    if (!verifyCsrf()) {
        $error = '无效的 CSRF token';
        logMessage('密码重置提交失败：CSRF 校验不通过，IP=' . $_SERVER['REMOTE_ADDR']);
    } else {
        $newPwd = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if ($newPwd === '' || $confirm === '') {
            $error = '密码不能为空';
        } elseif ($newPwd !== $confirm) {
            $error = '两次输入的密码不一致';
        } else {
            $hash = password_hash($newPwd, PASSWORD_BCRYPT);
            $pdo->beginTransaction();
            // 更新用户密码
            $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $upd->execute([$hash, $userId]);
            // 删除该 token（防重复使用）
            $del = $pdo->prepare('DELETE FROM password_resets WHERE id = ?');
            $del->execute([$resetId]);
            $pdo->commit();
            $success = '密码已成功更新，请前往登录';
            logMessage("用户 ID {$userId} 密码重置成功");
            $showForm = false;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>重置密码 - 星浪音乐</title>
    <link rel="stylesheet" href="../css/backend.css">
</head>
<body>
    <h2>重置密码</h2>
    <?php if ($error): ?>
        <div class="error-msg"><?php echo e($error); ?></div>
    <?php elseif ($success): ?>
        <div class="success-msg"><?php echo e($success); ?></div>
    <?php endif; ?>
    <?php if ($showForm): ?>
        <form class="backend-form" method="post" action="">
            <?php echo csrfInput(); ?>
            <div>
                <label>新密码</label>
                <input type="password" name="new_password" required>
            </div>
            <div>
                <label>确认新密码</label>
                <input type="password" name="confirm_password" required>
            </div>
            <button type="submit" class="primary-btn">更新密码</button>
        </form>
    <?php else: ?>
        <p><a href="login.php">← 前往登录</a></p>
    <?php endif; ?>
<script src="/js/xingzai-widget.js" data-api="/backend/xingzai_chat.php" data-avatar="/images/xingzai-avatar.jpg"></script>
</body>
</html>
