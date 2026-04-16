<?php
require_once 'utils.php';
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function parseLrcEditable(?string $relativePath, string $lyricsText): array {
    $items = [];
    if ($relativePath) {
        $path = __DIR__ . '/' . ltrim($relativePath, '/');
        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                if (preg_match('/\[(\d{2}:\d{2}(?:\.\d{1,2})?)\](.*)$/u', $line, $m)) {
                    $items[] = ['time' => $m[1], 'text' => trim($m[2])];
                }
            }
        }
    }
    if (empty($items) && trim($lyricsText) !== '') {
        $rows = preg_split('/\r\n|\r|\n/', trim($lyricsText));
        $sec = 0;
        foreach ($rows as $row) {
            $row = trim($row);
            if ($row === '') continue;
            $items[] = ['time' => sprintf('%02d:%02d.00', floor($sec / 60), $sec % 60), 'text' => $row];
            $sec += 5;
        }
    }
    if (empty($items) && $relativePath) {
        $items[] = ['time' => '00:00.00', 'text' => '歌词文件已存在，但当前未成功解析，可手动补写'];
    }
    if (empty($items)) {
        $items[] = ['time' => '00:00.00', 'text' => ''];
    }
    return $items;
}

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$pdo = getPdo();
$stmt = $pdo->prepare('SELECT id, user_id, title, lyrics, lrc_path FROM songs WHERE id = ?');
$stmt->execute([$id]);
$song = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$song || ((int) $song['user_id'] !== (int) $_SESSION['user_id'])) {
    http_response_code(403);
    echo '无权编辑这首歌的歌词';
    exit;
}

$success = '';
$error = '';
$items = parseLrcEditable($song['lrc_path'] ?? null, $song['lyrics'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = '无效的 CSRF token';
    } else {
        $times = $_POST['lyric_time'] ?? [];
        $texts = $_POST['lyric_text'] ?? [];
        $rows = [];
        $plainLyrics = [];
        foreach ($times as $i => $time) {
            $time = trim((string) $time);
            $text = trim((string) ($texts[$i] ?? ''));
            if ($time === '' || $text === '') {
                continue;
            }
            if (!preg_match('/^\d{2}:\d{2}(?:\.\d{1,2})?$/', $time)) {
                $error = '时间格式必须类似 00:12.50';
                break;
            }
            $rows[] = '[' . $time . ']' . $text;
            $plainLyrics[] = $text;
        }

        if ($error === '') {
            $lrcContent = implode(PHP_EOL, $rows) . PHP_EOL;
            $lrcPath = $song['lrc_path'];
            if (empty($lrcPath)) {
                $dir = __DIR__ . '/lyrics';
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $lrcPath = 'lyrics/manual_' . $song['id'] . '_' . bin2hex(random_bytes(4)) . '.lrc';
            }
            file_put_contents(__DIR__ . '/' . $lrcPath, $lrcContent);
            $lyrics = implode(PHP_EOL, $plainLyrics);
            $pdo->prepare("UPDATE songs SET lyrics = ?, lrc_path = ?, lyrics_status = 'generated', lyrics_note = '歌词已由用户手动修正', lrc_generated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$lyrics, $lrcPath, $song['id']]);
            $success = '歌词和时间轴已保存';
            $song['lyrics'] = $lyrics;
            $song['lrc_path'] = $lrcPath;
            $items = parseLrcEditable($song['lrc_path'], $song['lyrics']);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>修改歌词 - 音乐后台</title>
    <link rel="stylesheet" href="../css/backend.css">
    <style>
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
        .lyric-row {
            display: grid !important;
            grid-template-columns: 1fr !important;
            gap: 10px;
        }
        .lyric-row input {
            max-width: none !important;
            min-width: 0 !important;
        }
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
                <strong>修改歌词</strong>
                <span><?php echo e($song['title']); ?></span>
            </div>
        </div>
        <button type="button" class="backend-mobile-toggle" id="backendMobileToggle" aria-expanded="false" aria-controls="backendLinks">☰</button>
        <div class="backend-links" id="backendLinks">
            <a href="manage_songs.php">返回歌曲管理</a>
        </div>
    </div>

    <div class="backend-card">
        <span class="backend-kicker">Lyrics Editor</span>
        <h1>时间在左，歌词在右</h1>
        <p>客户可以自己逐句修改时间轴和歌词，保存后会自动重写 LRC。</p>
        <?php if ($error): ?><div class="error-msg"><?php echo e($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="success-msg"><?php echo e($success); ?></div><?php endif; ?>

        <form class="backend-form" method="post" action="">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $song['id']; ?>">
            <div id="lyricsRows" style="display:grid; gap:12px;">
                <?php foreach ($items as $row): ?>
                    <div class="inline-actions lyric-row" style="align-items:flex-start;">
                        <input type="text" name="lyric_time[]" value="<?php echo e($row['time']); ?>" placeholder="00:12.50" style="max-width:140px;">
                        <input type="text" name="lyric_text[]" value="<?php echo e($row['text']); ?>" placeholder="这一句歌词" style="flex:1; min-width:280px;">
                        <button type="button" class="button-link secondary-btn remove-row">删除</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="inline-actions" style="margin-top:16px;">
                <button type="button" id="addRow" class="button-link secondary-btn">新增一行</button>
                <button type="submit" class="primary-btn">保存歌词与时间轴</button>
            </div>
        </form>
    </div>
</div>
<script>
var backendToggle = document.getElementById('backendMobileToggle');
var backendLinks = document.getElementById('backendLinks');
if (backendToggle && backendLinks) {
    backendToggle.addEventListener('click', function () {
        var open = backendLinks.classList.toggle('open');
        backendToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
}

document.getElementById('addRow').addEventListener('click', function () {
    var wrap = document.getElementById('lyricsRows');
    var row = document.createElement('div');
    row.className = 'inline-actions lyric-row';
    row.style.alignItems = 'flex-start';
    row.innerHTML = '<input type="text" name="lyric_time[]" value="00:00.00" placeholder="00:12.50" style="max-width:140px;">' +
        '<input type="text" name="lyric_text[]" value="" placeholder="这一句歌词" style="flex:1; min-width:280px;">' +
        '<button type="button" class="button-link secondary-btn remove-row">删除</button>';
    wrap.appendChild(row);
});

document.addEventListener('click', function (event) {
    if (event.target.classList.contains('remove-row')) {
        var rows = document.querySelectorAll('.lyric-row');
        if (rows.length > 1) {
            event.target.closest('.lyric-row').remove();
        }
    }
});
</script>
<script src="/js/xingzai-widget.js" data-api="/backend/xingzai_chat.php" data-avatar="/images/xingzai-avatar.jpg"></script>
</body>
</html>
