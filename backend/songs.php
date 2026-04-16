<?php
require_once __DIR__ . '/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = getPdo();
$userId = (int) $_SESSION['user_id'];

$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$pageSize = 10;
$offset = ($page - 1) * $pageSize;

$total = (int) $pdo->query('SELECT COUNT(*) FROM songs')->fetchColumn();
$totalPages = max(1, (int) ceil($total / $pageSize));

$stmt = $pdo->prepare('SELECT id, title, description, play_count FROM songs ORDER BY id DESC LIMIT :limit OFFSET :offset');
$stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$songs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$favoritesStmt = $pdo->prepare('SELECT song_id FROM favorites WHERE user_id = :uid');
$favoritesStmt->execute([':uid' => $userId]);
$favoriteIds = array_map('intval', $favoritesStmt->fetchAll(PDO::FETCH_COLUMN));
$favoriteLookup = array_fill_keys($favoriteIds, true);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>歌曲列表</title>
    <link rel="stylesheet" href="/new_music_project/css/starwaves.css">
    <style>
        body { font-family: Arial, sans-serif; margin: 30px; }
        ul { list-style: none; padding: 0; }
        li { margin-bottom: 14px; padding: 14px; border: 1px solid #ddd; border-radius: 8px; }
        .song-link { text-decoration: none; color: #1d4ed8; font-weight: bold; }
        .song-desc { color: #555; margin: 8px 0; }
        .song-actions { display: flex; gap: 10px; align-items: center; margin-top: 8px; }
        .fav-btn { border: 0; border-radius: 6px; padding: 8px 12px; cursor: pointer; }
        .fav-btn.add { background: #2563eb; color: #fff; }
        .fav-btn.remove { background: #ef4444; color: #fff; }
        .pagination { margin-top: 20px; display: flex; gap: 16px; }
    </style>
</head>
<body>
    <h1>歌曲列表 (第 <?= $page ?> 页 / 共 <?= $totalPages ?> 页)</h1>
    <ul id="songList">
    <?php foreach ($songs as $song): ?>
        <?php $isFavorited = !empty($favoriteLookup[(int) $song['id']]); ?>
        <li data-song-id="<?= (int) $song['id'] ?>">
            <a class="song-link" href="/new_music_project/backend/single-song.php?id=<?= (int) $song['id'] ?>">
                <?= htmlspecialchars($song['title']) ?>
            </a>
            <div class="song-desc"><?= htmlspecialchars($song['description']) ?></div>
            <div>播放次数: <?= (int) $song['play_count'] ?></div>
            <div class="song-actions">
                <a href="/new_music_project/backend/single-song.php?id=<?= (int) $song['id'] ?>">查看详情</a>
                <button class="fav-btn <?= $isFavorited ? 'remove' : 'add' ?>" type="button">
                    <?= $isFavorited ? '取消收藏' : '收藏' ?>
                </button>
            </div>
        </li>
    <?php endforeach; ?>
    </ul>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>">← 上一页</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>">下一页 →</a>
        <?php endif; ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.fav-btn').forEach(function (button) {
            button.addEventListener('click', function () {
                var item = this.closest('li');
                var songId = item.getAttribute('data-song-id');
                var isRemoving = this.classList.contains('remove');
                var action = isRemoving ? 'remove' : 'add';
                var currentButton = this;
                var xhr = new XMLHttpRequest();
                var formData = new FormData();

                xhr.open('POST', 'favorite.php', true);
                formData.append('song_id', songId);
                formData.append('action', action);

                xhr.onload = function () {
                    if (xhr.status === 200) {
                        if (action === 'add') {
                            currentButton.textContent = '取消收藏';
                            currentButton.classList.remove('add');
                            currentButton.classList.add('remove');
                        } else {
                            currentButton.textContent = '收藏';
                            currentButton.classList.remove('remove');
                            currentButton.classList.add('add');
                        }
                    }
                };

                xhr.send(formData);
            });
        });
    });
    </script>
<script src="/js/xingzai-widget.js" data-api="/backend/xingzai_chat.php" data-avatar="/images/xingzai-avatar.jpg"></script>
</body>
</html>
