<?php
session_start();
require_once __DIR__ . '/backend/utils.php';
require_once __DIR__ . '/backend/config.php';

$artist = null;
$songs = [];
$dbError = null;
$userId = (int) ($_GET['id'] ?? 0);
$isLoggedIn = !empty($_SESSION['user_id']);
$uploadUrl = $isLoggedIn ? 'backend/upload.php' : 'backend/login.php';

if ($userId > 0) {
    try {
        $pdo = getPdo();
        $artistStmt = $pdo->prepare('SELECT id, username, full_name, bio, avatar_path, created_at FROM users WHERE id = ?');
        $artistStmt->execute([$userId]);
        $artist = $artistStmt->fetch();

        if ($artist) {
            $songStmt = $pdo->prepare('SELECT id, title, description, file_path, source, storage_type, archive_path, duration_label, duration_seconds, created_at, play_count FROM songs WHERE user_id = ? ORDER BY created_at DESC');
            $songStmt->execute([$userId]);
            $songs = $songStmt->fetchAll();
        }
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <title>音乐人主页 | 星浪音乐</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <link href="css/bootstrap.css" rel="stylesheet" type="text/css" media="all" />
    <link href="css/style.css" rel="stylesheet" type="text/css" media="all" />
    <link href="css/starwaves.css" rel="stylesheet" type="text/css" media="all" />
    <link href="css/font-awesome.css" rel="stylesheet">
</head>
<body>
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
                            <li><a href="backend/login.php" class="hvr-ripple-in">登录</a></li>
                            <li><a href="backend/register.php" class="hvr-ripple-in">注册</a></li>
                            <li><a href="<?php echo htmlspecialchars($uploadUrl); ?>" class="hvr-ripple-in">上传歌曲</a></li>
                        </ul>
                    </nav>
                </div>
            </nav>
        </div>
    </div>

    <div class="banner-bottom creator-showcase songs-section" style="padding-top:180px; min-height:100vh;">
        <div class="container">
            <?php if ($dbError): ?>
                <div class="songs-empty"><h4>数据库读取失败</h4><p><?php echo htmlspecialchars($dbError); ?></p></div>
            <?php elseif (!$artist): ?>
                <div class="songs-empty"><h4>没有找到这个音乐人</h4><p>可能链接不对，或者该用户还没有公开资料。</p><a class="btn btn-primary btn-lg hero-primary" href="songs.php">返回作品列表</a></div>
            <?php else: ?>
                <?php $avatar = !empty($artist['avatar_path']) ? 'backend/' . ltrim($artist['avatar_path'], '/') : 'images/starwaves-logo.svg'; ?>
                <div class="song-detail-card">
                    <span class="backend-kicker">Artist</span>
                    <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                        <img src="<?php echo htmlspecialchars($avatar); ?>" alt="avatar" style="width:92px;height:92px;border-radius:28px;object-fit:cover;">
                        <div>
                            <h1 style="margin-bottom:8px;"><?php echo htmlspecialchars($artist['full_name'] ?: $artist['username']); ?></h1>
                            <p>入驻时间：<?php echo htmlspecialchars($artist['created_at']); ?></p>
                        </div>
                    </div>
                    <div class="creator-grid" style="margin-top:28px;">
                        <div class="col-md-5">
                            <div class="creator-card" style="min-height:220px;">
                                <h4>个人简介</h4>
                                <p><?php echo nl2br(htmlspecialchars($artist['bio'] ?: '这个音乐人还没有填写个人简介。')); ?></p>
                                <div class="song-actions" style="margin-top:18px;">
                                    <a href="backend/register.php">我也要入驻</a>
                                    <a href="<?php echo htmlspecialchars($uploadUrl); ?>">上传我的作品</a>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-7">
                            <div class="creator-card" style="min-height:220px;">
                                <h4>TA 的作品</h4>
                                <?php if (empty($songs)): ?>
                                    <p>暂时还没有上传作品。</p>
                                <?php else: ?>
                                    <div class="songs-grid" style="grid-template-columns:1fr; gap:14px; margin-top:0;">
                                        <?php foreach ($songs as $song): ?>
                                            <?php
                                                $artistSongUrl = resolveSongAudioUrl($song, 'frontend');
                                                $artistDurationLabel = songDurationLabel($song);
                                            ?>
                                            <article class="song-card" style="margin:0;">
                                                <div class="song-card-head">
                                                    <h4><?php echo htmlspecialchars($song['title']); ?></h4>
                                                    <p><?php echo htmlspecialchars($song['created_at']); ?> · <?php echo (int) $song['play_count']; ?> 次播放</p>
                                                </div>
                                                <p class="muted"><?php echo htmlspecialchars(mb_strimwidth($song['description'], 0, 90, '...')); ?></p>
                                                <audio controls preload="none" class="song-player" data-title="<?php echo htmlspecialchars($song['title'], ENT_QUOTES); ?>" data-duration-label="<?php echo htmlspecialchars($artistDurationLabel, ENT_QUOTES); ?>">
                                                    <source src="<?php echo htmlspecialchars($artistSongUrl); ?>">
                                                </audio>
                                                <div class="song-actions">
                                                    <a href="single-song.php?id=<?php echo (int) $song['id']; ?>">查看详情</a>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="clearfix"></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<script src="js/global-player.js"></script>
<script src="/js/xingzai-widget.js" data-api="/backend/xingzai_chat.php" data-avatar="/images/xingzai-avatar.jpg"></script>
</body>
</html>
