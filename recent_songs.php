<?php
require_once __DIR__.'/backend/db.php';
require_once __DIR__.'/backend/utils.php';

try {
    $pdo = getPdo();
    $stmt = $pdo->prepare('SELECT id, title, file_path, source, storage_type, archive_path, duration_label, duration_seconds FROM songs ORDER BY created_at DESC LIMIT 5');
    $stmt->execute();
    $songs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    logMessage('error', 'Failed to fetch recent songs: '.$e->getMessage());
    $songs = [];
}
?>
<div class="recent-songs-widget" style="padding:10px;">
    <h3>最近上传的歌曲</h3>
    <?php if (empty($songs)): ?>
        <p>暂无歌曲。</p>
    <?php else: ?>
        <ul style="list-style:none;padding:0;">
                <?php foreach ($songs as $song): ?>
                <?php
                    $recentSongUrl = resolveSongAudioUrl($song, 'frontend');
                    $recentDurationLabel = songDurationLabel($song);
                ?>
                <li style="margin-bottom:10px;">
                    <a href="backend/single-song.php?id=<?php echo e($song['id']); ?>" style="text-decoration:none;color:#0066cc;">
                        <?php echo e($song['title']); ?>
                    </a><br>
                    <audio controls style="width:100%;margin-top:5px;" data-duration-label="<?php echo e($recentDurationLabel); ?>">
                        <source src="<?php echo e($recentSongUrl); ?>" type="audio/mpeg">
                        您的浏览器不支持音频播放。
                    </audio>
                </li>
                <?php endforeach; ?>

        </ul>
    <?php endif; ?>
</div>
