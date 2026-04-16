<?php
require_once __DIR__ . '/backend/utils.php';
require_once __DIR__ . '/backend/config.php';

$isLoggedIn = !empty($_SESSION['user_id']);
$currentUser = null;
$dbError = null;

$chartConfigs = [
    'daily' => [
        'name' => 'STAR TOP 日榜',
        'subtitle' => '星浪热歌日榜',
        'hero' => '24 小时热度走向',
        'description' => '按最近 24 小时热度与发布时间综合排序，适合展示当下最活跃的歌曲。',
        'badge' => 'DAY',
        'cover' => 'images/starwaves-logo.svg',
        'updated' => '每日 10:00 更新',
        'theme' => 'theme-day',
    ],
    'weekly' => [
        'name' => 'STAR TOP 周榜',
        'subtitle' => '星浪热播周榜',
        'hero' => '7 天连续热播',
        'description' => '按近 7 天播放表现与稳定度排序，适合观察持续热度作品。',
        'badge' => 'WEEK',
        'cover' => 'images/starwaves-logo.svg',
        'updated' => '每周一 10:00 更新',
        'theme' => 'theme-week',
    ],
    'monthly' => [
        'name' => 'STAR TOP 月榜',
        'subtitle' => '星浪月度金曲榜',
        'hero' => '30 天综合成绩',
        'description' => '按近 30 天综合热度排行，适合承接整月成绩最亮眼的作品。',
        'badge' => 'MONTH',
        'cover' => 'images/starwaves-logo.svg',
        'updated' => '每月 1 日 12:00 更新',
        'theme' => 'theme-month',
    ],
    'rising' => [
        'name' => 'STAR TOP 飙升榜',
        'subtitle' => '星浪飙升势力榜',
        'hero' => '新势力持续上冲',
        'description' => '更偏向新歌、增长速度和近期关注度，适合承接即将爆发的新作品。',
        'badge' => 'RISING',
        'cover' => 'images/starwaves-logo.svg',
        'updated' => '每 6 小时滚动更新',
        'theme' => 'theme-rising',
    ],
];

$activeChart = $_GET['chart'] ?? 'daily';
if (!isset($chartConfigs[$activeChart])) {
    $activeChart = 'daily';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;

$demoPool = [
    ['title' => '夜航星尘', 'artist' => '星浪精选', 'description' => '城市夜航感的流行人声单曲，副歌带一点霓虹闪烁的漂浮感。', 'play_count' => 982],
    ['title' => '海风写成诗', 'artist' => '潮声计划', 'description' => '治愈系流行女声方向，海边感氛围和旋律记忆点都很清楚。', 'play_count' => 934],
    ['title' => '失重电台', 'artist' => '零度合成社', 'description' => '电子与氛围节拍方向，主歌克制，副歌瞬间拉开空间。', 'play_count' => 901],
    ['title' => '凌晨四点半', 'artist' => '凌晨俱乐部', 'description' => '情绪向男声作品，偏夜间电台质感，适合榜单试听展示。', 'play_count' => 865],
    ['title' => '云层背面', 'artist' => '白昼航线', 'description' => '电影感抒情作品，层次舒服，适合做榜单稳定曝光位。', 'play_count' => 842],
    ['title' => '回声落在黄昏里', 'artist' => '晚风唱片', 'description' => '暖色调流行作品，鼓组克制，人声位置靠前。', 'play_count' => 818],
    ['title' => '微光预报', 'artist' => '北纬一度', 'description' => '偏轻盈的合成器流行，hook 很短但很抓耳。', 'play_count' => 786],
    ['title' => '倒带成海', 'artist' => '潮汐录像带', 'description' => '旋律线条很顺，编曲是偏清爽的海盐味流行。', 'play_count' => 758],
    ['title' => '月台尽头', 'artist' => '雾港列车', 'description' => '偏成人抒情和城市叙事，适合周榜和月榜长期停留。', 'play_count' => 741],
    ['title' => '你路过的银河', 'artist' => '银河便利店', 'description' => '轻科幻氛围的流行歌，主打人声和空间感。', 'play_count' => 722],
    ['title' => '半岛失眠地图', 'artist' => '失眠半岛', 'description' => '偏夜晚公路电影感，适合飙升榜的试听卡展示。', 'play_count' => 704],
    ['title' => '海盐色对白', 'artist' => '晴空事务所', 'description' => '温暖清新的男女对唱，画面感强。', 'play_count' => 689],
    ['title' => '夏末慢镜头', 'artist' => '薄荷放映厅', 'description' => '节奏感更轻，适合做白天场景的播放陪伴。', 'play_count' => 674],
    ['title' => '无人岛电波', 'artist' => '回声邮局', 'description' => '微电子配器加柔和主唱，整体很适合榜单包装。', 'play_count' => 652],
    ['title' => '雾里看见晴天', 'artist' => '青曜工作室', 'description' => '偏励志向的流行抒情，适合月榜尾部铺量。', 'play_count' => 633],
    ['title' => '晚安海平线', 'artist' => '落日频道', 'description' => '收尾型抒情作品，情绪很稳，适合作为榜单收束。', 'play_count' => 619],
];

function scoreSong(array $song, string $chart): float {
    $playCount = (int) ($song['play_count'] ?? 0);
    $createdAt = strtotime((string) ($song['created_at'] ?? 'now')) ?: time();
    $ageHours = max(1, (time() - $createdAt) / 3600);
    return match ($chart) {
        'daily' => $playCount * 0.78 + max(0, 240 - $ageHours * 3.2),
        'weekly' => $playCount * 0.98 + max(0, 280 - $ageHours * 1.4),
        'monthly' => $playCount * 1.16 + max(0, 320 - $ageHours * 0.5),
        'rising' => $playCount * 0.62 + max(0, 420 - $ageHours * 5.2),
        default => $playCount,
    };
}

function buildDemoSongs(array $pool, string $chart): array {
    $result = [];
    $timeBase = strtotime('2026-04-14 12:00:00');
    foreach ($pool as $idx => $item) {
        $delta = match ($chart) {
            'daily' => [0, -12, -24, -36, -48, -60, -72, -84, -96, -108, -120, -132, -144, -156, -168, -180][$idx] ?? (-12 * $idx),
            'weekly' => [0, -24, -36, -60, -84, -108, -132, -156, -180, -204, -228, -252, -276, -300, -324, -348][$idx] ?? (-24 * $idx),
            'monthly' => [0, -72, -108, -144, -180, -216, -252, -288, -324, -360, -396, -432, -468, -504, -540, -576][$idx] ?? (-48 * $idx),
            'rising' => [0, -6, -12, -18, -24, -30, -36, -42, -48, -54, -60, -66, -72, -78, -84, -90][$idx] ?? (-8 * $idx),
            default => -12 * $idx,
        };
        $boost = match ($chart) {
            'daily' => max(0, 140 - $idx * 5),
            'weekly' => max(0, 110 - $idx * 4),
            'monthly' => max(0, 90 - $idx * 3),
            'rising' => max(0, 180 - $idx * 7),
            default => 0,
        };
        $result[] = [
            'id' => null,
            'title' => $item['title'],
            'description' => $item['description'],
            'file_path' => null,
            'created_at' => date('Y-m-d H:i:s', $timeBase + ($delta * 3600)),
            'play_count' => $item['play_count'] + $boost,
            'user_id' => null,
            'username' => null,
            'full_name' => $item['artist'],
            'avatar_path' => 'images/starwaves-logo.svg',
            'is_demo' => true,
        ];
    }
    foreach ($result as &$song) {
        $song['_score'] = scoreSong($song, $chart);
    }
    unset($song);
    usort($result, fn($a, $b) => ($b['_score'] <=> $a['_score']) ?: ((int) $b['play_count'] <=> (int) $a['play_count']));
    return $result;
}

try {
    $pdo = getPdo();
    if ($isLoggedIn) {
        $userStmt = $pdo->prepare('SELECT username, full_name, avatar_path FROM users WHERE id = ?');
        $userStmt->execute([(int) $_SESSION['user_id']]);
        $currentUser = $userStmt->fetch();
    }
    $stmt = $pdo->query('SELECT s.id, s.title, s.description, s.file_path, s.source, s.storage_type, s.archive_path, s.duration_label, s.duration_seconds, s.created_at, s.play_count, s.user_id, s.visibility, u.username, u.full_name, u.avatar_path FROM songs s LEFT JOIN users u ON u.id = s.user_id WHERE s.visibility = \'public\' ORDER BY s.created_at DESC LIMIT 120');
    $allSongs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($allSongs as &$song) {
        $song['is_demo'] = false;
        $song['_score'] = scoreSong($song, $activeChart);
    }
    unset($song);
    usort($allSongs, fn($a, $b) => ($b['_score'] <=> $a['_score']) ?: ((int) $b['play_count'] <=> (int) $a['play_count']));
} catch (Throwable $e) {
    $dbError = $e->getMessage();
    $allSongs = [];
}

$demoSongs = buildDemoSongs($demoPool, $activeChart);
$mergedSongs = $allSongs;
if (count($mergedSongs) < 40) {
    foreach ($demoSongs as $demoSong) {
        $mergedSongs[] = $demoSong;
    }
}
if (count($mergedSongs) < 40) {
    foreach ($demoSongs as $demoSong) {
        $mergedSongs[] = $demoSong;
    }
}
foreach ($mergedSongs as &$song) {
    if (!isset($song['_score'])) {
        $song['_score'] = scoreSong($song, $activeChart);
    }
}
unset($song);
usort($mergedSongs, fn($a, $b) => ($b['_score'] <=> $a['_score']) ?: ((int) $b['play_count'] <=> (int) $a['play_count']));

$totalSongs = count($mergedSongs);
$totalPages = max(1, (int) ceil($totalSongs / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$topSongs = array_slice($mergedSongs, $offset, $perPage);
$chartMeta = $chartConfigs[$activeChart];
$rankStart = $offset + 1;

$playableQueue = [];
foreach ($topSongs as $song) {
    if (!empty($song['is_demo']) || empty($song['file_path'])) {
        continue;
    }
    $queueSrc = resolveSongAudioUrl($song, 'frontend');
    $playableQueue[] = [
        'src' => $queueSrc,
        'title' => (string) $song['title'],
        'artist' => (string) ($song['full_name'] ?: $song['username'] ?: '星浪精选'),
        'durationLabel' => songDurationLabel($song),
    ];
}

$showcaseCharts = [];
foreach ($chartConfigs as $key => $config) {
    $showcaseCharts[$key] = array_slice(buildDemoSongs($demoPool, $key), 0, 3);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($chartMeta['name']); ?> | 星浪音乐</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="浏览星浪音乐 STAR TOP 音乐榜，查看日榜、周榜、月榜和飙升榜。" />
<link href="css/bootstrap.css" rel="stylesheet" type="text/css" media="all" />
<link href="css/style.css" rel="stylesheet" type="text/css" media="all" />
<link href="css/starwaves.css" rel="stylesheet" type="text/css" media="all" />
<link href="css/starwaves_top_extra.css" rel="stylesheet" type="text/css" media="all" />
<link href="css/font-awesome.css" rel="stylesheet">
</head>
<body class="has-song-bottom-player topchart-body">
<div class="main_section_agile site-shell" id="home"><div class="agileits_w3layouts_banner_nav"><nav class="navbar navbar-default"><div class="navbar-header navbar-left"><button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#bs-example-navbar-collapse-1"><span class="sr-only">切换导航</span><span class="icon-bar"></span><span class="icon-bar"></span><span class="icon-bar"></span></button><h1><a class="navbar-brand starwaves-brand" href="index.php"><img src="images/starwaves-logo.svg" alt="星浪音乐" class="brand-mark" /><span class="brand-text-wrap"><strong>星浪音乐</strong><em>STARWAVES MUSIC</em></span></a></h1></div><div class="collapse navbar-collapse navbar-right" id="bs-example-navbar-collapse-1"><nav class="menu-hover-effect menu-hover-effect-4"><ul class="nav navbar-nav"><li><a href="index.php" class="hvr-ripple-in">首页</a></li><li><a href="star-ai.php" class="hvr-ripple-in">STAR.AI</a></li><li class="active"><a href="top_songs.php" class="hvr-ripple-in">STAR TOP音乐榜</a></li><li><a href="starwaves-mix.php" class="hvr-ripple-in">混音</a></li><li><a href="starwaves-master.php" class="hvr-ripple-in">母带</a></li></ul></nav><?php if ($isLoggedIn && $currentUser): ?><?php $navAvatar = !empty($currentUser['avatar_path']) ? 'backend/' . ltrim($currentUser['avatar_path'], '/') : 'images/starwaves-logo.svg'; ?><a class="site-user-chip" href="backend/admin.php"><img src="<?php echo htmlspecialchars($navAvatar); ?>" alt="avatar"><span><?php echo htmlspecialchars($currentUser['full_name'] ?: $currentUser['username']); ?></span></a><?php else: ?><div class="site-entry-actions"><a class="site-login-pill site-register-pill" href="backend/register.php">注册</a><a class="site-login-pill" href="backend/login.php">登录</a></div><?php endif; ?></div></nav><div class="clearfix"></div></div></div>

<div class="agile_inner site-hero inner-hero topchart-hero"><div class="hero-overlay"></div><div class="container hero-content inner-hero-content topchart-hero-content"><div class="hero-copy"><span class="hero-kicker">QQ 风格榜单对照版 · STAR TOP</span><h2><?php echo htmlspecialchars($chartMeta['subtitle']); ?>，<span><?php echo htmlspecialchars($chartMeta['hero']); ?></span></h2><p><?php echo htmlspecialchars($chartMeta['description']); ?></p><div class="hero-actions"><a class="btn btn-primary btn-lg hero-primary" href="#chart-board" role="button">进入榜单</a><a class="btn btn-primary btn-lg hero-secondary" href="index.php#star-top" role="button">返回首页推荐位</a></div></div><div class="hero-panel"><div class="hero-panel-card topchart-panel-card"><ul><li><span>当前榜单</span><strong><?php echo htmlspecialchars($chartMeta['subtitle']); ?></strong></li><li><span>榜单规模</span><strong><?php echo $totalSongs; ?> 首</strong></li><li><span>更新时间</span><strong><?php echo htmlspecialchars($chartMeta['updated']); ?></strong></li></ul></div></div></div></div>

<div class="services-breadcrumb"><div class="agile_inner_breadcrumb"><ul class="w3_short"><li><a href="index.php">首页</a><span>|</span></li><li>STAR TOP音乐榜</li></ul></div></div>

<div class="topchart-reference-zone">
  <div class="container">
    <div class="wthree_head_section chart-board-head">
      <h3 class="w3l_header w3_agileits_header">STAR TOP音乐榜 <span>统一尺寸 · 参考图同类结构</span></h3>
      <p class="chart-board-copy">四个榜单卡片统一尺寸，左侧榜单封面，右侧展示 Top 3 歌曲，真实歌曲不足时先用演示歌曲填满。</p>
    </div>
    <div class="topchart-reference-grid">
      <?php foreach ($chartConfigs as $key => $config): ?>
        <article class="topchart-reference-card <?php echo htmlspecialchars($config['theme']); ?> <?php echo $key === $activeChart ? 'is-active' : ''; ?>">
          <a class="topchart-reference-main" href="top_songs.php?chart=<?php echo urlencode($key); ?>">
            <div class="topchart-reference-cover">
              <span class="topchart-reference-badge"><?php echo htmlspecialchars($config['badge']); ?></span>
              <img src="<?php echo htmlspecialchars($config['cover']); ?>" alt="cover">
              <div class="topchart-reference-cover-copy">
                <h4><?php echo htmlspecialchars($config['subtitle']); ?></h4>
                <p><?php echo htmlspecialchars($config['updated']); ?></p>
              </div>
            </div>
            <div class="topchart-reference-list">
              <ol>
                <?php foreach ($showcaseCharts[$key] as $songIndex => $song): ?>
                  <li>
                    <strong><?php echo $songIndex + 1; ?></strong>
                    <div>
                      <span><?php echo htmlspecialchars($song['title']); ?></span>
                      <em><?php echo htmlspecialchars($song['full_name'] ?: '星浪精选'); ?></em>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ol>
            </div>
          </a>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="topchart-tabs-zone"><div class="container"><div class="topchart-tabs"><?php foreach ($chartConfigs as $key => $config): ?><a class="topchart-tab <?php echo $key === $activeChart ? 'active' : ''; ?>" href="top_songs.php?chart=<?php echo urlencode($key); ?>"><span class="topchart-tab__badge"><?php echo htmlspecialchars($config['badge']); ?></span><strong><?php echo htmlspecialchars($config['subtitle']); ?></strong><em><?php echo htmlspecialchars($config['hero']); ?></em></a><?php endforeach; ?></div></div></div>

<div id="chart-board" class="creator-showcase chart-board-zone"><div class="container"><div class="wthree_head_section chart-board-head"><div class="chart-board-head-row"><div><h3 class="w3l_header w3_agileits_header"><?php echo htmlspecialchars($chartMeta['name']); ?> <span>第 <?php echo $page; ?> 页 / 共 <?php echo $totalPages; ?> 页</span></h3><p class="chart-board-copy">当前榜单采用统一卡片尺寸，真实歌曲会优先展示，不足部分由演示歌曲自动补齐。</p></div><button type="button" class="chart-play-all-btn<?php echo empty($playableQueue) ? ' is-disabled' : ''; ?>"<?php echo empty($playableQueue) ? ' disabled' : ''; ?> data-play-all-scope="chart-board"><span class="chart-play-all-icon"><i class="fa fa-play"></i></span><em>全部播放</em></button></div></div><?php if ($dbError): ?><div class="songs-empty"><h4>数据库连接失败</h4><p><?php echo htmlspecialchars($dbError); ?></p></div><?php endif; ?><div class="chart-rank-list compact-reference-list"><?php foreach ($topSongs as $index => $song): ?><?php $rank = $rankStart + $index; $isDemoSong = !empty($song['is_demo']); $avatar = !empty($song['avatar_path']) ? (strpos((string) $song['avatar_path'], 'images/') === 0 ? $song['avatar_path'] : 'backend/' . ltrim($song['avatar_path'], '/')) : 'images/starwaves-logo.svg'; $audioSrc = $isDemoSong ? '' : resolveSongAudioUrl($song, 'frontend'); ?><article class="chart-rank-card compact-card"><div class="chart-rank-number-wrap"><span class="chart-rank-number"><?php echo (string) $rank; ?></span><?php if ($rank <= 3): ?><span class="chart-rank-tag">TOP</span><?php endif; ?></div><div class="chart-rank-main"><div class="chart-rank-body"><img src="<?php echo htmlspecialchars($avatar); ?>" alt="avatar" class="chart-rank-avatar"><div class="chart-rank-content"><h4><?php echo htmlspecialchars($song['title']); ?></h4><p class="chart-rank-artist"><?php echo htmlspecialchars($song['full_name'] ?: $song['username'] ?: '星浪精选'); ?></p><p class="chart-rank-desc"><?php echo htmlspecialchars(mb_strimwidth((string) ($song['description'] ?: '这首歌正在作为 STAR TOP 音乐榜展示样本。'), 0, 100, '...')); ?></p><ul class="chart-rank-meta"><li><i class="fa fa-play-circle-o"></i><?php echo (int) ($song['play_count'] ?? 0); ?> 次播放</li><li><i class="fa fa-clock-o"></i><?php echo htmlspecialchars(substr((string) ($song['created_at'] ?? '刚刚更新'), 0, 10)); ?></li></ul></div></div></div><div class="chart-rank-actions"><?php if ($isDemoSong): ?><a href="top_songs.php?chart=<?php echo urlencode($activeChart); ?>">榜单展示</a><a href="backend/login.php">上传真歌</a><?php else: ?><a href="single-song.php?id=<?php echo (int) $song['id']; ?>">查看详情</a><a href="artist.php?id=<?php echo (int) $song['user_id']; ?>">查看音乐人</a><?php endif; ?></div></article><?php endforeach; ?></div><div class="chart-pagination"><?php if ($page > 1): ?><a class="chart-page-btn" href="top_songs.php?chart=<?php echo urlencode($activeChart); ?>&page=<?php echo $page - 1; ?>">上一页</a><?php else: ?><span class="chart-page-btn disabled">上一页</span><?php endif; ?><div class="chart-page-indicator"><?php echo htmlspecialchars($chartMeta['subtitle']); ?> · 当前第 <?php echo $page; ?> 页</div><?php if ($page < $totalPages): ?><a class="chart-page-btn" href="top_songs.php?chart=<?php echo urlencode($activeChart); ?>&page=<?php echo $page + 1; ?>">下一页</a><?php else: ?><span class="chart-page-btn disabled">下一页</span><?php endif; ?></div></div></div>

<div class="footer"><div class="f-bg-w3l"><div class="container"><div class="col-md-4 w3layouts_footer_grid"><h2>星浪 <span>音乐</span></h2></div><div class="col-md-8 w3layouts_footer_grid"><ul class="w3l_footer_nav"><li><a href="index.php">首页</a></li><li><a href="star-ai.php">STAR.AI</a></li><li><a href="top_songs.php" class="active">STAR TOP音乐榜</a></li><li><a href="backend/login.php">登录</a></li></ul></div><div class="clearfix"></div></div></div></div>
<script type="text/javascript" src="js/jquery-2.1.4.min.js"></script><script type="text/javascript" src="js/bootstrap-3.1.1.min.js"></script>
<script src="js/global-player.js"></script>
<script>
window.StarwavesChartQueue = <?php echo json_encode($playableQueue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
(function () {
    var queue = Array.isArray(window.StarwavesChartQueue) ? window.StarwavesChartQueue : [];
    var currentIndex = -1;
    var playerReadyTries = 0;

    function getPlayerAudio() {
        return document.getElementById('starwavesGlobalPlayerAudio');
    }

    function playQueueIndex(index) {
        if (!window.StarwavesGlobalPlayer || !queue[index]) {
            return;
        }
        currentIndex = index;
        var item = queue[index];
        window.StarwavesGlobalPlayer.playTrack({
            src: item.src,
            title: item.title + ' - ' + item.artist,
            durationLabel: item.durationLabel || '--:--',
            currentTime: 0,
            lyrics: []
        });
        window.StarwavesGlobalPlayer.show();
    }

    function bindQueueEnd() {
        var audio = getPlayerAudio();
        if (!audio) {
            if (playerReadyTries < 20) {
                playerReadyTries += 1;
                window.setTimeout(bindQueueEnd, 250);
            }
            return;
        }
        if (audio.dataset.chartQueueBound === '1') {
            return;
        }
        audio.dataset.chartQueueBound = '1';
        audio.addEventListener('ended', function () {
            if (currentIndex < 0) {
                return;
            }
            var nextIndex = currentIndex + 1;
            if (queue[nextIndex]) {
                playQueueIndex(nextIndex);
            } else {
                currentIndex = -1;
            }
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-play-all-scope="chart-board"]');
        if (!button) {
            return;
        }
        if (!queue.length) {
            window.alert('当前页还没有可连续播放的真实歌曲，先上传歌曲后这里就能直接全部播放。');
            return;
        }
        playQueueIndex(0);
    });

    bindQueueEnd();
})();
</script>
<script src="/js/xingzai-widget.js" data-api="/backend/xingzai_chat.php" data-avatar="/images/xingzai-avatar.jpg"></script>
</body></html>
