<?php
require_once __DIR__ . '/backend/utils.php';
require_once __DIR__ . '/backend/config.php';

function ensureMasteringJobsTable(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mastering_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            song_id INTEGER NOT NULL,
            mastering_type TEXT NOT NULL DEFAULT 'software',
            style TEXT DEFAULT 'balanced',
            target_lufs REAL DEFAULT -9,
            status TEXT NOT NULL DEFAULT 'queued',
            input_file TEXT,
            output_file TEXT,
            preview_file TEXT,
            notes TEXT,
            error_message TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at TEXT,
            completed_at TEXT
        )"
    );
}

function parseMetricJson($value): ?array {
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : null;
}

function metricValue(?array $metrics, string $key): string {
    if (!$metrics || !array_key_exists($key, $metrics) || $metrics[$key] === null || $metrics[$key] === '') {
        return '--';
    }
    return number_format((float) $metrics[$key], 2, '.', '');
}

function renderMetricGroup(?array $metrics, string $title): string {
    return '<div class="master-metric-group">'
        . '<span>' . htmlspecialchars($title, ENT_QUOTES) . '</span>'
        . '<strong>LUFS ' . htmlspecialchars(metricValue($metrics, 'integrated_lufs'), ENT_QUOTES) . '</strong>'
        . '<strong>TP ' . htmlspecialchars(metricValue($metrics, 'true_peak_dbtp'), ENT_QUOTES) . ' dBTP</strong>'
        . '<strong>LRA ' . htmlspecialchars(metricValue($metrics, 'lra'), ENT_QUOTES) . '</strong>'
        . '</div>';
}

$recentSongs = [];
$recentJobs = [];
$dbError = null;
$isLoggedIn = !empty($_SESSION['user_id']);
$currentUser = null;
$currentUserLabel = '未登录访客';
$songSearch = trim((string) ($_GET['song_search'] ?? ''));
$songPage = max(1, (int) ($_GET['song_page'] ?? 1));
$songPerPage = 5;
$songTotal = 0;
$songPages = 1;
$jobSearch = trim((string) ($_GET['job_search'] ?? ''));
$jobPage = max(1, (int) ($_GET['job_page'] ?? 1));
$jobPerPage = 5;
$jobTotal = 0;
$jobPages = 1;
$demoSongs = [
    ['id' => null, 'title' => '夜航星尘', 'description' => '适合展示软件母带标准化流程的流行作品 demo。', 'file_path' => null, 'created_at' => '演示推荐位', 'play_count' => 982, 'user_id' => null, 'username' => null, 'full_name' => '星浪精选', 'avatar_path' => 'images/starwaves-logo.svg', 'is_demo' => true],
    ['id' => null, 'title' => '海风写成诗', 'description' => '偏治愈流行方向，适合做发行前最终听感整理。', 'file_path' => null, 'created_at' => '演示推荐位', 'play_count' => 864, 'user_id' => null, 'username' => null, 'full_name' => '潮声计划', 'avatar_path' => 'images/starwaves-logo.svg', 'is_demo' => true],
    ['id' => null, 'title' => '失重电台', 'description' => '电子流行和氛围节拍方向，适合展示自动母带的响度与空间整理。', 'file_path' => null, 'created_at' => '演示推荐位', 'play_count' => 731, 'user_id' => null, 'username' => null, 'full_name' => '零度合成社', 'avatar_path' => 'images/starwaves-logo.svg', 'is_demo' => true],
];

try {
    $pdo = getPdo();
    ensureMasteringJobsTable($pdo);

    if ($isLoggedIn) {
        $userStmt = $pdo->prepare('SELECT username, full_name, avatar_path FROM users WHERE id = ?');
        $userStmt->execute([(int) $_SESSION['user_id']]);
        $currentUser = $userStmt->fetch();
        if ($currentUser) {
            $currentUserLabel = trim((string) ($currentUser['full_name'] ?: $currentUser['username'] ?: '登录用户'));
        }
    }

    $songWhere = '';
    $songParams = [];
    if ($songSearch !== '') {
        $songWhere = ' WHERE s.title LIKE :song_search OR s.description LIKE :song_search OR u.username LIKE :song_search OR u.full_name LIKE :song_search';
        $songParams[':song_search'] = '%' . $songSearch . '%';
    }
    $songCountStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM songs s
         LEFT JOIN users u ON u.id = s.user_id'
         . $songWhere
    );
    $songCountStmt->execute($songParams);
    $songTotal = (int) $songCountStmt->fetchColumn();
    $songPages = max(1, (int) ceil($songTotal / $songPerPage));
    $songPage = min($songPage, $songPages);
    $songOffset = ($songPage - 1) * $songPerPage;

    $songStmt = $pdo->prepare(
        'SELECT s.id, s.title, s.description, s.file_path, s.source, s.created_at, s.play_count, s.user_id, u.username, u.full_name, u.avatar_path
         FROM songs s
         LEFT JOIN users u ON u.id = s.user_id'
         . $songWhere .
        ' ORDER BY s.created_at DESC
          LIMIT ' . (int) $songPerPage . ' OFFSET ' . (int) $songOffset
    );
    $songStmt->execute($songParams);
    $recentSongs = $songStmt->fetchAll();

    foreach ($recentSongs as &$song) {
        $song['is_demo'] = false;
    }
    unset($song);

    if ($isLoggedIn) {
        $jobWhere = ' WHERE mj.user_id = :user_id AND mj.mastering_type = \'software\'';
        $jobParams = [':user_id' => (int) $_SESSION['user_id']];
        if ($jobSearch !== '') {
            $jobWhere .= ' AND (s.title LIKE :job_search OR mj.status LIKE :job_search OR mj.style LIKE :job_search)';
            $jobParams[':job_search'] = '%' . $jobSearch . '%';
        }
        $jobCountStmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM mastering_jobs mj
             LEFT JOIN songs s ON s.id = mj.song_id'
             . $jobWhere
        );
        $jobCountStmt->execute($jobParams);
        $jobTotal = (int) $jobCountStmt->fetchColumn();
        $jobPages = max(1, (int) ceil($jobTotal / $jobPerPage));
        $jobPage = min($jobPage, $jobPages);
        $jobOffset = ($jobPage - 1) * $jobPerPage;

        $jobStmt = $pdo->prepare(
            'SELECT mj.id, mj.song_id, mj.mastering_type, mj.status, mj.style, mj.target_lufs, mj.created_at, mj.output_file, mj.preview_file,
                    mj.notes, mj.error_message, mj.analysis_before_json, mj.analysis_target_json, mj.analysis_after_json,
                    s.title AS song_title
             FROM mastering_jobs mj
             LEFT JOIN songs s ON s.id = mj.song_id'
             . $jobWhere .
            ' ORDER BY mj.created_at DESC
              LIMIT ' . (int) $jobPerPage . ' OFFSET ' . (int) $jobOffset
        );
        $jobStmt->execute($jobParams);
        $recentJobs = $jobStmt->fetchAll();
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

if (count($recentSongs) < 6) {
    $recentSongs = array_merge($recentSongs, array_slice($demoSongs, 0, 6 - count($recentSongs)));
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <title>星浪音乐 | 母带中心</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta name="description" content="星浪音乐母带中心，支持软件母带自动分析、标准化处理与结果回看。" />
    <link href="<?php echo htmlspecialchars(siteAssetUrl('css/bootstrap.css')); ?>" rel="stylesheet" type="text/css" media="all" />
    <link href="<?php echo htmlspecialchars(siteAssetUrl('css/owl.carousel.css')); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(siteAssetUrl('css/team.css')); ?>" type="text/css" media="all" />
    <link href="<?php echo htmlspecialchars(siteAssetUrl('css/style.css')); ?>" rel="stylesheet" type="text/css" media="all" />
    <link href="<?php echo htmlspecialchars(siteAssetUrl('css/starwaves.css')); ?>" rel="stylesheet" type="text/css" media="all" />
    <link href="<?php echo htmlspecialchars(siteAssetUrl('css/font-awesome.css')); ?>" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Raleway:400,600,700,800" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:400,600,700" rel="stylesheet">
    <style>
        .master-page { background: linear-gradient(180deg, #f6f8fc 0%, #eef3fb 100%); }
        .master-hero-copy h2 span { display: block; }
        .master-hero-note { display: inline-flex; gap: 10px; align-items: center; margin-top: 16px; padding: 10px 16px; border-radius: 999px; background: rgba(255,255,255,0.16); color: #fff; font-size: 14px; }
        .master-flow-zone, .master-song-zone, .master-process-zone { padding: 48px 0; }
        .master-block { background: #fff; border-radius: 28px; box-shadow: 0 22px 60px rgba(24, 56, 109, 0.08); padding: 30px; }
        .master-flow-grid, .master-option-grid, .master-process-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; }
        .master-step, .master-option, .master-process-card { border: 1px solid rgba(25, 70, 140, 0.08); border-radius: 22px; padding: 22px; background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%); }
        .master-step-index, .master-option-tag { display: inline-block; margin-bottom: 12px; padding: 6px 12px; border-radius: 999px; background: rgba(77, 117, 201, 0.12); color: #1e4ea1; font-weight: 700; font-size: 12px; letter-spacing: .08em; text-transform: uppercase; }
        .master-option.hardware { background: linear-gradient(180deg, #fffaf1 0%, #fff 100%); border-color: rgba(215, 145, 32, 0.18); }
        .master-option.software { background: linear-gradient(180deg, #f4fbff 0%, #fff 100%); }
        .master-option ul, .master-process-card ul { padding-left: 18px; margin: 14px 0 0; color: #5f6f89; }
        .master-song-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-top: 18px; }
        .master-song-card { position: relative; overflow: hidden; border-radius: 20px; background: #fff; border: 1px solid rgba(25, 70, 140, 0.08); box-shadow: 0 14px 36px rgba(24, 56, 109, 0.07); padding: 16px; }
        .master-song-card h4 { margin: 0 0 4px; font-size: 17px; color: #173056; line-height: 1.35; }
        .master-song-card p, .master-song-card li, .master-order-card p, .master-order-card li, .master-section-title p { color: #5f6f89; line-height: 1.8; }
        .master-song-meta { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0; padding: 0; list-style: none; }
        .master-song-meta li { padding: 6px 10px; border-radius: 999px; background: #f3f7ff; color: #305489; font-size: 12px; }
        .master-song-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
        .master-btn { display: inline-flex; align-items: center; justify-content: center; padding: 12px 18px; border-radius: 999px; font-weight: 700; text-decoration: none; transition: .25s ease; }
        .master-btn.primary { background: linear-gradient(90deg, #4b74ca, #6d95ef); color: #fff; }
        .master-btn.gold { background: linear-gradient(90deg, #cc9c33, #f0c66a); color: #2e2410; }
        .master-btn.ghost { border: 1px solid rgba(25, 70, 140, 0.12); color: #274878; background: #fff; }
        .master-btn.linkish { padding: 10px 14px; font-size: 13px; }
        .master-btn:hover { transform: translateY(-2px); text-decoration: none; }
        .master-order-card { margin-top: 28px; border-radius: 26px; padding: 28px; background: linear-gradient(135deg, #16325f 0%, #244f8f 60%, #2e65b0 100%); color: #fff; box-shadow: 0 26px 70px rgba(18, 46, 88, 0.22); }
        .master-order-card p, .master-order-card li, .master-order-card h3, .master-order-card h4 { color: #fff; }
        .master-order-grid { display: grid; grid-template-columns: 1.1fr .9fr; gap: 22px; align-items: start; }
        .master-order-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-top: 18px; }
        .master-field { padding: 16px; border-radius: 18px; background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.14); }
        .master-field span { display: block; font-size: 12px; opacity: .78; margin-bottom: 6px; letter-spacing: .06em; text-transform: uppercase; }
        .master-field strong { display: block; font-size: 17px; }
        .master-order-side { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.16); border-radius: 22px; padding: 22px; }
        .master-order-side .master-btn { margin-top: 14px; }
        .master-section-title { margin-bottom: 20px; }
        .master-section-title h3 { margin: 0 0 10px; color: #173056; }
        .master-metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-top: 14px; }
        .master-metric-group { border-radius: 16px; background: #f7faff; border: 1px solid rgba(25, 70, 140, 0.08); padding: 14px; }
        .master-metric-group span { display: block; margin-bottom: 8px; font-size: 12px; color: #60718d; letter-spacing: .06em; text-transform: uppercase; }
        .master-metric-group strong { display: block; color: #173056; line-height: 1.7; font-size: 13px; }
        .master-job-note { margin-top: 10px; font-size: 12px; color: #60718d; }
        .master-job-links { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .master-job-error { margin-top: 8px; color: #b24343; font-size: 12px; }
        .master-toolbar { display:flex; gap:12px; align-items:center; justify-content:space-between; flex-wrap:wrap; margin-top:10px; }
        .master-search-form { display:flex; gap:10px; flex-wrap:wrap; width:100%; }
        .master-search-form input { flex:1 1 220px; min-height:44px; border:1px solid rgba(25,70,140,.12); border-radius:999px; padding:0 16px; }
        .master-pagination { display:flex; align-items:center; justify-content:center; gap:10px; flex-wrap:wrap; margin-top:18px; }
        .master-page-indicator { display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border-radius:999px; background:#f3f7ff; color:#274878; cursor:pointer; }
        .master-page-indicator input { width:72px; border:0; background:transparent; text-align:center; color:#173056; font-weight:700; }

        @media (max-width: 991px) {
            .master-flow-grid, .master-option-grid, .master-song-grid, .master-process-grid, .master-order-grid, .master-order-fields, .master-metrics { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="has-song-bottom-player master-page">
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
                            <li><a href="index.php" class="hvr-ripple-in">首页</a></li>
                            <li><a href="star-ai.php" class="hvr-ripple-in">STAR.AI</a></li>
                            <li><a href="top_songs.php" class="hvr-ripple-in">STAR TOP音乐榜</a></li>
                            <li><a href="starwaves-mix.php" class="hvr-ripple-in">混音</a></li>
                            <li class="active"><a href="starwaves-master.php" class="hvr-ripple-in">母带</a></li>
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
            <div class="hero-copy master-hero-copy">
                <span class="hero-kicker">MASTERING CENTER / 软件母带</span>
                <h2>先选歌曲，<span>再进入软件母带自动流程。</span></h2>
                <p>系统会先分析输入音频，再对照《爱的海洋》参考目标执行标准化，最后回写 before / target / after 指标。</p>
                <div class="hero-actions">
                    <a class="btn btn-primary btn-lg hero-primary" href="#master-song-pick" role="button">开始选歌</a>
                    <a class="btn btn-primary btn-lg hero-secondary" href="#master-types" role="button">查看母带方式</a>
                    <a class="btn btn-primary btn-lg hero-secondary" href="#master-order" role="button">查看任务结果</a>
                </div>
                <div class="master-hero-note"><i class="fa fa-headphones"></i> 参考目标：-9.0 LUFS / -1.1 dBTP / LRA 8.8</div>
            </div>
            <div class="hero-panel">
                <div class="hero-panel-card">
                    <ul>
                        <li><span>STEP 1</span><strong>选择歌曲</strong></li>
                        <li><span>STEP 2</span><strong>分析原始指标</strong></li>
                        <li><span>STEP 3</span><strong>输出标准化成品</strong></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="master-flow-zone">
        <div class="container">
            <div class="master-block">
                <div class="master-section-title">
                    <h3>母带服务流程</h3>
                    <p>软件母带现在按正式流程执行：进歌分析、对照目标、处理输出、保存报告。</p>
                </div>
                <div class="master-flow-grid">
                    <div class="master-step">
                        <span class="master-step-index">Step 01</span>
                        <h4>选择歌曲</h4>
                        <p>从你已经上传的歌曲里挑一首需要做最终听感整理的作品，先确定处理对象。</p>
                    </div>
                    <div class="master-step">
                        <span class="master-step-index">Step 02</span>
                        <h4>分析并算差值</h4>
                        <p>系统先测原始 LUFS、True Peak、LRA，再对照《爱的海洋》目标值计算偏差。</p>
                    </div>
                    <div class="master-step">
                        <span class="master-step-index">Step 03</span>
                        <h4>输出并记录结果</h4>
                        <p>完成后生成 WAV 成品和 MP3 试听，同时保存 before / target / after 三组指标。</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="master-types" class="master-process-zone">
        <div class="container">
            <div class="master-option-grid">
                <div class="master-option software master-block">
                    <span class="master-option-tag">软件母带</span>
                    <h3>分析型标准化通道</h3>
                    <p>这一条会自动跑分析 -> 调标 -> 生成音频，并把关键指标写回任务记录。当前按 10 积分 / 次扣费。</p>
                    <ul>
                        <li>自动测原始 loudness / true peak / LRA</li>
                        <li>按《爱的海洋》目标标准化</li>
                        <li>保存 before / target / after 指标</li>
                    </ul>
                    <div class="master-song-actions">
                        <a class="master-btn primary" href="#master-song-pick">选这首做软件母带（10 积分）</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="master-song-pick" class="master-song-zone">
        <div class="container">
            <div class="master-section-title">
                <h3>选择要做母带的歌曲</h3>
                <p>选中歌曲后会立刻进入分析型标准化流程。</p>
            </div>
            <?php if ($dbError): ?>
                <div class="master-block">
                    <h4>数据库连接失败</h4>
                    <p><?php echo htmlspecialchars($dbError); ?></p>
                </div>
            <?php else: ?>
                <div class="master-toolbar">
                    <form class="master-search-form" method="get">
                        <input type="hidden" name="song_page" value="1">
                        <input type="hidden" name="job_page" value="<?php echo (int) $jobPage; ?>">
                        <input type="hidden" name="job_search" value="<?php echo htmlspecialchars($jobSearch, ENT_QUOTES); ?>">
                        <input type="search" name="song_search" value="<?php echo htmlspecialchars($songSearch, ENT_QUOTES); ?>" placeholder="搜索歌曲 / 作者 / 描述">
                        <button class="master-btn ghost" type="submit">搜索</button>
                    </form>
                </div>
                <div class="master-song-grid">
                    <?php foreach ($recentSongs as $song): ?>
                        <?php
                            $isDemoSong = !empty($song['is_demo']);
                            $avatar = resolveAvatarUrl(!empty($song['avatar_path'])
                                ? (strpos((string) $song['avatar_path'], 'images/') === 0 ? $song['avatar_path'] : 'backend/' . ltrim($song['avatar_path'], '/'))
                                : 'images/starwaves-logo.svg');
                        ?>
                        <article class="master-song-card">
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                                <img src="<?php echo htmlspecialchars($avatar); ?>" alt="avatar" style="width:40px;height:40px;border-radius:14px;object-fit:cover;">
                                <div style="min-width:0;">
                                    <h4><?php echo htmlspecialchars(mb_strimwidth((string) $song['title'], 0, 34, '...')); ?></h4>
                                    <p style="margin:0;"><?php echo htmlspecialchars($song['full_name'] ?: $song['username'] ?: '星浪音乐'); ?></p>
                                </div>
                            </div>
                            <p><?php echo htmlspecialchars(mb_strimwidth((string) ($song['description'] ?? '暂无描述'), 0, 62, '...')); ?></p>
                            <ul class="master-song-meta">
                                <li><i class="fa fa-calendar-o"></i> <?php echo htmlspecialchars((string) $song['created_at']); ?></li>
                                <li><i class="fa fa-play-circle-o"></i> <?php echo (int) ($song['play_count'] ?? 0); ?> 次播放</li>
                                <li><?php echo $isDemoSong ? '演示歌曲' : '真实歌曲'; ?></li>
                            </ul>
                            <div class="master-song-actions">
                                <button
                                    class="master-btn primary js-master-request"
                                    type="button"
                                    data-song-id="<?php echo $song['id'] === null ? '' : (int) $song['id']; ?>"
                                    data-song-title="<?php echo htmlspecialchars($song['title'], ENT_QUOTES); ?>"
                                    data-master-type="software"
                                >选这首做软件母带（10 积分）</button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if ($songPages > 1): ?>
                    <div class="master-pagination" data-master-page-wrap data-page-count="<?php echo (int) $songPages; ?>" data-page-param="song_page" data-search-param="song_search" data-search-value="<?php echo htmlspecialchars($songSearch, ENT_QUOTES); ?>" data-extra-param="job_page" data-extra-value="<?php echo (int) $jobPage; ?>" data-extra-param-two="job_search" data-extra-value-two="<?php echo htmlspecialchars($jobSearch, ENT_QUOTES); ?>">
                        <a class="master-btn ghost linkish <?php echo $songPage <= 1 ? 'disabled' : ''; ?>" href="?song_page=<?php echo max(1, $songPage - 1); ?>&amp;song_search=<?php echo rawurlencode($songSearch); ?>&amp;job_page=<?php echo (int) $jobPage; ?>&amp;job_search=<?php echo rawurlencode($jobSearch); ?>">上页</a>
                        <span class="master-page-indicator" data-master-page-trigger>第 <?php echo (int) $songPage; ?> / <?php echo (int) $songPages; ?> 页 <input type="number" min="1" max="<?php echo (int) $songPages; ?>" value="<?php echo (int) $songPage; ?>" data-master-page-input></span>
                        <a class="master-btn ghost linkish <?php echo $songPage >= $songPages ? 'disabled' : ''; ?>" href="?song_page=<?php echo min($songPages, $songPage + 1); ?>&amp;song_search=<?php echo rawurlencode($songSearch); ?>&amp;job_page=<?php echo (int) $jobPage; ?>&amp;job_search=<?php echo rawurlencode($jobSearch); ?>">下页</a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div id="master-order" class="master-order-card">
                <div class="master-order-grid">
                    <div>
                        <h3>母带任务中心</h3>
                        <p>这里会显示当前歌曲、任务状态，以及自动母带完成后的标准化报告。</p>
                        <div class="master-order-fields">
                            <div class="master-field">
                                <span>歌曲名称</span>
                                <strong id="selected-song-title">前台选中的歌曲</strong>
                            </div>
                            <div class="master-field">
                                <span>母带类型</span>
                                <strong id="selected-master-type">等待选择</strong>
                            </div>
                            <div class="master-field">
                                <span>用户信息</span>
                                <strong id="selected-user"><?php echo htmlspecialchars($currentUserLabel); ?></strong>
                            </div>
                            <div class="master-field">
                                <span>状态</span>
                                <strong id="selected-status"><?php echo $isLoggedIn ? '待提交任务' : '请先登录后提交'; ?></strong>
                            </div>
                        </div>
                        <div id="master-request-message" style="margin-top:16px;font-size:14px;opacity:.92;">点击上面的歌曲按钮，就会在这里显示任务结果。</div>
                    </div>
                    <div class="master-order-side">
                        <h4>当前已接通</h4>
                        <ul>
                            <li>软件母带分析型标准化</li>
                            <li>按当前规则扣 10 积分 / 次</li>
                            <li>任务状态查询与重复提交拦截</li>
                            <li>完成后展示 before / target / after</li>
                        </ul>
                        <button class="master-btn gold" type="button" id="refresh-master-jobs">刷新任务状态</button>
                        <a class="master-btn ghost" href="index.php">先返回主站</a>
                    </div>
                </div>
            </div>

            <div class="master-block" style="margin-top:24px;">
                <div class="master-section-title" style="margin-bottom:14px;">
                    <h3>我的母带任务</h3>
                    <p><?php echo $isLoggedIn ? '这里显示你最近提交的软件母带任务，并展示分析报告。' : '登录后可以查看自己的母带任务记录。'; ?></p>
                </div>
                <?php if ($isLoggedIn): ?>
                    <div class="master-toolbar">
                        <form class="master-search-form" method="get">
                            <input type="hidden" name="job_page" value="1">
                            <input type="hidden" name="song_page" value="<?php echo (int) $songPage; ?>">
                            <input type="hidden" name="song_search" value="<?php echo htmlspecialchars($songSearch, ENT_QUOTES); ?>">
                            <input type="search" name="job_search" value="<?php echo htmlspecialchars($jobSearch, ENT_QUOTES); ?>" placeholder="搜索已完成歌曲 / 状态 / 风格">
                            <button class="master-btn ghost" type="submit">搜索</button>
                        </form>
                    </div>
                    <div id="master-job-list" class="master-song-grid" style="grid-template-columns: repeat(2, minmax(0, 1fr));">
                        <?php if ($recentJobs): ?>
                            <?php foreach ($recentJobs as $job): ?>
                                <?php
                                    $beforePayload = parseMetricJson($job['analysis_before_json'] ?? null);
                                    $beforeMetrics = is_array($beforePayload['measured'] ?? null) ? $beforePayload['measured'] : $beforePayload;
                                    $targetPayload = parseMetricJson($job['analysis_target_json'] ?? null);
                                    $targetMetrics = is_array($targetPayload['target'] ?? null) ? $targetPayload['target'] : $targetPayload;
                                    $afterPayload = parseMetricJson($job['analysis_after_json'] ?? null);
                                    $afterMetrics = is_array($afterPayload['measured'] ?? null) ? $afterPayload['measured'] : $afterPayload;
                                ?>
                                <article class="master-song-card" data-job-id="<?php echo (int) $job['id']; ?>">
                                    <h4><?php echo htmlspecialchars(mb_strimwidth((string) ($job['song_title'] ?: ('任务 #' . $job['id'])), 0, 34, '...')); ?></h4>
                                    <p>类型：软件母带 ｜ 风格：<?php echo htmlspecialchars((string) ($job['style'] ?: 'balanced')); ?></p>
                                    <ul class="master-song-meta">
                                        <li>任务 #<?php echo (int) $job['id']; ?></li>
                                        <li>状态：<?php echo htmlspecialchars((string) $job['status']); ?></li>
                                        <li>目标：<?php echo htmlspecialchars((string) $job['target_lufs']); ?> LUFS</li>
                                    </ul>
                                    <?php if ($beforeMetrics || $targetMetrics || $afterMetrics): ?>
                                        <div class="master-metrics">
                                            <?php echo renderMetricGroup($beforeMetrics, 'Before'); ?>
                                            <?php echo renderMetricGroup($targetMetrics, 'Target'); ?>
                                            <?php echo renderMetricGroup($afterMetrics, 'After'); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($job['notes'])): ?>
                                        <div class="master-job-note"><?php echo htmlspecialchars((string) $job['notes']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($job['error_message'])): ?>
                                        <div class="master-job-error"><?php echo htmlspecialchars((string) $job['error_message']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($job['preview_file']) || !empty($job['output_file'])): ?>
                                        <div class="master-job-links">
                                            <?php if (!empty($job['preview_file'])): ?><a class="master-btn ghost linkish" href="<?php echo htmlspecialchars((string) $job['preview_file']); ?>" target="_blank" rel="noopener">试听 MP3</a><?php endif; ?>
                                            <?php if (!empty($job['output_file'])): ?><a class="master-btn ghost linkish" href="<?php echo htmlspecialchars((string) $job['output_file']); ?>" target="_blank" rel="noopener">下载 WAV</a><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="master-process-card" style="grid-column:1 / -1;">
                                <h4>还没有任务</h4>
                                <p>先从上面的歌曲列表里选一首，提交第一条母带任务。</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($jobPages > 1): ?>
                        <div class="master-pagination" data-master-page-wrap data-page-count="<?php echo (int) $jobPages; ?>" data-page-param="job_page" data-search-param="job_search" data-search-value="<?php echo htmlspecialchars($jobSearch, ENT_QUOTES); ?>" data-extra-param="song_page" data-extra-value="<?php echo (int) $songPage; ?>" data-extra-param-two="song_search" data-extra-value-two="<?php echo htmlspecialchars($songSearch, ENT_QUOTES); ?>">
                            <a class="master-btn ghost linkish <?php echo $jobPage <= 1 ? 'disabled' : ''; ?>" href="?job_page=<?php echo max(1, $jobPage - 1); ?>&amp;job_search=<?php echo rawurlencode($jobSearch); ?>&amp;song_page=<?php echo (int) $songPage; ?>&amp;song_search=<?php echo rawurlencode($songSearch); ?>">上页</a>
                            <span class="master-page-indicator" data-master-page-trigger>第 <?php echo (int) $jobPage; ?> / <?php echo (int) $jobPages; ?> 页 <input type="number" min="1" max="<?php echo (int) $jobPages; ?>" value="<?php echo (int) $jobPage; ?>" data-master-page-input></span>
                            <a class="master-btn ghost linkish <?php echo $jobPage >= $jobPages ? 'disabled' : ''; ?>" href="?job_page=<?php echo min($jobPages, $jobPage + 1); ?>&amp;job_search=<?php echo rawurlencode($jobSearch); ?>&amp;song_page=<?php echo (int) $songPage; ?>&amp;song_search=<?php echo rawurlencode($songSearch); ?>">下页</a>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="master-process-card">
                        <h4>登录后可提交真实任务</h4>
                        <p>演示歌曲目前只展示流程，真正创建母带任务需要先登录到你的星浪账号。</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="master-process-zone" style="padding-top:0;">
        <div class="container">
            <div class="master-process-grid">
                <div class="master-process-card">
                    <h4>软件母带</h4>
                    <p>以《爱的海洋》为基准目标，先分析、再标准化、再回写报告。</p>
                </div>
                <div class="master-process-card">
                    <h4>结果透明</h4>
                    <p>任务卡片直接展示 before / target / after，避免只看到“完成”却看不到量化结果。</p>
                </div>
            </div>
        </div>
    </div>

    <script src="js/jquery-2.1.4.min.js"></script>
    <script src="js/bootstrap-3.1.1.min.js"></script>
    <script>
        (function () {
            var isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
            var selectedTitle = document.getElementById('selected-song-title');
            var selectedType = document.getElementById('selected-master-type');
            var selectedStatus = document.getElementById('selected-status');
            var selectedUser = document.getElementById('selected-user');
            var requestMessage = document.getElementById('master-request-message');
            var jobList = document.getElementById('master-job-list');
            var refreshButton = document.getElementById('refresh-master-jobs');

            function typeLabel() {
                return '软件母带';
            }

            function escapeHtml(value) {
                return String(value || '').replace(/[&<>"']/g, function (char) {
                    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[char];
                });
            }

            function parseMetricJson(value) {
                if (!value) return null;
                if (typeof value === 'object') return value;
                try {
                    return JSON.parse(value);
                } catch (error) {
                    return null;
                }
            }

            function metricValue(metrics, key) {
                if (!metrics || metrics[key] === undefined || metrics[key] === null || metrics[key] === '') {
                    return '--';
                }
                return Number(metrics[key]).toFixed(2);
            }

            function renderMetricGroup(metrics, title) {
                return '<div class="master-metric-group">' +
                    '<span>' + escapeHtml(title) + '</span>' +
                    '<strong>LUFS ' + escapeHtml(metricValue(metrics, 'integrated_lufs')) + '</strong>' +
                    '<strong>TP ' + escapeHtml(metricValue(metrics, 'true_peak_dbtp')) + ' dBTP</strong>' +
                    '<strong>LRA ' + escapeHtml(metricValue(metrics, 'lra')) + '</strong>' +
                    '</div>';
            }

            document.querySelectorAll('[data-master-page-wrap]').forEach(function (wrap) {
                var trigger = wrap.querySelector('[data-master-page-trigger]');
                var input = wrap.querySelector('[data-master-page-input]');
                if (!trigger || !input) return;
                function jump() {
                    var target = parseInt(String(input.value || '').trim(), 10) || 0;
                    var pageCount = parseInt(wrap.getAttribute('data-page-count') || '1', 10) || 1;
                    if (!target) return;
                    if (target < 1) target = 1;
                    if (target > pageCount) target = pageCount;
                    var url = new URL(window.location.href);
                    url.searchParams.set(wrap.getAttribute('data-page-param'), String(target));
                    url.searchParams.set(wrap.getAttribute('data-search-param'), wrap.getAttribute('data-search-value') || '');
                    var extraParam = wrap.getAttribute('data-extra-param');
                    var extraValue = wrap.getAttribute('data-extra-value');
                    var extraParamTwo = wrap.getAttribute('data-extra-param-two');
                    var extraValueTwo = wrap.getAttribute('data-extra-value-two');
                    if (extraParam) url.searchParams.set(extraParam, extraValue || '');
                    if (extraParamTwo) url.searchParams.set(extraParamTwo, extraValueTwo || '');
                    window.location.href = url.toString();
                }
                trigger.addEventListener('click', function () {
                    input.focus();
                    input.select();
                });
                input.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        jump();
                    }
                });
            });

            function renderJobCard(job) {
                var beforePayload = parseMetricJson(job.analysis_before_json);
                var beforeMetrics = beforePayload && beforePayload.measured ? beforePayload.measured : beforePayload;
                var targetPayload = parseMetricJson(job.analysis_target_json);
                var targetMetrics = targetPayload && targetPayload.target ? targetPayload.target : targetPayload;
                var afterPayload = parseMetricJson(job.analysis_after_json);
                var afterMetrics = afterPayload && afterPayload.measured ? afterPayload.measured : afterPayload;
                var metricsHtml = '';
                if (beforeMetrics || targetMetrics || afterMetrics) {
                    metricsHtml = '<div class="master-metrics">' +
                        renderMetricGroup(beforeMetrics, 'Before') +
                        renderMetricGroup(targetMetrics, 'Target') +
                        renderMetricGroup(afterMetrics, 'After') +
                        '</div>';
                }
                var links = '';
                if (job.preview_file || job.output_file) {
                    links = '<div class="master-job-links">' +
                        (job.preview_file ? '<a class="master-btn ghost linkish" href="' + escapeHtml(job.preview_file) + '" target="_blank" rel="noopener">试听 MP3</a>' : '') +
                        (job.output_file ? '<a class="master-btn ghost linkish" href="' + escapeHtml(job.output_file) + '" target="_blank" rel="noopener">下载 WAV</a>' : '') +
                        '</div>';
                }
                var note = job.notes ? '<div class="master-job-note">' + escapeHtml(job.notes) + '</div>' : '';
                var error = job.error_message ? '<div class="master-job-error">' + escapeHtml(job.error_message) + '</div>' : '';
                return '<article class="master-song-card" data-job-id="' + escapeHtml(job.id) + '">' +
                    '<h4>' + escapeHtml(job.song_title || ('任务 #' + job.id)) + '</h4>' +
                    '<p>类型：' + escapeHtml(typeLabel(job.mastering_type)) + ' ｜ 风格：' + escapeHtml(job.style || 'balanced') + '</p>' +
                    '<ul class="master-song-meta">' +
                    '<li>任务 #' + escapeHtml(job.id) + '</li>' +
                    '<li>状态：' + escapeHtml(job.status) + '</li>' +
                    '<li>目标：' + escapeHtml(job.target_lufs) + ' LUFS</li>' +
                    '</ul>' +
                    metricsHtml + note + error + links +
                    '</article>';
            }

            function renderJobs(items) {
                if (!jobList) return;
                if (!items || !items.length) {
                    jobList.innerHTML = '<div class="master-process-card" style="grid-column:1 / -1;"><h4>还没有任务</h4><p>先从上面的歌曲列表里选一首，提交第一条母带任务。</p></div>';
                    return;
                }
                jobList.innerHTML = items.map(renderJobCard).join('');
            }

            function fetchJobs() {
                if (!isLoggedIn) return;
                fetch('backend/master.php?action=list', { credentials: 'same-origin' })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (!data.ok) return;
                        renderJobs(data.jobs || []);
                    })
                    .catch(function () {});
            }

            function setSelection(songTitle, type, status, message) {
                if (selectedTitle) selectedTitle.textContent = songTitle || '前台选中的歌曲';
                if (selectedType) selectedType.textContent = type ? typeLabel(type) : '等待选择';
                if (selectedStatus) selectedStatus.textContent = status || (isLoggedIn ? '待提交任务' : '请先登录后提交');
                if (requestMessage) requestMessage.textContent = message || '点击上面的歌曲按钮，就会在这里显示任务结果。';
                if (selectedUser && !selectedUser.textContent.trim()) {
                    selectedUser.textContent = isLoggedIn ? '当前登录用户' : '未登录访客';
                }
            }

            document.querySelectorAll('.js-master-request').forEach(function (button) {
                button.addEventListener('click', function () {
                    var songId = button.getAttribute('data-song-id');
                    var songTitle = button.getAttribute('data-song-title') || '未命名歌曲';
                    var type = button.getAttribute('data-master-type') || 'software';
                    setSelection(songTitle, type, '提交中...', '正在创建任务，请稍等。');
                    location.hash = 'master-order';

                    if (!songId) {
                        setSelection(songTitle, type, '演示流程', '这首是演示歌曲，真实提交请先登录并选择你自己的已上传歌曲。');
                        return;
                    }

                    fetch('backend/master.php?action=create', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ song_id: Number(songId), mastering_type: type, target_lufs: -9.0 })
                    })
                        .then(function (res) { return res.json(); })
                        .then(function (data) {
                            if (!data.ok) {
                                if (data.code === 'LOGIN_REQUIRED') {
                                    setSelection(songTitle, type, '请先登录', '登录后才能提交真实母带任务。');
                                    return;
                                }
                                setSelection(songTitle, type, '提交失败', data.error || '创建任务失败，请稍后再试。');
                                return;
                            }
                            setSelection(songTitle, type, data.job.status, '任务已创建：#' + data.job.id + '。' + (data.job.notes || ''));
                            fetchJobs();
                        })
                        .catch(function () {
                            setSelection(songTitle, type, '网络异常', '接口暂时不可用，请稍后刷新再试。');
                        });
                });
            });

            if (refreshButton) {
                refreshButton.addEventListener('click', fetchJobs);
            }
        })();
    </script>
<script src="<?php echo htmlspecialchars(siteAssetUrl('js/xingzai-widget.js')); ?>" data-api="/backend/xingzai_chat.php" data-avatar="<?php echo htmlspecialchars(siteAssetUrl('images/xingzai-avatar.jpg')); ?>"></script>
</body>
</html>

