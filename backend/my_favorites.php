<?php
require_once __DIR__ . '/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo '请先登录';
    exit;
}

$userId = (int) $_SESSION['user_id'];
$pdo = getPdo();
$stmt = $pdo->prepare('SELECT s.id, s.title, s.play_count FROM songs s JOIN favorites f ON s.id = f.song_id WHERE f.user_id = :uid ORDER BY f.added_at DESC');
$stmt->execute([':uid' => $userId]);
$favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>我的收藏</title>
    <link rel="stylesheet" href="/new_music_project/css/starwaves.css">
    <style>
        body { font-family: Arial, sans-serif; margin: 30px; }
        ul { list-style: none; padding: 0; }
        li { margin-bottom: 12px; padding: 12px; border: 1px solid #ddd; border-radius: 8px; }
        .song-link { text-decoration: none; color: #1d4ed8; font-weight: bold; }
        .meta { margin: 6px 0 10px; color: #555; }
        .remove-btn { background: #ef4444; color: #fff; border: 0; padding: 8px 12px; border-radius: 6px; cursor: pointer; }
        .back-link { display: inline-block; margin-top: 20px; }
        .empty { color: #666; }
    </style>
</head>
<body>
    <h1>我的收藏</h1>

    <?php if (empty($favorites)): ?>
        <p class="empty">你还没有收藏歌曲，去歌曲详情页试试吧。</p>
    <?php else: ?>
        <ul id="favoriteList">
            <?php foreach ($favorites as $song): ?>
                <li data-song-id="<?= (int) $song['id'] ?>">
                    <a class="song-link" href="/new_music_project/backend/single-song.php?id=<?= (int) $song['id'] ?>">
                        <?= htmlspecialchars($song['title']) ?>
                    </a>
                    <div class="meta">播放次数：<?= (int) $song['play_count'] ?></div>
                    <button class="remove-btn" type="button">取消收藏</button>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <a class="back-link" href="/new_music_project/backend/songs.php">返回歌曲列表</a>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.remove-btn').forEach(function (button) {
            button.addEventListener('click', function () {
                var item = this.closest('li');
                var songId = item.getAttribute('data-song-id');
                var xhr = new XMLHttpRequest();
                var formData = new FormData();

                xhr.open('POST', 'favorite.php', true);
                formData.append('song_id', songId);
                formData.append('action', 'remove');

                xhr.onload = function () {
                    if (xhr.status === 200) {
                        item.remove();
                        if (!document.querySelector('#favoriteList li')) {
                            var empty = document.createElement('p');
                            empty.className = 'empty';
                            empty.textContent = '你还没有收藏歌曲，去歌曲详情页试试吧。';
                            document.body.insertBefore(empty, document.querySelector('.back-link'));
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
