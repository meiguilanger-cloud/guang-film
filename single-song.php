<?php
require_once __DIR__ . '/backend/utils.php';
require_once __DIR__ . '/backend/config.php';

function parseLrcFile(?string $relativePath): array {
    if (!$relativePath) {
        return [];
    }
    $path = __DIR__ . '/backend/' . ltrim($relativePath, '/');
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $result = [];
    foreach ($lines as $line) {
        if (preg_match('/\[(\d{2}):(\d{2}(?:\.\d{1,2})?)\](.*)$/u', $line, $m)) {
            $time = ((int) $m[1] * 60) + (float) $m[2];
            $text = trim($m[3]);
            if ($text !== '') {
                $result[] = ['time' => $time, 'text' => $text];
            }
        }
    }
    return $result;
}

$song = null;
$dbError = null;
$id = (int) ($_GET['id'] ?? 0);
$isLoggedIn = !empty($_SESSION['user_id']);
$currentUser = null;
$uploadUrl = $isLoggedIn ? 'backend/upload.php' : 'backend/login.php';
$lrcItems = [];

if ($id > 0) {
    try {
        $pdo = getPdo();
        if ($isLoggedIn) {
            $userStmt = $pdo->prepare('SELECT username, full_name, avatar_path FROM users WHERE id = ?');
            $userStmt->execute([(int) $_SESSION['user_id']]);
            $currentUser = $userStmt->fetch();
        }

        $stmt = $pdo->prepare(
            'SELECT s.id, s.title, s.description, s.lyrics, s.lrc_path, s.file_path, s.source, s.storage_type, s.archive_path, s.duration_label, s.duration_seconds, s.mastered_file_path, s.mastered_preview_path,
                    s.mastering_status, s.created_at, s.play_count, s.user_id,
                    u.username, u.full_name, u.bio, u.avatar_path
             FROM songs s
             LEFT JOIN users u ON u.id = s.user_id
             WHERE s.id = ?'
        );
        $stmt->execute([$id]);
        $song = $stmt->fetch();
        if ($song) {
            $lrcItems = parseLrcFile($song['lrc_path'] ?? null);
        }
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <title>歌曲详情 | 星浪音乐</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <link href="css/bootstrap.css" rel="stylesheet" type="text/css" media="all" />
    <link href="css/style.css" rel="stylesheet" type="text/css" media="all" />
    <link href="css/starwaves.css" rel="stylesheet" type="text/css" media="all" />
    <link href="css/font-awesome.css" rel="stylesheet">
    <style>
        .lyrics-panel { max-height: 340px; overflow-y: auto; padding-right: 8px; }
        .lyrics-line { padding: 8px 12px; border-radius: 10px; transition: all .2s ease; color: #555; }
        .lyrics-line.active { background: rgba(230, 182, 92, 0.18); color: #1d1914; font-weight: 700; }
    </style>
</head>
<body class="<?php echo $song ? 'has-song-bottom-player' : ''; ?>">
    <div class="main_section_agile site-shell" id="home">
        <div class="agileits_w3layouts_banner_nav">
            <nav class="navbar navbar-default">
                <div class="navbar-header navbar-left">
                    <h1>
                        <a class="navbar-brand starwaves-brand" href="index.php">
                            <img src="images/starwaves-logo.svg" alt="星浪音乐" class="brand-mark" />
                            <span class="brand-text-wrap"><strong>星浪音乐</strong><em>Starwaves</em></span>
                        </a>
                    </h1>
                </div>
                <div class="collapse navbar-collapse navbar-right">
                    <nav class="menu-hover-effect menu-hover-effect-4">
                        <ul class="nav navbar-nav">
                            <li><a href="index.php" class="hvr-ripple-in">首页</a></li>
                            <li><a href="songs.php" class="hvr-ripple-in">作品列表</a></li>
                            <li><a href="<?php echo htmlspecialchars($uploadUrl); ?>" class="hvr-ripple-in">上传歌曲</a></li>
                        </ul>
                    </nav>
                    <?php if ($isLoggedIn && $currentUser): ?>
                        <?php $navAvatar = !empty($currentUser['avatar_path']) ? 'backend/' . ltrim($currentUser['avatar_path'], '/') : 'images/starwaves-logo.svg'; ?>
                        <a class="site-user-chip" href="backend/admin.php">
                            <img src="<?php echo htmlspecialchars($navAvatar); ?>" alt="avatar">
                            <span><?php echo htmlspecialchars($currentUser['full_name'] ?: $currentUser['username']); ?></span>
                        </a>
                    <?php else: ?>
                        <div class="site-entry-actions site-entry-actions-single">
                            <a class="site-login-pill" href="backend/login.php">登录</a>
                        </div>
                    <?php endif; ?>
                </div>
            </nav>
        </div>
    </div>

    <div class="banner-bottom creator-showcase songs-section" style="padding-top:180px; min-height:100vh;">
        <div class="container">
            <?php if ($dbError): ?>
                <div class="songs-empty"><h4>数据库读取失败</h4><p><?php echo htmlspecialchars($dbError); ?></p></div>
            <?php elseif (!$song): ?>
                <div class="songs-empty"><h4>没有找到这首歌</h4><p>歌曲可能还未写入数据库，或者链接参数不正确。</p><a class="btn btn-primary btn-lg hero-primary" href="songs.php">返回作品列表</a></div>
            <?php else: ?>
                <?php
                    $originalUrl = resolveSongAudioUrl($song, 'frontend');
                    $masteredPreviewUrl = (string) ($song['mastered_preview_path'] ?? '');
                    $masteredFileUrl = (string) ($song['mastered_file_path'] ?? '');
                    $activePlayerUrl = $masteredPreviewUrl !== '' ? $masteredPreviewUrl : $originalUrl;
                    $durationLabel = songDurationLabel($song, '00:00');
                ?>
                <?php $avatar = !empty($song['avatar_path']) ? 'backend/' . ltrim($song['avatar_path'], '/') : 'images/starwaves-logo.svg'; ?>
                <div class="song-detail-card">
                    <span class="backend-kicker">Song Detail</span>
                    <h1><?php echo htmlspecialchars($song['title']); ?></h1>
                    <p>上传时间：<?php echo htmlspecialchars($song['created_at']); ?> · 播放次数：<?php echo (int) $song['play_count']; ?> · 母带状态：<?php echo htmlspecialchars((string) ($song['mastering_status'] ?: 'none')); ?></p>
                    <audio id="songPlayer" controls controlsList="nodownload noplaybackrate" preload="metadata" class="song-player detail-player" data-duration-label="<?php echo htmlspecialchars($durationLabel, ENT_QUOTES); ?>"<?php if (!empty($lrcItems)): ?> data-lyrics-json="<?php echo htmlspecialchars(json_encode($lrcItems, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>"<?php endif; ?>>
                        <source src="<?php echo htmlspecialchars($activePlayerUrl); ?>">
                        你的浏览器暂不支持音频播放。
                    </audio>
                    <div class="song-actions">
                        <?php if (!empty($song['lrc_path'])): ?><a href="backend/<?php echo htmlspecialchars($song['lrc_path']); ?>" target="_blank">查看 LRC</a><?php endif; ?>
                        <a href="<?php echo htmlspecialchars($originalUrl); ?>" target="_blank">原始版本</a>
                        <?php if ($masteredPreviewUrl !== ''): ?><a href="<?php echo htmlspecialchars($masteredPreviewUrl); ?>" target="_blank">母带预览</a><?php endif; ?>
                        <?php if ($masteredFileUrl !== ''): ?><a href="<?php echo htmlspecialchars($masteredFileUrl); ?>" target="_blank">下载母带成品</a><?php endif; ?>
                        <a href="songs.php">返回作品列表</a>
                    </div>
                    <div class="creator-grid" style="margin-top:28px;">
                        <div class="col-md-7">
                            <div class="creator-card" style="min-height: 220px;">
                                <h4>歌曲简介</h4>
                                <p><?php echo nl2br(htmlspecialchars($song['description'] ?: '这首歌暂时还没有填写简介。')); ?></p>
                            </div>
                            <div class="creator-card" style="min-height: 240px; margin-top:20px;">
                                <h4>歌词<?php if (!empty($lrcItems)): ?>（跟随播放）<?php endif; ?></h4>
                                <?php if (!empty($lrcItems)): ?>
                                    <div id="lyricsPanel" class="lyrics-panel">
                                        <?php foreach ($lrcItems as $index => $item): ?>
                                            <div class="lyrics-line" data-index="<?php echo $index; ?>"><?php echo htmlspecialchars($item['text']); ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div style="white-space:pre-wrap; line-height:1.9; color:#555;">
                                        <?php echo nl2br(htmlspecialchars($song['lyrics'] ?: '这首歌暂时还没有填写歌词。')); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="creator-card" style="min-height: 240px;">
                                <h4>上传者</h4>
                                <div style="display:flex;align-items:center;gap:14px;margin-bottom:16px;">
                                    <img src="<?php echo htmlspecialchars($avatar); ?>" alt="avatar" style="width:72px;height:72px;border-radius:22px;object-fit:cover;">
                                    <div>
                                        <strong style="display:block;font-size:20px;color:#1d1914;"><a href="artist.php?id=<?php echo (int) $song['user_id']; ?>"><?php echo htmlspecialchars($song['full_name'] ?: $song['username'] ?: '未知用户'); ?></a></strong>

                                    </div>
                                </div>
                                <p><?php echo nl2br(htmlspecialchars($song['bio'] ?: '这个用户暂时还没有填写个人简介。')); ?></p>
                                <div class="song-actions" style="margin-top:16px;">
                                    <a href="artist.php?id=<?php echo (int) $song['user_id']; ?>">查看音乐人主页</a>
                                    <a href="<?php echo htmlspecialchars($uploadUrl); ?>">我也要上传</a>
                                </div>
                            </div>
                        </div>
                        <div class="clearfix"></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php if ($song): ?>
<div id="songBottomPlayer" class="song-bottom-player">
    <div class="song-bottom-player__inner">
        <div class="song-bottom-player__meta">
            <div id="songBottomTitle" class="song-bottom-player__title"><?php echo htmlspecialchars($song['title']); ?></div>
            <div id="songBottomTime" class="song-bottom-player__time">00:00 / <?php echo htmlspecialchars($durationLabel); ?></div>
        </div>
        <audio id="songBottomAudio" controls preload="metadata" class="song-bottom-player__audio" data-duration-label="<?php echo htmlspecialchars($durationLabel, ENT_QUOTES); ?>"<?php if (!empty($lrcItems)): ?> data-lyrics-json="<?php echo htmlspecialchars(json_encode($lrcItems, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>"<?php endif; ?>>
            <source src="<?php echo htmlspecialchars($activePlayerUrl); ?>">
            你的浏览器暂不支持音频播放。
        </audio>
    </div>
</div>
<?php endif; ?>
<?php if (!empty($lrcItems)): ?>
<script>
window.StarwavesCurrentLyrics = <?php echo json_encode($lrcItems, JSON_UNESCAPED_UNICODE); ?>;
const lrcItems = window.StarwavesCurrentLyrics;
const player = document.getElementById('songBottomAudio') || document.getElementById('songPlayer');
const lines = Array.from(document.querySelectorAll('.lyrics-line'));
let activeIndex = -1;

function syncLyrics(currentTime) {
    let index = -1;
    for (let i = 0; i < lrcItems.length; i++) {
        if (currentTime >= lrcItems[i].time) {
            index = i;
        } else {
            break;
        }
    }
    if (index === activeIndex || index < 0) return;
    activeIndex = index;
    lines.forEach(function (line, i) {
        line.classList.toggle('active', i === index);
    });
    const activeLine = lines[index];
    if (activeLine) {
        activeLine.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
}

if (player) {
    player.addEventListener('timeupdate', function () {
        syncLyrics(player.currentTime);
    });
}
</script>
<?php endif; ?>
<?php if ($song): ?>
<script>
(function () {
    var bottomAudio = document.getElementById('songBottomAudio');
    var bottomTime = document.getElementById('songBottomTime');
    if (!bottomAudio || !bottomTime) {
        return;
    }

    function fmt(sec) {
        if (!isFinite(sec) || sec < 0) return '00:00';
        var m = Math.floor(sec / 60);
        var s = Math.floor(sec % 60);
        return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    }

    var staticDurationLabel = bottomAudio.getAttribute('data-duration-label') || '<?php echo htmlspecialchars($durationLabel, ENT_QUOTES); ?>';
    function updateTime() {
        var totalLabel = (isFinite(bottomAudio.duration) && bottomAudio.duration > 0)
            ? fmt(bottomAudio.duration)
            : staticDurationLabel;
        bottomTime.textContent = fmt(bottomAudio.currentTime) + ' / ' + totalLabel;
    }

    bottomAudio.addEventListener('timeupdate', updateTime);
    bottomAudio.addEventListener('loadedmetadata', updateTime);
    bottomAudio.addEventListener('durationchange', updateTime);
})();
</script>
<?php endif; ?>
<script src="js/global-player.js"></script>
<script src="/js/xingzai-widget.js" data-api="/backend/xingzai_chat.php" data-avatar="/images/xingzai-avatar.jpg"></script>
</body>
</html>
