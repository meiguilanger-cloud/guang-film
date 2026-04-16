<?php
session_start();
require_once 'utils.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    die('无效的歌曲 ID');
}

$pdo = getPdo();
$stmt = $pdo->prepare('SELECT s.id, s.title, s.description, s.file_path, s.storage_type, s.archive_path, s.created_at, u.username FROM songs s JOIN users u ON s.user_id = u.id WHERE s.id = ?');
$stmt->execute([$id]);
$song = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$song) {
    die('歌曲未找到');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($song['title']); ?> - 歌曲详情</title>
    <link rel="stylesheet" href="../css/backend.css">
</head>
<body class="has-song-bottom-player">
    <h2><?php echo htmlspecialchars($song['title']); ?></h2>
    <p>作者：<?php echo htmlspecialchars($song['username']); ?> | 上传时间：<?php echo $song['created_at']; ?></p>
    <div class="song-detail">
        <?php $backendSongUrl = resolveSongAudioUrl($song, 'backend'); ?>
        <audio controls class="song-player" data-title="<?php echo htmlspecialchars($song['title'], ENT_QUOTES); ?>" src="<?php echo htmlspecialchars($backendSongUrl); ?>"></audio>
        <p><?php echo nl2br(htmlspecialchars($song['description'])); ?></p>
    </div>
<?php
    // Determine if current user has favorited this song
    $favStmt = $pdo->prepare('SELECT 1 FROM favorites WHERE user_id = :uid AND song_id = :sid');
    $favStmt->execute([':uid'=>$_SESSION['user_id'], ':sid'=>$id]);
    $isFavorited = $favStmt->fetchColumn() ? true : false;
?>
<button id="favBtn"><?php echo $isFavorited ? '取消收藏' : '收藏'; ?></button>
<script>
document.getElementById('favBtn').addEventListener('click', function(){
    var action = this.textContent.trim() === '收藏' ? 'add' : 'remove';
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'favorite.php', true);
    var form = new FormData();
    form.append('song_id', <?php echo $id; ?>);
    form.append('action', action);
    xhr.onload = function(){
        if(xhr.status===200){
            if(action==='add'){
                favBtn.textContent='取消收藏';
            } else {
                favBtn.textContent='收藏';
            }
        }
    };
    xhr.send(form);
});
</script>
    <p><a href="songs.php">← 返回歌曲列表</a></p>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var audio = document.querySelector('audio');
    if (!audio) return;
    audio.addEventListener('ended', function() {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'increment_play.php', true);
        var formData = new FormData();
        formData.append('song_id', <?php echo $song['id']; ?>);
        xhr.send(formData);
    });
});
</script>
<script src="../js/global-player.js"></script>
<script src="/js/xingzai-widget.js" data-api="/backend/xingzai_chat.php" data-avatar="/images/xingzai-avatar.jpg"></script>
</body>
</html>
