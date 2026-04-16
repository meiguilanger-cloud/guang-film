<?php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = getPdo();

$totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalSongs = (int) $pdo->query('SELECT COUNT(*) FROM songs')->fetchColumn();
$totalFavorites = (int) $pdo->query('SELECT COUNT(*) FROM favorites')->fetchColumn();
$topSongs = $pdo->query('SELECT title, play_count FROM songs ORDER BY play_count DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
$recentFavorites = (int) $pdo->query("SELECT COUNT(*) FROM favorites WHERE added_at >= datetime('now', '-1 hour')")->fetchColumn();

$playLog = __DIR__ . '/../logs/play.log';
$recentPlayCount = 0;
if (is_file($playLog)) {
    $oneHourAgo = time() - 3600;
    $lines = file($playLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $parts = explode('|', $line);
        $timestamp = trim($parts[0] ?? '');
        if ($timestamp !== false && strtotime($timestamp) >= $oneHourAgo) {
            $recentPlayCount++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>后台统计仪表盘</title>
    <link rel="stylesheet" href="../css/backend.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .card { background: rgba(255,255,255,0.05); padding: 18px; border: 1px solid rgba(255,255,255,0.08); border-radius: 18px; }
        .label { display: block; font-size: 14px; color: rgba(255,255,255,0.68); margin-bottom: 8px; }
        .value { font-size: 28px; font-weight: bold; }
        .chart-card { background: rgba(13, 18, 24, 0.82); padding: 18px; border: 1px solid rgba(255,255,255,0.08); border-radius: 22px; }
        @media (max-width: 900px) {
            .backend-mobile-toggle {
                display: inline-flex !important;
                align-items: center;
                justify-content: center;
                align-self: flex-start;
                margin-left: auto;
                border: 0;
                border-radius: 14px;
                background: rgba(255,255,255,0.08);
                color: #fff;
                width: 46px;
                height: 46px;
                font-size: 24px;
            }
            .backend-links { display: none !important; width: 100%; gap: 10px; margin-top: 6px; }
            .backend-links.open { display: grid !important; }
        }
        @media (min-width: 901px) {
            .backend-mobile-toggle { display: none !important; }
        }
    </style>
</head>
<body>
<div class="backend-shell">
    <div class="backend-topbar">
        <div class="backend-brand">
            <img src="../images/starwaves-logo.svg" alt="logo">
            <div>
                <strong>后台统计仪表盘</strong>
                <span>用户、歌曲、播放与收藏趋势</span>
            </div>
        </div>
        <button type="button" class="backend-mobile-toggle" id="backendMobileToggle" aria-expanded="false" aria-controls="backendLinks">☰</button>
        <div class="backend-links" id="backendLinks">
            <a href="admin.php">返回后台</a>
            <a href="manage_songs.php">歌曲管理</a>
        </div>
    </div>

    <div class="backend-card">
        <span class="backend-kicker">Analytics</span>
        <h1>后台统计仪表盘</h1>

        <div class="stats">
        <div class="card">
            <span class="label">注册用户数</span>
            <div class="value"><?= $totalUsers ?></div>
        </div>
        <div class="card">
            <span class="label">歌曲总数</span>
            <div class="value"><?= $totalSongs ?></div>
        </div>
        <div class="card">
            <span class="label">收藏总数</span>
            <div class="value"><?= $totalFavorites ?></div>
        </div>
        <div class="card">
            <span class="label">最近一小时播放数</span>
            <div class="value"><?= $recentPlayCount ?></div>
        </div>
        <div class="card">
            <span class="label">最近一小时新增收藏</span>
            <div class="value"><?= $recentFavorites ?></div>
        </div>
    </div>

    </div>

    <div class="chart-card">
        <canvas id="topSongsChart" width="400" height="200"></canvas>
    </div>
</div>

    <script>
        const ctx = document.getElementById('topSongsChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($topSongs, 'title'), JSON_UNESCAPED_UNICODE) ?>,
                datasets: [{
                    label: '播放次数',
                    data: <?= json_encode(array_map('intval', array_column($topSongs, 'play_count'))) ?>,
                    backgroundColor: 'rgba(37, 99, 235, 0.65)',
                    borderColor: 'rgba(37, 99, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: true }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });
        var toggle = document.getElementById('backendMobileToggle');
        var links = document.getElementById('backendLinks');
        if (toggle && links) {
            toggle.addEventListener('click', function () {
                var open = links.classList.toggle('open');
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }
    </script>
<script src="/js/xingzai-widget.js" data-api="/backend/xingzai_chat.php" data-avatar="/images/xingzai-avatar.jpg"></script>
</body>
</html>
