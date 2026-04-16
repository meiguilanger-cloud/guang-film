<?php
require_once __DIR__ . '/backend/utils.php';
require_once __DIR__ . '/backend/config.php';

$songs = [];
$dbError = null;
$isLoggedIn = !empty($_SESSION['user_id']);
$uploadUrl = $isLoggedIn ? 'backend/upload.php' : 'backend/login.php';

try {
    $pdo = getPdo();
    $songs = $pdo->query(
        'SELECT s.id, s.title, s.description, s.file_path, s.storage_type, s.archive_path, s.duration_label, s.duration_seconds, s.mastered_file_path, s.mastered_preview_path, s.mastering_status,
                s.created_at, s.play_count, s.user_id, u.username, u.full_name, u.avatar_path
         FROM songs s
         LEFT JOIN users u ON u.id = s.user_id
         ORDER BY s.created_at DESC'
    )->fetchAll();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <title>作品列表 | 星浪音乐</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta name="description" content="浏览星浪音乐已上传作品，在线播放并查看歌曲信息。" />
    <link href="css/bootstrap.css" rel="stylesheet" type="text/css" media="all" />
    <link href="css/style.css" rel="stylesheet" type="text/css" media="all" />
    <link href="css/starwaves.css" rel="stylesheet" type="text/css" media="all" />
    <link href="css/font-awesome.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Raleway:400,600,700,800" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:400,600,700" rel="stylesheet">
</head>
<body class="has-song-bottom-player">
    <div class="main_section_agile site-shell" id="home">
        <div class="agileits_w3layouts_banner_nav">
            <nav class="navbar navbar-default">
                <div class="navbar-header navbar-left">
                    <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#bs-example-navbar-collapse-1">
                        <span class="sr-only">切换导航</span>
                        <span class="icon-bar"></span>
                        <span class="icon-bar"></span>
                        <span class="icon-bar"></span>
                    </button>
                    <h1>
                        <a class="navbar-brand starwaves-brand" href="index.php">
                            <img src="images/starwaves-logo.svg" alt="星浪音乐" class="brand-mark" />
                            <span class="brand-text-wrap"><strong>星浪音乐</strong><em>Starwaves</em></span>
                        </a>
                    </h1>
                </div>
                <div class="collapse navbar-collapse navbar-right" id="bs-example-navbar-collapse-1">
                    <nav class="menu-hover-effect menu-hover-effect-4">
                        <ul class="nav navbar-nav">
                            <li><a href="index.php" class="hvr-ripple-in">首页</a></li>
                            <li class="active"><a href="songs.php" class="hvr-ripple-in">作品列表</a></li>
                            <li><a href="backend/login.php" class="hvr-ripple-in">登录</a></li>
                            <li><a href="backend/register.php" class="hvr-ripple-in">注册</a></li>
                            <li><a href="backend/admin.php" class="hvr-ripple-in">后台</a></li>
                            <li><a href="<?php echo htmlspecialchars($uploadUrl); ?>" class="hvr-ripple-in">上传歌曲</a></li>
                        </ul>
                    </nav>
                </div>
            </nav>
            <div class="clearfix"></div>
        </div>
    </div>

    <div class="agile_inner site-hero inner-hero songs-hero">
        <div class="hero-overlay"></div>
        <div class="container hero-content inner-hero-content">
            <div class="hero-copy">
                <span class="hero-kicker">Songs & Player</span>
                <h2>上传后的作品，<span>现在可以直接在站内展示和试听。</span></h2>
                <p>这里读取的就是数据库里的真实歌曲记录。后台上传一首歌，这里就会自动出现一张作品卡片。</p>
            </div>
        </div>
    </div>

    <div class="services-breadcrumb"><div class="agile_inner_breadcrumb"><ul class="w3_short"><li><a href="index.php">首页</a><span>|</span></li><li>作品列表</li></ul></div></div>

    <div class="banner-bottom creator-showcase songs-section">
        <div class="container">
            <div class="wthree_head_section">
                <h3 class="w3l_header w3_agileits_header">最新上传 <span>站内试听</span></h3>
            </div>
            <?php if ($dbError): ?>
                <div class="songs-empty"><h4>数据库暂时没有连上</h4><p><?php echo htmlspecialchars($dbError); ?></p></div>
            <?php elseif (empty($songs)): ?>
                <div class="songs-empty"><h4>还没有歌曲记录</h4><p>先去上传一首歌，上传成功后这里会自动出现作品卡片和在线播放器。</p><div class="hero-actions" style="justify-content:center;"><a class="btn btn-primary btn-lg hero-primary" href="<?php echo htmlspecialchars($uploadUrl); ?>" role="button">去上传作品</a></div></div>
            <?php else: ?>
                <div class="songs-grid">
                    <?php foreach ($songs as $song): ?>
                        <?php
                            $avatar = !empty($song['avatar_path']) ? 'backend/' . ltrim($song['avatar_path'], '/') : 'images/starwaves-logo.svg';
                            $previewUrl = !empty($song['mastered_preview_path'])
                                ? (string) $song['mastered_preview_path']
                                : resolveSongAudioUrl($song, 'frontend');
                            $durationLabel = songDurationLabel($song);
                        ?>
                        <article class="song-card">
                            <div class="song-card-head">
                                <span class="song-badge"><?php echo !empty($song['mastered_preview_path']) ? 'MASTERED' : 'NEW'; ?></span>
                                <h4><?php echo htmlspecialchars($song['title']); ?></h4>
                                <p>上传者：<a href="artist.php?id=<?php echo (int) $song['user_id']; ?>"><?php echo htmlspecialchars($song['full_name'] ?: $song['username'] ?: '未知用户'); ?></a></p>
                            </div>
                            <ul class="song-meta">
                                <li><i class="fa fa-calendar-o" aria-hidden="true"></i><?php echo htmlspecialchars($song['created_at']); ?></li>
                                <li><i class="fa fa-play-circle-o" aria-hidden="true"></i><?php echo (int) $song['play_count']; ?> 次播放</li>
                                <li><i class="fa fa-magic" aria-hidden="true"></i><?php echo htmlspecialchars((string) ($song['mastering_status'] ?: 'none')); ?></li>
                            </ul>
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
                                <img src="<?php echo htmlspecialchars($avatar); ?>" alt="avatar" style="width:44px;height:44px;border-radius:14px;object-fit:cover;">
                                <span class="muted"><?php echo htmlspecialchars(mb_strimwidth($song['description'], 0, 70, '...')); ?></span>
                            </div>
                            <audio controls controlsList="nodownload noplaybackrate" preload="none" class="song-player" data-title="<?php echo htmlspecialchars($song['title'], ENT_QUOTES); ?>" data-duration-label="<?php echo htmlspecialchars($durationLabel, ENT_QUOTES); ?>">
                                <source src="<?php echo htmlspecialchars($previewUrl); ?>">
                                你的浏览器暂不支持音频播放。
                            </audio>
                            <div class="song-playing-badge">正在播放</div>
                            <div class="song-actions">
                                <a href="single-song.php?id=<?php echo (int) $song['id']; ?>">查看详情</a>
                                <a href="artist.php?id=<?php echo (int) $song['user_id']; ?>">查看音乐人</a>
                                <?php if (!empty($song['mastered_file_path'])): ?>
                                    <a href="<?php echo htmlspecialchars($song['mastered_file_path']); ?>" target="_blank">母带成品</a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="footer"><div class="f-bg-w3l"><div class="container"><div class="col-md-4 w3layouts_footer_grid"><h2>星浪 <span>音乐</span></h2></div><div class="col-md-8 w3layouts_footer_grid"><ul class="w3l_footer_nav"><li><a href="index.php">首页</a></li><li><a href="songs.php" class="active">作品列表</a></li><li><a href="backend/register.php">注册</a></li><li><a href="backend/login.php">登录</a></li><li><a href="backend/admin.php">后台</a></li><li><a href="backend/upload.php">上传歌曲</a></li></ul><p>Copyright &copy; 2026 Starwaves. 星浪音乐，给作品一个更像作品的入口。</p></div><div class="clearfix"></div></div></div></div>

    <div id="globalBottomPlayer" class="song-bottom-player">
        <div class="song-bottom-player__inner">
            <div class="song-bottom-player__meta">
                <div id="globalBottomTitle" class="song-bottom-player__title">请选择歌曲</div>
                <div id="globalBottomTime" class="song-bottom-player__time">00:00 / 00:00</div>
            </div>
            <audio id="globalBottomAudio" controls preload="metadata" class="song-bottom-player__audio"></audio>
        </div>
    </div>

    <script type="text/javascript" src="js/jquery-2.1.4.min.js"></script>
    <script type="text/javascript" src="js/bootstrap-3.1.1.min.js"></script>
<script src="js/global-player.js"></script>
<script src="/js/xingzai-widget.js" data-api="/backend/xingzai_chat.php" data-avatar="/images/xingzai-avatar.jpg"></script>
</body>
</html>
