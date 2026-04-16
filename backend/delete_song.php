<?php
require_once 'utils.php';
require_once 'db.php';

requireLoginPage('delete_song.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));

$pdo = getPdo();
$userId = $_SESSION['user_id'];

$songId = $_GET['id'] ?? null;
if (!$songId || !ctype_digit($songId)) {
    die('无效的歌曲 ID');
}

// 获取歌曲信息用于权限校验和文件删除
$stmt = $pdo->prepare('SELECT * FROM songs WHERE id = ?');
$stmt->execute([$songId]);
$song = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$song) {
    die('歌曲不存在');
}
if ($song['user_id'] != $userId) {
    die('没有权限删除此歌曲');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = '无效的 CSRF token';
        logMessage('delete_song.php: CSRF 校验失败，IP=' . $_SERVER['REMOTE_ADDR']);
    } else {
        // 删除文件
        $filePath = __DIR__ . '/uploads/' . $song['file_path'];
        if (is_file($filePath)) {
            unlink($filePath);
        }
        // 删除数据库记录
        $del = $pdo->prepare('DELETE FROM songs WHERE id = ?');
        $del->execute([$songId]);
        logMessage("用户 {$_SESSION['username']} 删除歌曲 ID {$songId}");
        // 删除后直接回后台主页，避免再跳到旧 songs.php 链路。
        header('Location: admin.php?deleted=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>删除歌曲 - 确认</title>
    <link rel="stylesheet" href="../css/backend.css">
</head>
<body>
    <h2>确认删除歌曲</h2>
    <p>确定要删除歌曲 <strong><?php echo e($song['title']); ?></strong> 吗？此操作不可恢复。</p>
    <form method="post" action="">
        <?php echo csrfInput(); ?>
        <button type="submit" class="primary-btn" style="background:#c33;">立即删除</button>
        <a href="songs.php" class="primary-btn" style="background:#777; margin-left:10px;">取消</a>
    </form>
<script src="/js/xingzai-widget.js" data-api="/backend/xingzai_chat.php" data-avatar="/images/xingzai-avatar.jpg"></script>
</body>
</html>
