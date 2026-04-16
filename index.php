<?php
require_once __DIR__ . '/backend/utils.php';
require_once __DIR__ . '/backend/config.php';

$recentSongs = [];
$dbError = null;
$isLoggedIn = !empty($_SESSION['user_id']);
$currentUser = null;
$demoSongs = [
    ['id' => null, 'title' => '夜航星尘', 'description' => '带一点城市霓虹感的人声流行 demo，适合展示 STAR.AI 到混音母带的一体化试听路径。', 'file_path' => null, 'created_at' => '演示推荐位', 'play_count' => 982, 'user_id' => null, 'username' => null, 'full_name' => '星浪精选', 'avatar_path' => 'images/starwaves-logo.svg', 'is_demo' => true],
    ['id' => null, 'title' => '海风写成诗', 'description' => '偏治愈系的流行女声方向，用来展示编曲、混音和母带后的完整氛围感。', 'file_path' => null, 'created_at' => '演示推荐位', 'play_count' => 864, 'user_id' => null, 'username' => null, 'full_name' => '潮声计划', 'avatar_path' => 'images/starwaves-logo.svg', 'is_demo' => true],
    ['id' => null, 'title' => '失重电台', 'description' => '偏电子流行和氛围节拍的试听位，用来撑起首页榜单层次和推荐观感。', 'file_path' => null, 'created_at' => '演示推荐位', 'play_count' => 731, 'user_id' => null, 'username' => null, 'full_name' => '零度合成社', 'avatar_path' => 'images/starwaves-logo.svg', 'is_demo' => true],
    ['id' => null, 'title' => '凌晨四点半', 'description' => '偏情绪向男声作品，主打副歌记忆点和发行前整理后的完整听感。', 'file_path' => null, 'created_at' => '演示推荐位', 'play_count' => 618, 'user_id' => null, 'username' => null, 'full_name' => '凌晨俱乐部', 'avatar_path' => 'images/starwaves-logo.svg', 'is_demo' => true],
    ['id' => null, 'title' => '云层背面', 'description' => '更偏电影感的抒情作品，适合展示细节处理和空间层次感。', 'file_path' => null, 'created_at' => '演示推荐位', 'play_count' => 577, 'user_id' => null, 'username' => null, 'full_name' => '白昼航线', 'avatar_path' => 'images/starwaves-logo.svg', 'is_demo' => true],
    ['id' => null, 'title' => '回声落在黄昏里', 'description' => '偏暖色质感的流行样本，可作为人工混音和软件母带的演示落点。', 'file_path' => null, 'created_at' => '演示推荐位', 'play_count' => 533, 'user_id' => null, 'username' => null, 'full_name' => '晚风唱片', 'avatar_path' => 'images/starwaves-logo.svg', 'is_demo' => true],
];

try {
    $pdo = getPdo();
    if ($isLoggedIn) {
        $userStmt = $pdo->prepare('SELECT username, full_name, avatar_path FROM users WHERE id = ?');
        $userStmt->execute([(int) $_SESSION['user_id']]);
        $currentUser = $userStmt->fetch();
    }

    $recentSongs = $pdo->query(
        'SELECT s.id, s.title, s.description, s.file_path, s.source, s.storage_type, s.archive_path, s.duration_label, s.duration_seconds, s.created_at, s.play_count, s.user_id, u.username, u.full_name, u.avatar_path
         FROM songs s
         LEFT JOIN users u ON u.id = s.user_id
         WHERE s.visibility = \'public\'
         ORDER BY s.play_count DESC, s.created_at DESC
         LIMIT 4'
    )->fetchAll();
    foreach ($recentSongs as &$song) {
        $song['is_demo'] = false;
    }
    unset($song);
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

if (count($recentSongs) < 4) {
    $recentSongs = array_merge($recentSongs, array_slice($demoSongs, 0, 4 - count($recentSongs)));
}
?>
<?php $staticBase = rtrim(staticBaseUrl(), '/'); ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <title>星浪音乐 | 首页</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta name="description" content="星浪音乐是面向独立音乐人的创作、上传与发现平台。" />
    <meta name="keywords" content="星浪音乐,原创音乐,音乐上传,独立音乐人,音乐平台" />
    <link href="<?php echo htmlspecialchars(siteAssetUrl('css/bootstrap.css')); ?>" rel="stylesheet" type="text/css" media="all" />
    <link href="<?php echo htmlspecialchars(siteAssetUrl('css/owl.carousel.css')); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(siteAssetUrl('css/team.css')); ?>" type="text/css" media="all" />
    <link href="<?php echo htmlspecialchars(siteAssetUrl('css/style.css')); ?>" rel="stylesheet" type="text/css" media="all" />
    <link href="<?php echo htmlspecialchars(siteAssetUrl('css/starwaves.css')); ?>" rel="stylesheet" type="text/css" media="all" />
    <link href="<?php echo htmlspecialchars(siteAssetUrl('css/starwaves_top_extra.css')); ?>" rel="stylesheet" type="text/css" media="all" />
    <link href="<?php echo htmlspecialchars(siteAssetUrl('css/font-awesome.css')); ?>" rel="stylesheet">
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
                            <img src="<?php echo htmlspecialchars(siteAssetUrl('images/starwaves-logo.svg')); ?>" alt="星浪音乐" class="brand-mark" />
                            <span class="brand-text-wrap"><strong>星浪音乐</strong><em>STARWAVES MUSIC</em></span>
                        </a>
                    </h1>
                </div>
                <div class="collapse navbar-collapse navbar-right" id="bs-example-navbar-collapse-1">
                    <nav class="menu-hover-effect menu-hover-effect-4">
                        <ul class="nav navbar-nav">
                            <li class="active"><a href="index.php" class="hvr-ripple-in">首页</a></li>
                            <li><a href="star-ai.php" class="hvr-ripple-in">STAR.AI</a></li>
                            <li><a href="top_songs.php" class="hvr-ripple-in">STAR TOP音乐榜</a></li>
                            <li><a href="starwaves-mix.html" class="hvr-ripple-in">混音</a></li>
                            <li><a href="starwaves-master.html" class="hvr-ripple-in">母带</a></li>
                        </ul>
                    </nav>
                    <?php if ($isLoggedIn && $currentUser): ?>
                        <?php $navAvatar = resolveAvatarUrl(!empty($currentUser['avatar_path']) ? 'backend/' . ltrim($currentUser['avatar_path'], '/') : 'images/starwaves-logo.svg'); ?>
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
            <div class="clearfix"></div>
        </div>
    </div>

    <div class="banner_top site-hero">
        <div class="hero-overlay"></div>
        <div class="container hero-content">
            <div class="hero-copy">
                <span class="hero-kicker">STAR.AI</span>
                <h2>先用 AI 生成灵感，<span>快速开启你的音乐创作。</span></h2>
                <p>星浪音乐当前聚焦 STAR.AI 创作流程，帮助你更快生成灵感、歌词方向与候选作品，先把创作体验跑顺。</p>
                <div class="hero-actions">
                    <a class="btn btn-primary btn-lg hero-primary" href="star-ai.php" role="button">进入 STAR.AI</a>
                    <?php if (!$isLoggedIn): ?>
                        <a class="btn btn-primary btn-lg hero-secondary" href="backend/login.php" role="button">登录 / 注册</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="hero-panel">
                <div class="hero-panel-card">
                    <ul>
                        <li><span>STAR.AI</span><strong>快速出歌</strong></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="banner-bottom feature-strip"><div class="container"><div class="col-md-4 agileits_banner_bottom_left"><div id="star-ai" class="agileinfo_banner_bottom_pos section-card"><div class="w3_agileits_banner_bottom_pos_grid"><div class="col-xs-3 wthree_banner_bottom_grid_left"><div class="agile_banner_bottom_grid_left_grid hvr-radial-out"><i class="fa fa-bolt" aria-hidden="true"></i></div></div><div class="col-xs-9 wthree_banner_bottom_grid_right"><h4><a href="star-ai.php">STAR.AI</a></h4><p>一句灵感就能快速生成 2 首候选歌曲，后续还能继续接混音、母带与歌词处理，是整站的 AI 创作入口。</p></div><div class="clearfix"></div></div></div></div><div class="clearfix"></div></div></div>

    <div class="creator-showcase service-matrix-zone">
        <div class="container">
            <div class="service-matrix-grid">
                <article class="service-path-card">
                    <span class="service-path-tag">STAR.AI</span>
                    <h4>AI 音乐工作台</h4>
                    <p>快速生成灵感、风格 demo、歌词方向与候选歌曲，作为整站所有音乐服务的起点。</p>
                    <ul>
                        <li>一次生成默认返回 2 首候选歌曲</li>
                        <li>支持歌词、风格、人声或纯音乐方向</li>
                        <li>支持简单创作与精准创作双模式</li>
                    </ul>
                </article>
            </div>
        </div>
    </div>

    <div id="star-top" class="creator-showcase recent-upload-showcase dynamic-upload-zone topchart-home-zone">
        <div class="container">
            <div class="wthree_head_section topchart-home-head">
                <h3 class="w3l_header w3_agileits_header">STAR TOP音乐榜 <span>日榜 / 周榜 / 月榜 / 飙升榜</span></h3>
                <p class="chart-board-copy">按 QQ 音乐风格把榜单入口做成四榜单结构，首页先展示榜单导航和 TOP 推荐位。</p>
            </div>
            <div class="topchart-home-tabs">
                <a href="top_songs.php?chart=daily" class="topchart-home-tab"><span>DAY</span><strong>星浪热歌日榜</strong><em>24小时热度走向</em></a>
                <a href="top_songs.php?chart=weekly" class="topchart-home-tab"><span>WEEK</span><strong>星浪热播周榜</strong><em>7天连续热播</em></a>
                <a href="top_songs.php?chart=monthly" class="topchart-home-tab"><span>MONTH</span><strong>星浪月度金曲榜</strong><em>30天综合成绩</em></a>
                <a href="top_songs.php?chart=rising" class="topchart-home-tab"><span>RISING</span><strong>星浪飙升势力榜</strong><em>新势力持续上冲</em></a>
            </div>
            <?php if ($dbError): ?>
                <div class="songs-empty"><h4>数据库连接失败</h4><p><?php echo htmlspecialchars($dbError); ?></p></div>
            <?php elseif (empty($recentSongs)): ?>
                <div class="songs-empty"><h4>暂时还没有榜单歌曲</h4><p>等站内歌曲再多一些，这里会自动展示当下最热门的作品。</p><div class="hero-actions" style="justify-content:center;"><a class="btn btn-primary btn-lg hero-primary" href="backend/login.php" role="button">登录上传歌曲</a></div></div>
            <?php else: ?>
                <?php $playableRecentSongs = array_values(array_filter($recentSongs, static fn($song) => empty($song['is_demo']) && !empty($song['id']))); ?>
                <div class="topchart-home-list-head" style="display:flex;justify-content:flex-start;margin:0 0 14px;">
                    <button type="button" class="chart-play-all-btn<?php echo empty($playableRecentSongs) ? ' is-disabled' : ''; ?>"<?php echo empty($playableRecentSongs) ? ' disabled' : ''; ?> id="homePlayAllBtn">
                        <span class="chart-play-all-icon"><i class="fa fa-play"></i></span><em>全部播放</em>
                    </button>
                </div>
                <div class="topchart-home-list">
                    <?php foreach ($recentSongs as $idx => $song): ?>
                        <?php
                            $isDemoSong = !empty($song['is_demo']);
                            $avatar = resolveAvatarUrl(!empty($song['avatar_path']) ? (strpos((string) $song['avatar_path'], 'images/') === 0 ? $song['avatar_path'] : 'backend/' . ltrim($song['avatar_path'], '/')) : 'images/starwaves-logo.svg');
                            $audioSrc = $isDemoSong ? '' : resolveSongAudioUrl($song, 'frontend');
                            $durationLabel = $isDemoSong ? '--:--' : songDurationLabel($song);
                            $artistName = $song['full_name'] ?: $song['username'] ?: '星浪精选';
                        ?>
                        <article class="topchart-home-card<?php echo $isDemoSong ? '' : ' is-clickable'; ?>"<?php if (!$isDemoSong): ?> data-home-track data-track-src="<?php echo htmlspecialchars($audioSrc, ENT_QUOTES); ?>" data-track-title="<?php echo htmlspecialchars($song['title'], ENT_QUOTES); ?>" data-track-duration-label="<?php echo htmlspecialchars($durationLabel, ENT_QUOTES); ?>" data-detail-url="single-song.php?id=<?php echo (int) $song['id']; ?>"<?php endif; ?>>
                            <div class="topchart-home-rank"><?php echo $idx + 1; ?></div>
                            <div class="topchart-home-main">
                                <img src="<?php echo htmlspecialchars($avatar); ?>" alt="avatar" class="topchart-home-avatar">
                                <div class="topchart-home-copy">
                                    <h4><?php echo htmlspecialchars($song['title']); ?></h4>
                                    <p><?php echo htmlspecialchars($artistName); ?></p>
                                    <span><?php echo (int) $song['play_count']; ?> 次播放 · <?php echo htmlspecialchars($durationLabel); ?></span>
                                </div>
                            </div>
                            <div class="topchart-home-actions">
                                <?php if ($isDemoSong): ?>
                                    <a href="top_songs.php">进入榜单</a>
                                <?php else: ?>
                                    <button type="button" class="chart-play-all-btn" data-home-play data-track-src="<?php echo htmlspecialchars($audioSrc, ENT_QUOTES); ?>" data-track-title="<?php echo htmlspecialchars($song['title'], ENT_QUOTES); ?>" data-track-duration-label="<?php echo htmlspecialchars($durationLabel, ENT_QUOTES); ?>" aria-label="播放 <?php echo htmlspecialchars($song['title'], ENT_QUOTES); ?>"><span class="chart-play-all-icon"><i class="fa fa-play"></i></span></button>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="topchart-home-cta">
                    <a class="btn btn-primary btn-lg hero-primary" href="top_songs.php" role="button">进入完整 STAR TOP 榜单</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="globalBottomPlayer" class="song-bottom-player">
        <div class="song-bottom-player__inner">
            <div class="song-bottom-player__meta">
                <div id="globalBottomTitle" class="song-bottom-player__title">请选择歌曲</div>
                <div id="globalBottomTime" class="song-bottom-player__time">00:00 / 00:00</div>
            </div>
            <audio id="globalBottomAudio" controls preload="metadata" class="song-bottom-player__audio"></audio>
        </div>
    </div>

    <div class="footer"><div class="f-bg-w3l"><div class="container"><div class="col-md-4 w3layouts_footer_grid"><h2>星浪 <span>音乐</span></h2></div><div class="col-md-8 w3layouts_footer_grid"><ul class="w3l_footer_nav"><li><a href="index.php" class="active">首页</a></li><li><a href="#star-ai">STAR.AI</a></li><li><a href="backend/login.php">登录</a></li></ul><p>Copyright &copy; 2026 Starwaves. 星浪音乐，先用 STAR.AI 出歌，再把作品推进到音乐榜展示与发现。</p></div><div class="clearfix"></div></div></div></div>

    <script>
    window.StarwavesStaticBase = <?php echo json_encode($staticBase, JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script type="text/javascript" src="<?php echo htmlspecialchars(siteAssetUrl('js/jquery-2.1.4.min.js')); ?>"></script>
    <script type="text/javascript" src="<?php echo htmlspecialchars(siteAssetUrl('js/bootstrap-3.1.1.min.js')); ?>"></script>
    <script src="<?php echo htmlspecialchars(siteAssetUrl('js/global-player.js')); ?>"></script>
    <script>
    (function () {
        function playTrack(button) {
            if (!button || !window.StarwavesGlobalPlayer) {
                return;
            }
            var src = button.getAttribute('data-track-src') || '';
            if (!src) {
                return;
            }
            window.StarwavesGlobalPlayer.playTrack({
                src: src,
                title: button.getAttribute('data-track-title') || '当前歌曲',
                durationLabel: button.getAttribute('data-track-duration-label') || '--:--'
            });
        }

        document.querySelectorAll('[data-home-play]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.stopPropagation();
                playTrack(button);
            });
        });

        document.querySelectorAll('.topchart-home-card.is-clickable').forEach(function (card) {
            card.addEventListener('click', function () {
                var detailUrl = card.getAttribute('data-detail-url') || '';
                if (detailUrl) {
                    window.location.href = detailUrl;
                }
            });
        });

        var homeQueue = Array.from(document.querySelectorAll('[data-home-track]')).map(function (item) {
            return {
                src: item.getAttribute('data-track-src') || '',
                title: item.getAttribute('data-track-title') || '当前歌曲',
                durationLabel: item.getAttribute('data-track-duration-label') || '--:--'
            };
        }).filter(function (item) {
            return !!item.src;
        });
        var playAllBtn = document.getElementById('homePlayAllBtn');
        if (playAllBtn && homeQueue.length) {
            playAllBtn.addEventListener('click', function () {
                playTrack(playAllBtn);
            });
            playAllBtn.setAttribute('data-track-src', homeQueue[0].src);
            playAllBtn.setAttribute('data-track-title', homeQueue[0].title);
            playAllBtn.setAttribute('data-track-duration-label', homeQueue[0].durationLabel);
        }
    })();
    </script>
<script src="/js/xingzai-widget.js" data-api="/backend/xingzai_chat.php" data-avatar="/images/xingzai-avatar.jpg"></script>
</body>
</html>
