<?php
require_once 'utils.php';
require_once 'db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = getPdo();
$userId = (int) $_SESSION['user_id'];
$isAdminView = isset($_GET['scope']) && $_GET['scope'] === 'all';
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'CSRF 校验失败，请刷新后重试。';
    } else {
        $jobId = (int) ($_POST['job_id'] ?? 0);
        $status = trim((string) ($_POST['status'] ?? 'queued'));
        $previewUrl = trim((string) ($_POST['preview_url'] ?? ''));
        $mixFileUrl = trim((string) ($_POST['mix_file_url'] ?? ''));
        $allowedStatuses = ['queued', 'processing', 'preview_ready', 'awaiting_manual', 'completed', 'failed'];

        if ($jobId <= 0) {
            $error = '缺少任务 ID';
        } elseif (!in_array($status, $allowedStatuses, true)) {
            $error = '状态无效';
        } else {
            $scopeSql = $isAdminView ? '' : ' AND user_id = :user_id';
            $sql = 'UPDATE mix_jobs SET status = :status, preview_url = :preview_url, mix_file_url = :mix_file_url, updated_at = CURRENT_TIMESTAMP WHERE id = :id' . $scopeSql;
            $stmt = $pdo->prepare($sql);
            $params = [
                ':status' => $status,
                ':preview_url' => $previewUrl !== '' ? $previewUrl : null,
                ':mix_file_url' => $mixFileUrl !== '' ? $mixFileUrl : null,
                ':id' => $jobId,
            ];
            if (!$isAdminView) {
                $params[':user_id'] = $userId;
            }
            $stmt->execute($params);
            $message = '混音任务已更新。';
        }
    }
}

$where = $isAdminView ? '1=1' : 'mj.user_id = :user_id';
$sql = 'SELECT mj.*, u.username, u.full_name FROM mix_jobs mj LEFT JOIN users u ON u.id = mj.user_id WHERE ' . $where . ' ORDER BY mj.created_at DESC LIMIT 50';
$stmt = $pdo->prepare($sql);
if ($isAdminView) {
    $stmt->execute();
} else {
    $stmt->execute([':user_id' => $userId]);
}
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>混音任务池</title>
    <link rel="stylesheet" href="../css/backend.css?v=20260414-1">
</head>
<body>
<div class="backend-shell">
    <div class="backend-topbar">
        <div class="backend-brand">
            <img src="../images/starwaves-logo.svg" alt="logo">
            <div>
                <strong>混音任务池</strong>
                <span>管理软件混音和硬件混音任务</span>
            </div>
        </div>
        <div class="backend-links">
            <a href="admin.php">后台首页</a>
            <a href="manage_songs.php">管理歌曲</a>
            <a href="logout.php">退出登录</a>
        </div>
    </div>

    <div class="backend-card">
        <span class="backend-kicker">Mix Jobs</span>
        <h1>最近混音任务</h1>
        <?php if ($message): ?><div class="success-msg"><?php echo e($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="error-msg"><?php echo e($error); ?></div><?php endif; ?>
        <div class="desktop-song-table">
            <table class="panel-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>模式</th>
                        <th>工程</th>
                        <th>用户</th>
                        <th>状态</th>
                        <th>积分</th>
                        <th>时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobs as $job): ?>
                        <tr>
                            <td>#<?php echo (int) $job['id']; ?></td>
                            <td><?php echo ($job['mix_mode'] ?? 'software') === 'hardware' ? '硬件混音' : '软件混音'; ?></td>
                            <td>
                                <strong><?php echo e((string) ($job['project_name'] ?? '未命名工程')); ?></strong>
                                <div class="muted"><?php echo e((string) ($job['artist_name'] ?: '未填写')); ?> / <?php echo e((string) ($job['song_style'] ?: 'POP')); ?></div>
                            </td>
                            <td><?php echo e((string) (($job['full_name'] ?: $job['username']) ?: '未知用户')); ?></td>
                            <td><?php echo e((string) ($job['status'] ?? 'queued')); ?></td>
                            <td><?php echo (int) ($job['charged_credits'] ?? 0); ?></td>
                            <td><?php echo e((string) ($job['created_at'] ?? '')); ?></td>
                            <td>
                                <form method="post" class="backend-form" style="gap:10px;">
                                    <?php echo csrfInput(); ?>
                                    <input type="hidden" name="job_id" value="<?php echo (int) $job['id']; ?>">
                                    <select name="status">
                                        <?php foreach (['queued','processing','preview_ready','awaiting_manual','completed','failed'] as $status): ?>
                                            <option value="<?php echo e($status); ?>"<?php echo ($job['status'] ?? '') === $status ? ' selected' : ''; ?>><?php echo e($status); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="url" name="preview_url" placeholder="试听链接" value="<?php echo e((string) ($job['preview_url'] ?? '')); ?>">
                                    <input type="url" name="mix_file_url" placeholder="成品链接" value="<?php echo e((string) ($job['mix_file_url'] ?? '')); ?>">
                                    <?php if (!empty($job['preview_archive_path'])): ?><div class="muted">试听网盘：<?php echo e((string) $job['preview_archive_path']); ?></div><?php endif; ?>
                                    <?php if (!empty($job['mix_file_archive_path'])): ?><div class="muted">成品网盘：<?php echo e((string) $job['mix_file_archive_path']); ?></div><?php endif; ?>
                                    <button type="submit">保存</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script src="/js/xingzai-widget.js" data-api="/backend/xingzai_chat.php" data-avatar="/images/xingzai-avatar.jpg"></script>
</body>
</html>
