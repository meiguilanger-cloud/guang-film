<?php
require_once 'utils.php';
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = getPdo();
$search = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;
$params = [];
$whereSql = '';
if ($search !== '') {
    $whereSql = ' WHERE s.title LIKE :kw OR s.description LIKE :kw OR s.lyrics LIKE :kw';
    $params[':kw'] = "%{$search}%";
}
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM songs s JOIN users u ON s.user_id = u.id' . $whereSql);
$countStmt->execute($params);
$totalSongs = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalSongs / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}
$sql = 'SELECT s.*, u.username, u.full_name FROM songs s JOIN users u ON s.user_id = u.id' . $whereSql . ' ORDER BY s.created_at DESC LIMIT :limit OFFSET :offset';
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$songs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = [
    'none' => '未补歌词',
    'generated' => '歌词和 LRC 已就绪',
    'pending' => '已导入歌词',
    'pending_recognition' => '识别处理中'
];
$statusClasses = [
    'none' => 'secondary-btn',
    'generated' => 'primary-btn',
    'pending' => 'secondary-btn',
    'pending_recognition' => 'secondary-btn'
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>歌曲管理 - 音乐后台</title>
    <link rel="stylesheet" href="../css/backend.css?v=20260406-1659">
    <style>
    .backend-song-grid {
        display: grid;
        gap: 12px;
        margin-top: 14px;
    }
    .backend-song-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px;
        border-radius: 18px;
        background: #f7f2e8;
        border: 1px solid rgba(16,21,28,.06);
        overflow: visible;
        position: relative;
        cursor: default;
    }
    .backend-song-cover {
        position: relative;
        width: 72px;
        min-width: 72px;
        height: 72px;
        border-radius: 18px;
        overflow: hidden;
        background: linear-gradient(135deg, #d7c4a1, #8d7147);
        box-shadow: 0 10px 26px rgba(0,0,0,.12);
    }
    .backend-song-cover img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .backend-song-cover::after {
        content: '▶';
        position: absolute;
        left: 50%;
        top: 50%;
        transform: translate(-46%, -55%);
        width: 34px;
        height: 34px;
        border-radius: 999px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(255,255,255,.88);
        color: #2b2117;
        font-size: 14px;
        box-shadow: 0 10px 20px rgba(0,0,0,.18);
        z-index: 1;
    }
    .backend-song-main {
        min-width: 0;
        flex: 1;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
    }
    .backend-song-copy {
        min-width: 0;
        flex: 1;
    }
    .backend-song-head {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: start;
        gap: 8px;
        min-width: 0;
    }
    .backend-song-title-wrap {
        min-width: 0;
        flex: 1;
    }
    .backend-song-title-wrap strong {
        display: block;
        color: #151b22;
        margin: 0;
        font-size: 16px;
        line-height: 1.22;
        max-width: 100%;
        min-width: 0;
        white-space: normal;
        overflow: visible;
        text-overflow: clip;
        word-break: break-word;
        overflow-wrap: anywhere;
    }
    .backend-song-title-wrap .muted {
        margin-top: 5px;
        color: #6b6258;
        font-size: 12px;
        line-height: 1.4;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .backend-song-index {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        align-self: start;
        margin-top: 2px;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: .4px;
        color: #111;
        background: #7ec8ff;
    }
    .backend-song-meta {
        margin-top: 5px;
        color: #6b6258;
        font-size: 12px;
        line-height: 1.4;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        overflow-wrap: anywhere;
        word-break: break-word;
    }
    .backend-song-duration {
        color: #6b6258;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: .02em;
        margin-top: 8px;
    }
    .backend-song-status {
        margin-top: 8px;
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: center;
    }
    .backend-song-status .button-link {
        width: fit-content;
        pointer-events: none;
        padding: 6px 10px !important;
        font-size: 11px;
    }
    .backend-song-side {
        display: grid;
        gap: 8px;
        justify-items: end;
        min-width: 112px;
    }
    .backend-song-audio .audio-preview {
        width: 112px;
        height: 30px;
    }
    .backend-song-actions {
        position: relative;
        display: flex;
        justify-content: flex-end;
        width: 40px;
    }
    .backend-song-actions-toggle {
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
    .backend-song-actions-panel {
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
    .backend-song-actions.is-open .backend-song-actions-panel {
        display: grid;
        gap: 6px;
    }
    .backend-song-actions-panel a {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 8px 10px;
        border-radius: 10px;
        background: #121a24;
        color: #fff8eb !important;
        font-size: 12px;
        font-weight: 700;
        line-height: 1.2;
    }
    .backend-pagination {
        margin-top: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .backend-page-indicator {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 118px;
        height: 42px;
        padding: 0 12px;
        border-radius: 999px;
        background: rgba(255,255,255,0.08);
        color: #fff;
        cursor: pointer;
    }
    .backend-page-indicator.is-editing {
        padding: 0 8px;
        background: rgba(255,255,255,0.12);
    }
    .backend-page-indicator-text {
        font-size: 14px;
        font-weight: 700;
        white-space: nowrap;
    }
    .backend-page-indicator-input {
        display: none;
        width: 100%;
        border: 0;
        outline: none;
        background: transparent;
        color: #fff;
        text-align: center;
        font-size: 14px;
        font-weight: 700;
        padding: 0;
    }
    .backend-page-indicator.is-editing .backend-page-indicator-text { display: none; }
    .backend-page-indicator.is-editing .backend-page-indicator-input { display: block; }
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
        .desktop-song-table { display: none !important; }
        .mobile-song-list { display: grid !important; gap: 14px; margin-top: 18px; }
        .mobile-song-card {
            padding: 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.05);
        }
        .backend-song-item {
            align-items: flex-start;
        }
        .backend-song-cover {
            width: 68px;
            min-width: 68px;
            height: 68px;
            border-radius: 16px;
        }
        .backend-song-main {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            min-width: 0;
        }
        .backend-song-side {
            width: auto;
            min-width: 52px;
            justify-items: end;
        }
        .backend-song-actions {
            width: auto;
        }
        .backend-song-audio .audio-preview {
            width: 100%;
        }
    }
    @media (min-width: 901px) {
        .backend-mobile-toggle { display: none !important; }
        .mobile-song-list { display: none !important; }
    }
    </style>
</head>
<body>
<div class="backend-shell">
    <div class="backend-topbar">
        <div class="backend-brand">
            <img src="../images/starwaves-logo.svg" alt="logo">
            <div>
                <strong>歌曲管理</strong>
                <span>支持歌词处理和百度网盘归档</span>
            </div>
        </div>
        <button type="button" class="backend-mobile-toggle" id="backendMobileToggle" aria-expanded="false" aria-controls="backendLinks">☰</button>
        <div class="backend-links" id="backendLinks">
            <a href="../index.php">返回首页</a>
            <a href="admin.php">后台首页</a>
            <a href="upload.php">上传歌曲</a>
        </div>
    </div>

    <div class="backend-card">
        <span class="backend-kicker">Library</span>
        <h1>歌曲管理</h1>
        <?php if (!empty($_GET['generated'])): ?>
            <div class="success-msg">已完成歌词处理并生成 LRC。</div>
        <?php elseif (!empty($_GET['queued'])): ?>
            <div class="success-msg">识别任务已提交，但本次未成功返回歌词，可稍后重试或补充歌词文本。</div>
        <?php elseif (($_GET['archive'] ?? '') === 'success'): ?>
            <div class="success-msg">歌曲已成功归档到百度网盘。</div>
        <?php elseif (($_GET['archive'] ?? '') === 'failed'): ?>
            <div class="error-msg">歌曲归档失败，请看状态说明。</div>
        <?php endif; ?>
        <form method="get" action="" class="backend-form" style="max-width:none;">
            <div class="inline-actions">
                <input type="text" name="q" placeholder="搜索标题、描述或歌词" value="<?php echo e($search); ?>" style="min-width:280px;">
                <button type="submit" class="primary-btn">搜索</button>
                <a href="manage_songs.php" class="button-link secondary-btn">全部</a>
            </div>
        </form>
        <div class="muted" style="margin-top:14px;">后台列表现在固定每页 10 首；当前第 <?php echo $page; ?> / <?php echo $totalPages; ?> 页，共 <?php echo $totalSongs; ?> 首歌曲。</div>

        <div class="backend-song-grid">
            <?php foreach ($songs as $idx => $s): ?>
                <?php
                    $audioSrc = resolveSongAudioUrl($s, 'backend');
                    $status = $s['lyrics_status'] ?? 'none';
                    $statusLabel = $statusLabels[$status] ?? $status;
                    if ($status === 'pending_recognition' && !empty($s['lyrics_note']) && mb_strpos((string) $s['lyrics_note'], '失败') !== false) {
                        $statusLabel = '识别失败';
                    } elseif ($status === 'pending' && !empty($s['lyrics'])) {
                        $statusLabel = '已导入歌词';
                    }
                    $coverUrl = trim((string) ($s['image_url'] ?? ''));
                ?>
                <div class="backend-song-item">
                    <div class="backend-song-cover">
                        <?php if ($coverUrl !== ''): ?>
                            <img src="<?php echo e($coverUrl); ?>" alt="<?php echo e($s['title']); ?>">
                        <?php endif; ?>
                    </div>
                    <div class="backend-song-main">
                        <div class="backend-song-copy">
                            <div class="backend-song-head">
                                <div class="backend-song-title-wrap">
                                    <strong><?php echo e($s['title']); ?></strong>
                                </div>
                                <span class="backend-song-index">#<?php echo $offset + $idx + 1; ?></span>
                            </div>
                            <div class="backend-song-meta">
                                <?php
                                    $metaParts = [
                                        (string) ($s['full_name'] ?: $s['username']),
                                        (($s['storage_type'] ?? 'local') === 'baidu_netdisk' ? '百度网盘' : '本地')
                                    ];
                                    if (!empty($s['description'])) {
                                        $metaParts[] = mb_strimwidth((string) $s['description'], 0, 52, '...');
                                    }
                                ?>
                                <?php echo e(implode(' / ', array_filter($metaParts))); ?>
                            </div>
                            <div class="backend-song-duration">
                                <?php if (!empty($s['archived_at'])): ?>归档 <?php echo e($s['archived_at']); ?><?php else: ?>后台歌曲<?php endif; ?>
                            </div>
                            <div class="backend-song-status">
                                <span class="button-link <?php echo e($statusClasses[$status] ?? 'secondary-btn'); ?>">
                                    <?php echo e($statusLabel); ?>
                                </span>
                            </div>
                        </div>
                        <div class="backend-song-side">
                            <div class="backend-song-audio">
                                <audio class="audio-preview mobile-audio-preview" controls src="<?php echo e($audioSrc); ?>"></audio>
                            </div>
                            <div class="inline-actions backend-song-actions" data-more-menu>
                                <button type="button" class="backend-song-actions-toggle" data-more-menu-toggle aria-expanded="false" aria-label="展开更多操作">...</button>
                                <div class="backend-song-actions-panel">
                                    <a href="edit_song.php?id=<?php echo (int) $s['id']; ?>">编辑</a>
                                    <a href="edit_lyrics.php?id=<?php echo (int) $s['id']; ?>">歌词</a>
                                    <a href="download.php?id=<?php echo (int) $s['id']; ?>&format=mp3">下载 MP3</a>
                                    <a href="download.php?id=<?php echo (int) $s['id']; ?>&format=wav">下载 WAV</a>
                                    <?php if (!empty($s['lrc_path'])): ?>
                                        <a href="<?php echo e($s['lrc_path']); ?>" target="_blank">LRC</a>
                                    <?php endif; ?>
                                    <a href="delete_song.php?id=<?php echo (int) $s['id']; ?>" style="background:#2a1616; color:#ffcbc7 !important;">删除</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="backend-pagination" data-page-jump-wrap data-page-count="<?php echo $totalPages; ?>" data-search="<?php echo e($search); ?>">
            <?php if ($page > 1): ?>
                <a class="button-link secondary-btn" href="manage_songs.php?page=<?php echo $page - 1; ?><?php echo $search !== '' ? '&q=' . urlencode($search) : ''; ?>">← 上一页</a>
            <?php else: ?>
                <span class="button-link secondary-btn" style="opacity:.35; pointer-events:none;">← 上一页</span>
            <?php endif; ?>
            <span class="backend-page-indicator" data-page-jump-trigger role="button" tabindex="0">
                <span class="backend-page-indicator-text">第 <?php echo $page; ?> / <?php echo $totalPages; ?> 页</span>
                <input class="backend-page-indicator-input" type="number" min="1" max="<?php echo $totalPages; ?>" value="<?php echo $page; ?>" inputmode="numeric" data-page-inline-input>
            </span>
            <?php if ($page < $totalPages): ?>
                <a class="button-link secondary-btn" href="manage_songs.php?page=<?php echo $page + 1; ?><?php echo $search !== '' ? '&q=' . urlencode($search) : ''; ?>">下一页 →</a>
            <?php else: ?>
                <span class="button-link secondary-btn" style="opacity:.35; pointer-events:none;">下一页 →</span>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.getElementById('backendMobileToggle');
    var links = document.getElementById('backendLinks');
    if (!toggle || !links) {
        return;
    }
    toggle.addEventListener('click', function () {
        var open = links.classList.toggle('open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
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

    document.querySelectorAll('[data-page-jump-wrap]').forEach(function (wrap) {
        var trigger = wrap.querySelector('[data-page-jump-trigger]');
        var input = wrap.querySelector('[data-page-inline-input]');
        var indicatorText = wrap.querySelector('.backend-page-indicator-text');
        if (!trigger || !input) {
            return;
        }

        function normalizeTargetPage(rawValue) {
            var pageCount = parseInt(wrap.getAttribute('data-page-count') || '1', 10) || 1;
            var targetPage = parseInt(String(rawValue || '').trim(), 10) || 0;
            if (!targetPage) {
                return 0;
            }
            if (targetPage < 1) {
                targetPage = 1;
            } else if (targetPage > pageCount) {
                targetPage = pageCount;
            }
            return targetPage;
        }

        function buildJumpUrl(targetPage) {
            var nextUrl = new URL(window.location.href);
            nextUrl.searchParams.set('page', String(targetPage));
            var searchText = wrap.getAttribute('data-search') || '';
            if (searchText) {
                nextUrl.searchParams.set('q', searchText);
            } else {
                nextUrl.searchParams.delete('q');
            }
            return nextUrl.toString();
        }

        function syncJumpPreview() {
            var targetPage = normalizeTargetPage(input.value);
            var pageCount = parseInt(wrap.getAttribute('data-page-count') || '1', 10) || 1;
            if (!targetPage) {
                indicatorText.textContent = '第 ' + input.defaultValue + ' / ' + pageCount + ' 页';
                return;
            }
            indicatorText.textContent = '第 ' + targetPage + ' / ' + pageCount + ' 页';
        }

        function commitPageJump() {
            var targetPage = normalizeTargetPage(input.value);
            trigger.classList.remove('is-editing');
            if (!targetPage) {
                input.value = input.defaultValue;
                syncJumpPreview();
                return;
            }
            window.location.href = buildJumpUrl(targetPage);
        }

        function startEdit(event) {
            if (event) {
                event.preventDefault();
            }
            if (trigger.classList.contains('is-editing')) {
                return;
            }
            trigger.classList.add('is-editing');
            window.setTimeout(function () {
                input.focus();
                input.select();
            }, 20);
        }

        trigger.addEventListener('click', startEdit);
        trigger.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                startEdit(event);
            }
        });
        input.addEventListener('click', function (event) {
            event.stopPropagation();
        });
        input.addEventListener('input', syncJumpPreview);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                commitPageJump();
            } else if (event.key === 'Escape') {
                trigger.classList.remove('is-editing');
                input.value = input.defaultValue;
                syncJumpPreview();
            }
        });
        input.addEventListener('blur', commitPageJump);
    });
});
</script>
<script src="../js/global-player.js"></script>
<script src="/js/xingzai-widget.js" data-api="/backend/xingzai_chat.php" data-avatar="/images/xingzai-avatar.jpg"></script>
</body>
</html>
