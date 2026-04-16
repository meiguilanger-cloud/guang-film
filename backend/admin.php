<?php
require_once 'utils.php';
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = getPdo();
$userId = (int) $_SESSION['user_id'];
$stmt = $pdo->prepare('SELECT username, email, mobile, full_name, bio, avatar_path, credits FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'username' => $_SESSION['username'] ?? '用户',
    'email' => '',
    'mobile' => '',
    'full_name' => '',
    'bio' => '',
    'avatar_path' => '',
    'credits' => 0
];

$totalSongs = (int) $pdo->query('SELECT COUNT(*) FROM songs')->fetchColumn();
$todaySongs = (int) $pdo->query("SELECT COUNT(*) FROM songs WHERE DATE(created_at) = DATE('now','localtime')")->fetchColumn();
$totalFavorites = (int) $pdo->query('SELECT COUNT(*) FROM favorites')->fetchColumn();
$pendingPayments = (int) $pdo->query("SELECT COUNT(*) FROM payment_requests WHERE status = 'pending'")->fetchColumn();
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;
$stmt = $pdo->prepare('SELECT s.id, s.title, s.file_path, s.storage_type, s.archive_path, s.image_url, s.created_at, s.play_count, s.source, s.visibility, s.user_id, u.username FROM songs s LEFT JOIN users u ON s.user_id = u.id ORDER BY s.created_at DESC LIMIT :limit OFFSET :offset');
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$songsPage = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalPages = max(1, (int) ceil($totalSongs / $perPage));
$avatarSrc = resolveAvatarUrl(!empty($user['avatar_path']) ? $user['avatar_path'] : '../images/starwaves-logo.svg');
$userAccountCode = str_pad((string) $userId, 7, '0', STR_PAD_LEFT);
$displayName = trim((string) ($user['full_name'] ?: $user['username']));
$displayBio = trim((string) ($user['bio'] ?: '欢迎来到你的音乐后台。'));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>后台首页</title>
    <link rel="stylesheet" href="../css/backend.css?v=20260409-1838">
    <style>
    .dashboard-hero-title {
        font-size: 22px;
        line-height: 1.3;
    }
    .dashboard-hero-copy {
        font-size: 15px;
        line-height: 1.7;
        color: rgba(255,255,255,0.78);
    }
    .admin-mobile-track-list {
        display: grid;
        gap: 8px;
    }
    .admin-mobile-track-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 8px;
        border-radius: 14px;
        background: #f7f2e8;
        border: 1px solid rgba(16,21,28,.06);
        min-height: 64px;
    }
    .admin-mobile-track-cover {
        position: relative;
        width: 58px;
        min-width: 58px;
        height: 58px;
        border-radius: 14px;
        overflow: hidden;
        background: linear-gradient(135deg, #d7c4a1, #8d7147);
        box-shadow: 0 10px 26px rgba(0,0,0,.12);
    }
    .admin-mobile-track-cover img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .admin-mobile-track-wave {
        position: absolute;
        left: 50%;
        top: 50%;
        transform: translate(-50%, -50%);
        width: 14px;
        height: 12px;
        display: flex;
        align-items: flex-end;
        justify-content: center;
        gap: 2px;
        z-index: 3;
        opacity: 0;
        transition: opacity .18s ease;
    }
    .admin-mobile-track-wave span {
        width: 2px;
        height: 4px;
        border-radius: 999px;
        background: #fff7e2;
        opacity: .95;
    }
    .admin-mobile-track-wave span:nth-child(2) { height: 7px; }
    .admin-mobile-track-wave span:nth-child(3) { height: 5px; }
    .admin-mobile-track-wave span:nth-child(4) { height: 9px; }
    .admin-mobile-track-item.is-playing .admin-mobile-track-cover::after {
        opacity: 0;
    }
    .admin-mobile-track-item.is-playing .admin-mobile-track-cover img {
        filter: brightness(.58) saturate(.9);
    }
    .admin-mobile-track-item.is-playing .admin-mobile-track-wave {
        opacity: 1;
    }
    .admin-mobile-track-item.is-playing .admin-mobile-track-wave span {
        animation: adminMobileTrackWave .8s ease-in-out infinite;
        box-shadow: 0 0 8px rgba(255,247,226,.35);
    }
    .admin-mobile-track-item.is-playing .admin-mobile-track-wave span:nth-child(2) { animation-delay: .12s; }
    .admin-mobile-track-item.is-playing .admin-mobile-track-wave span:nth-child(3) { animation-delay: .24s; }
    .admin-mobile-track-item.is-playing .admin-mobile-track-wave span:nth-child(4) { animation-delay: .36s; }
    @keyframes adminMobileTrackWave {
        0%, 100% { height: 4px; opacity: .72; }
        50% { height: 11px; opacity: 1; }
    }
    .admin-mobile-track-cover::after {
        content: '▶';
        position: absolute;
        left: 50%;
        top: 50%;
        transform: translate(-46%, -55%);
        width: 18px;
        height: 18px;
        border-radius: 999px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(255,255,255,.88);
        color: #2b2117;
        font-size: 14px;
        box-shadow: 0 10px 20px rgba(0,0,0,.18);
    }
    .admin-mobile-track-time {
        position: absolute;
        left: 50%;
        bottom: 3px;
        transform: translateX(-50%);
        min-width: 24px;
        padding: 1px 3px;
        border-radius: 999px;
        background: rgba(0,0,0,.86);
        color: #fff;
        text-align: center;
        font-size: 7px;
        font-weight: 800;
        line-height: 1.1;
        z-index: 2;
    }
    .admin-mobile-track-main {
        min-width: 0;
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }
    .admin-mobile-track-copy {
        min-width: 0;
        flex: 1;
    }
    .admin-mobile-track-title-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: start;
        gap: 8px;
    }
    .admin-mobile-track-title {
        display: block;
        color: #151b22;
        font-size: 12px;
        line-height: 1.1;
        font-weight: 700;
        word-break: break-word;
        overflow-wrap: anywhere;
    }
    .admin-mobile-track-tag {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        align-self: start;
        margin-top: 1px;
        padding: 1px 5px;
        border-radius: 999px;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .4px;
        color: #111;
        background: #7ec8ff;
    }
    .admin-mobile-track-tag.ai {
        background: #f3ca78;
    }
    .admin-mobile-track-meta {
        margin-top: 2px;
        color: #6b6258;
        font-size: 9px;
        line-height: 1.22;
        display: -webkit-box;
        -webkit-line-clamp: 1;
        -webkit-box-orient: vertical;
        overflow: hidden;
        overflow-wrap: anywhere;
        word-break: break-word;
    }
    .admin-mobile-track-submeta {
        margin-top: 3px;
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
    }
    .admin-mobile-track-submeta span {
        display: inline-flex;
        align-items: center;
        padding: 1px 5px;
        border-radius: 999px;
        background: #efe4cd;
        color: #2f271d;
        font-size: 9px;
        line-height: 1.35;
    }
    .admin-mobile-track-menu {
        position: relative;
        display: flex;
        justify-content: flex-end;
        align-self: center;
        width: 40px;
    }
    .admin-mobile-track-menu-toggle {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border: 0;
        border-radius: 10px;
        background: #121a24;
        color: #fff8eb;
        font-size: 18px;
        font-weight: 700;
        line-height: 1;
    }
    .admin-mobile-track-menu-panel {
        position: absolute;
        top: 38px;
        right: 0;
        z-index: 20;
        display: none;
        width: 118px;
        padding: 6px;
        border-radius: 14px;
        background: #0d141d;
        box-shadow: 0 18px 32px rgba(0,0,0,0.28);
    }
    .admin-mobile-track-menu.is-open .admin-mobile-track-menu-panel {
        display: grid;
        gap: 6px;
    }
    .admin-mobile-track-menu-panel a {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 32px;
        padding: 7px 8px;
        border-radius: 10px;
        background: #121a24;
        color: #fff8eb !important;
        font-size: 11px;
        font-weight: 700;
        line-height: 1.2;
        text-align: center;
    }
    .admin-mobile-track-menu-panel a.song-visibility-toggle {
        background: #2d3440;
    }
    @media (max-width: 900px) {
        body {
            font-size: 18px;
        }
        .backend-shell {
            padding: 22px 16px 54px;
        }
        .backend-topbar {
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 22px;
        }
        .backend-brand {
            width: calc(100% - 58px);
            align-items: flex-start;
            gap: 16px;
        }
        .backend-brand img {
            width: 58px;
            height: 58px;
        }
        .backend-brand strong {
            font-size: 24px;
            line-height: 1.25;
        }
        .backend-brand span {
            font-size: 17px;
            line-height: 1.65;
        }
        .backend-mobile-toggle {
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            align-self: flex-start;
            margin-left: auto;
            width: 50px;
            height: 50px;
            font-size: 26px;
        }
        .backend-links {
            display: none !important;
            width: 100%;
            gap: 12px;
            margin-top: 8px;
        }
        .backend-links.open {
            display: grid !important;
        }
        .backend-links a {
            display: block;
            width: 100%;
            padding: 15px 18px;
            border-radius: 16px;
            background: rgba(255,255,255,0.06);
            font-size: 17px;
        }
        .backend-card {
            padding: 24px 20px;
            border-radius: 24px;
        }
        .backend-kicker {
            font-size: 12px;
            letter-spacing: 2px;
        }
        .backend-card h1,
        .backend-card h2 {
            font-size: 32px;
            line-height: 1.3;
            margin-bottom: 16px;
        }
        .dashboard-hero-title {
            font-size: 24px;
        }
        .dashboard-hero-copy,
        .muted,
        .user-meta p,
        .pagination,
        .song-visibility-toggle,
        .mobile-song-actions a {
            font-size: 17px;
            line-height: 1.7;
        }
        .user-hero {
            align-items: flex-start;
            gap: 16px;
        }
        .profile-avatar.large {
            width: 104px;
            height: 104px;
        }
        .user-meta {
            width: 100%;
        }
        .user-meta p {
            margin: 10px 0;
        }
        .stats-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 14px !important;
        }
        .stat-card {
            padding: 18px;
            border-radius: 20px;
        }
        .stat-card strong {
            font-size: 34px;
            line-height: 1.2;
        }
        .desktop-song-table { display: none !important; }
        .mobile-song-list { display: grid !important; gap: 16px; }
        .mobile-song-card {
            padding: 18px;
            border-radius: 20px;
            background: rgba(255,255,255,0.05);
        }
        .admin-mobile-track-item {
            align-items: flex-start;
        }
        .admin-mobile-track-cover {
            width: 60px;
            min-width: 60px;
            height: 60px;
            border-radius: 16px;
        }
        .admin-mobile-track-main {
            display: grid;
            gap: 6px;
        }
        .admin-mobile-track-menu {
            width: 40px;
            justify-self: end;
            align-self: center;
        }
        .admin-mobile-track-item {
            padding: 6px;
            gap: 6px;
        }
        .mobile-audio-preview { width: 100%; }
        .mobile-song-actions a {
            flex: 1 1 calc(50% - 8px);
            text-align: center;
            padding: 14px 12px;
            border-radius: 14px;
            background: rgba(255,255,255,0.06);
        }
        .pagination {
            justify-content: space-between;
            gap: 14px;
        }
    }
    @media (min-width: 901px) {
        .backend-mobile-toggle { display: none !important; }
        .backend-links {
            display: flex !important;
            width: auto;
            gap: 12px;
            margin-top: 0;
            justify-content: flex-end;
        }
        .backend-links.open { display: flex !important; flex-wrap: wrap; justify-content: flex-end; }
        .backend-links a {
            padding: 10px 14px;
            border-radius: 14px;
            background: rgba(255,255,255,0.06);
        }
        .mobile-song-list { display: none !important; }
    }
    </style>
</head>
<body>
<div class="backend-shell">
    <div class="backend-topbar">
        <div class="backend-brand">
            <img src="<?php echo e(resolvePublicAssetUrl('images/starwaves-logo.svg')); ?>" alt="logo">
            <div>
                <strong>音乐后台</strong>
                <span>上传、管理、分发你的歌曲内容</span>
            </div>
        </div>
        <button type="button" class="backend-mobile-toggle" id="backendMobileToggle" aria-expanded="false" aria-controls="backendLinks">☰</button>
        <div class="backend-links" id="backendLinks">
            <a href="../index.php">返回首页</a>
            <a href="upload.php">上传歌曲</a>
            <a href="manage_songs.php">管理歌曲</a>
            <a href="manage_mix_jobs.php">混音任务池</a>
            <a href="profile.php">个人资料</a>
            <a href="admin_dashboard.php">统计仪表盘</a>
            <a href="payment_request.php">充值链入口</a>
            <a href="logout.php">退出登录</a>
        </div>
    </div>

    <div class="backend-card">
        <span class="backend-kicker">Dashboard</span>
        <h1>音乐后台首页</h1>
        <div class="user-hero">
            <img class="profile-avatar large" src="<?php echo e($avatarSrc); ?>" alt="avatar">
            <div class="user-meta">
                <div class="dashboard-hero-title"><?php echo e($displayName); ?></div>
                <div class="dashboard-hero-copy"><?php echo e($displayBio); ?></div>
                <p><strong>账号：</strong><?php echo e($userAccountCode); ?></p>
                <p><strong>用户名：</strong><?php echo e($user['username']); ?></p>
                <p><strong>当前积分：</strong><?php echo (int) ($user['credits'] ?? 0); ?></p>
            </div>
        </div>
        <div class="stats-grid">
            <div class="stat-card"><span class="muted">歌曲总数</span><strong><?php echo $totalSongs; ?></strong></div>
            <div class="stat-card"><span class="muted">今日新增</span><strong><?php echo $todaySongs; ?></strong></div>
            <div class="stat-card"><span class="muted">当前积分</span><strong><?php echo (int) ($user['credits'] ?? 0); ?></strong></div>
            <div class="stat-card"><span class="muted">待审核充值</span><strong><?php echo $pendingPayments; ?></strong></div>
        </div>
    </div>

    <div class="backend-card">
        <span class="backend-kicker">Credits</span>
        <h2>剩余积分</h2>
        <div class="stats-grid">
            <div class="stat-card"><span class="muted">当前可用积分</span><strong><?php echo (int) ($user['credits'] ?? 0); ?> 积分</strong></div>
        </div>
    </div>

    <div class="backend-card">
        <span class="backend-kicker">Latest Songs</span>
        <h2>歌曲列表</h2>
        <div class="desktop-song-table">
            <table class="panel-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>标题</th>
                        <th>封面</th>
                        <th>上传者</th>
                        <th>时间</th>
                        <th>试听</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($songsPage as $idx => $song): ?>
                    <tr>
                        <?php
                            $audioSrc = resolveSongAudioUrl($song, 'backend');
                            $displayNumber = $idx + 1;
                        ?>
                        <td><?php echo $displayNumber; ?></td>
                        <td>
                            <a href="single-song.php?id=<?php echo e((string) $song['id']); ?>"><?php echo e($song['title']); ?></a>
                            <span style="display:inline-block;margin-left:6px;padding:2px 8px;border-radius:999px;background:<?php echo (($song['source'] ?? '') === 'ai') ? '#f3ca78' : '#7ec8ff'; ?>;color:#111;font-size:12px;font-weight:700;"><?php echo (($song['source'] ?? '') === 'ai') ? 'AI' : 'UP'; ?></span>
                            <div class="muted">播放 <?php echo (int) $song['play_count']; ?> 次</div>
                            <div class="muted">状态：<span class="song-visibility-text-<?php echo (int) $song['id']; ?>"><?php echo (($song['visibility'] ?? 'private') === 'public') ? '公开' : '私密'; ?></span></div>
                        </td>
                        <td><img src="<?php echo e($song['image_url'] ?? ''); ?>" style="max-width:80px;height:auto;" onerror="this.style.display='none';" /></td>
                        <td><?php echo e($song['username'] ?? '未知'); ?></td>
                        <td><?php echo e($song['created_at']); ?></td>
                        <td><audio class="audio-preview" controls src="<?php echo e($audioSrc); ?>"></audio></td>
                        <td>
                            <div class="inline-actions">
                                <?php if ((int) $song['user_id'] === $userId): ?>
                                    <a href="#" class="song-visibility-toggle" data-song-id="<?php echo e((string) $song['id']); ?>" data-visibility="<?php echo e(($song['visibility'] ?? 'private')); ?>"><?php echo (($song['visibility'] ?? 'private') === 'public') ? '设为私密' : '设为公开'; ?></a>
                                <?php endif; ?>
                                <a href="edit_song.php?id=<?php echo e((string) $song['id']); ?>">编辑</a>
                                <a href="delete_song.php?id=<?php echo e((string) $song['id']); ?>">删除</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="mobile-song-list admin-mobile-track-list">
            <?php foreach ($songsPage as $idx => $song): ?>
                <?php
                    $audioSrc = resolveSongAudioUrl($song, 'backend');
                    $displayNumber = $idx + 1;
                    $mobileCover = trim((string) ($song['image_url'] ?? ''));
                    $mobileMetaParts = [
                        (string) ($song['username'] ?? '未知'),
                        '播放 ' . (int) $song['play_count'] . ' 次',
                        '状态 ' . ((($song['visibility'] ?? 'private') === 'public') ? '公开' : '私密')
                    ];
                ?>
                <div class="admin-mobile-track-item" data-track-play data-track-src="<?php echo e($audioSrc); ?>" data-track-title="<?php echo e($song['title']); ?>">
                    <div class="admin-mobile-track-cover" data-cover-play>
                        <?php if ($mobileCover !== ''): ?>
                            <img src="<?php echo e($mobileCover); ?>" alt="<?php echo e($song['title']); ?>" onerror="this.style.display='none';">
                        <?php endif; ?>
                        <div class="admin-mobile-track-wave" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                        <span class="admin-mobile-track-time">#<?php echo $displayNumber; ?></span>
                    </div>
                    <div class="admin-mobile-track-main">
                        <div class="admin-mobile-track-copy">
                            <div class="admin-mobile-track-title-row">
                                <span class="admin-mobile-track-title"><?php echo e($song['title']); ?></span>
                                <span class="admin-mobile-track-tag <?php echo (($song['source'] ?? '') === 'ai') ? 'ai' : ''; ?>"><?php echo (($song['source'] ?? '') === 'ai') ? 'AI' : 'UP'; ?></span>
                            </div>
                            <div class="admin-mobile-track-meta"><?php echo e(implode(' / ', $mobileMetaParts)); ?></div>
                            <div class="admin-mobile-track-submeta">
                                <span><?php echo e($song['created_at']); ?></span>
                                <span class="song-visibility-text-<?php echo (int) $song['id']; ?>"><?php echo (($song['visibility'] ?? 'private') === 'public') ? '公开' : '私密'; ?></span>
                            </div>
                        </div>
                        <div class="admin-mobile-track-menu" data-more-menu>
                            <button type="button" class="admin-mobile-track-menu-toggle" data-more-menu-toggle aria-expanded="false" aria-label="展开更多操作">...</button>
                            <div class="admin-mobile-track-menu-panel">
                                <?php if ((int) $song['user_id'] === $userId): ?>
                                    <a href="#" class="song-visibility-toggle" data-song-id="<?php echo e((string) $song['id']); ?>" data-visibility="<?php echo e(($song['visibility'] ?? 'private')); ?>"><?php echo (($song['visibility'] ?? 'private') === 'public') ? '设为私密' : '设为公开'; ?></a>
                                <?php endif; ?>
                                <a href="edit_song.php?id=<?php echo e((string) $song['id']); ?>">编辑</a>
                                <a href="delete_song.php?id=<?php echo e((string) $song['id']); ?>" style="background:#2a1616;color:#ffcbc7 !important;">删除</a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="pagination">
            <?php if ($page > 1): ?><a href="admin.php?page=<?php echo $page - 1; ?>">← 上一页</a><?php endif; ?>
            <span>第 <?php echo $page; ?> / <?php echo $totalPages; ?> 页</span>
            <?php if ($page < $totalPages): ?><a href="admin.php?page=<?php echo $page + 1; ?>">下一页 →</a><?php endif; ?>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.getElementById('backendMobileToggle');
    var links = document.getElementById('backendLinks');
    if (toggle && links) {
        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var open = !links.classList.contains('open');
            links.classList.toggle('open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        links.addEventListener('click', function (event) {
            event.stopPropagation();
        });

        document.addEventListener('click', function () {
            if (window.innerWidth > 900) {
                return;
            }
            links.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
        });
    }

    function normalizeTrackSrc(src) {
        if (!src) return '';
        var clean = src.split('#')[0].split('?')[0];
        try {
            clean = new URL(clean, window.location.href).pathname;
        } catch (error) {}
        return clean.replace(/\\/g, '/');
    }

    function syncAdminTrackWaveState(src, playing) {
        var normalized = normalizeTrackSrc(src || '');
        document.querySelectorAll('.admin-mobile-track-item[data-track-src]').forEach(function (item) {
            var itemSrc = normalizeTrackSrc(item.getAttribute('data-track-src') || '');
            item.classList.toggle('is-playing', !!playing && normalized && itemSrc === normalized);
        });
    }

    document.querySelectorAll('[data-cover-play]').forEach(function (cover) {
        cover.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var item = cover.closest('.admin-mobile-track-item');
            if (!item || !window.StarwavesGlobalPlayer || typeof window.StarwavesGlobalPlayer.playTrack !== 'function') {
                return;
            }
            var src = item.getAttribute('data-track-src') || '';
            var title = item.getAttribute('data-track-title') || '当前歌曲';
            syncAdminTrackWaveState(src, true);
            window.StarwavesGlobalPlayer.playTrack({ src: src, title: title });
        });
    });

    document.addEventListener('starwaves:player-state', function (event) {
        var detail = event && event.detail ? event.detail : {};
        syncAdminTrackWaveState(detail.src || '', !!detail.playing);
    });

    var actionMenus = Array.prototype.slice.call(document.querySelectorAll('[data-more-menu]'));

    function closeActionMenus(exceptMenu) {
        actionMenus.forEach(function (menu) {
            var shouldOpen = !!exceptMenu && menu === exceptMenu;
            menu.classList.toggle('is-open', shouldOpen);
            var toggleButton = menu.querySelector('[data-more-menu-toggle]');
            if (toggleButton) {
                toggleButton.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
            }
        });
    }

    actionMenus.forEach(function (menu) {
        var toggleButton = menu.querySelector('[data-more-menu-toggle]');
        if (!toggleButton) {
            return;
        }
        toggleButton.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var isOpen = menu.classList.contains('is-open');
            closeActionMenus(isOpen ? null : menu);
        });
        menu.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    });

    document.addEventListener('click', function () {
        closeActionMenus(null);
    });

    document.querySelectorAll('.song-visibility-toggle').forEach(function (button) {
        button.addEventListener('click', async function (event) {
            event.preventDefault();
            var songId = button.getAttribute('data-song-id');
            var currentVisibility = button.getAttribute('data-visibility') || 'private';
            var nextVisibility = currentVisibility === 'public' ? 'private' : 'public';
            try {
                var response = await fetch('track_visibility.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ song_id: songId, visibility: nextVisibility })
                });
                var data = await response.json();
                if (!response.ok || !data.ok) {
                    throw new Error(data.error || '切换失败');
                }
                button.setAttribute('data-visibility', data.visibility);
                button.textContent = data.visibility === 'public' ? '设为私密' : '设为公开';
                document.querySelectorAll('.song-visibility-text-' + songId).forEach(function (label) {
                    label.textContent = data.visibility === 'public' ? '公开' : '私密';
                });
            } catch (error) {
                alert(error.message || '切换公开状态失败');
            }
        });
    });
});
</script>
<script src="<?php echo e(resolvePublicAssetUrl('js/global-player.js')); ?>"></script>
<script src="<?php echo e(resolvePublicAssetUrl('js/xingzai-widget.js')); ?>" data-api="/backend/xingzai_chat.php" data-avatar="<?php echo e(resolvePublicAssetUrl('images/xingzai-avatar.jpg')); ?>"></script>
</body>
</html>
