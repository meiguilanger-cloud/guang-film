<?php
require_once __DIR__ . '/backend/utils.php';
require_once __DIR__ . '/backend/config.php';

$isLoggedIn = !empty($_SESSION['user_id']);
$currentUser = null;
$loginTarget = 'backend/' . loginUrlWithReturn('../star-ai.php');
$packages = [
    ['price' => '50', 'credits' => '100', 'label' => '体验包'],
    ['price' => '100', 'credits' => '200', 'label' => '入门包'],
    ['price' => '500', 'credits' => '1000', 'label' => '标准包'],
    ['price' => '4600', 'credits' => '10000', 'label' => '专业包'],
    ['price' => '12800', 'credits' => '27500', 'label' => '旗舰包'],
];
$creditCosts = starwavesCreditCosts();
$recentTracks = [];
$recentTrackPage = max(1, (int) ($_GET['tracks_page'] ?? 1));
$recentTrackPerPage = 5;
$recentTrackTotal = 0;
$recentTrackPages = 1;
$trackSearch = trim((string) ($_GET['track_search'] ?? ''));
$coverPendingPage = $recentTrackPage === 1 && (($_GET['cover_pending'] ?? '') === '1');
$coverPendingTitle = trim((string) ($_GET['cover_title'] ?? '翻唱生成中'));
$remixPendingPage = $recentTrackPage === 1 && (($_GET['remix_pending'] ?? '') === '1');
$remixPendingTitle = trim((string) ($_GET['remix_title'] ?? '重新混音生成中'));

function parseStarAiLrcFile(?string $relativePath): array {
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '') {
        return [];
    }
    $fullPath = __DIR__ . '/backend/' . ltrim($relativePath, '/');
    if (!is_file($fullPath)) {
        return [];
    }
    $items = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) file_get_contents($fullPath)) as $line) {
        if (!preg_match('/\[(\d{2}):(\d{2}(?:\.\d{1,2})?)\](.*)/', trim((string) $line), $matches)) {
            continue;
        }
        $text = trim((string) ($matches[3] ?? ''));
        if ($text === '') {
            continue;
        }
        $items[] = [
            'time' => ((int) $matches[1] * 60) + (float) $matches[2],
            'text' => $text,
        ];
    }
    return $items;
}

function inferTrackStyle(array $track): string {
    $source = strtolower(trim((string) ($track['source'] ?? '')));
    $description = trim((string) ($track['description'] ?? ''));
    $title = strtolower(trim((string) ($track['title'] ?? '')));
    $lyrics = strtolower(trim((string) ($track['lyrics'] ?? '')));
    $context = strtolower($description . ' ' . $title . ' ' . $lyrics);
    $styleMap = [
        '国风流行' => ['china', 'chinese', 'guofeng', '古风', '国风', '戏腔'],
        '电子流行' => ['edm', 'electro', 'electronic', 'future bass', 'house', 'dance', 'techno'],
        '说唱 / Hip-Hop' => ['rap', 'hiphop', 'hip-hop', 'trap', 'boombap'],
        '摇滚' => ['rock', 'metal', 'punk', 'band'],
        'R&B / Soul' => ['r&b', 'soul', 'neo soul', 'funk', 'groove'],
        '民谣 / 抒情流行' => ['folk', 'acoustic', 'ballad', 'piano', 'guitar', '抒情', '民谣'],
        '流行舞曲' => ['pop', 'dance pop', 'synthpop'],
        '纯音乐 / 氛围' => ['instrumental', 'ambient', 'cinematic', 'soundtrack']
    ];

    if ($source === 'ai' && $description !== '') {
        $parts = preg_split('/\s*\/\s*|\s*,\s*|\s*\|\s*/u', $description) ?: [];
        $clean = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || in_array(strtolower($part), ['zh', 'en', 'yue', 'male vocal', 'female vocal', 'vocal'], true)) {
                continue;
            }
            $clean[] = $part;
        }
        if (!empty($clean)) {
            return implode(' / ', array_slice($clean, 0, 2));
        }
    }

    foreach ($styleMap as $label => $keywords) {
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && strpos($context, strtolower($keyword)) !== false) {
                return $label;
            }
        }
    }

    if ($source === 'ai') {
        return 'AI 流行';
    }
    return '流行';
}

try {
    if ($isLoggedIn) {
        $pdo = getPdo();
        $stmt = $pdo->prepare('SELECT username, full_name, avatar_path FROM users WHERE id = ?');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $currentUser = $stmt->fetch();

        $whereSql = 'WHERE user_id = :user_id';
        if ($trackSearch !== '') {
            $whereSql .= ' AND (title LIKE :track_search OR description LIKE :track_search OR lyrics LIKE :track_search)';
        }

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM songs ' . $whereSql);
        $countStmt->bindValue(':user_id', (int) $_SESSION['user_id'], PDO::PARAM_INT);
        if ($trackSearch !== '') {
            $countStmt->bindValue(':track_search', '%' . $trackSearch . '%', PDO::PARAM_STR);
        }
        $countStmt->execute();
        $recentTrackTotal = (int) $countStmt->fetchColumn();
        $recentTrackPages = max(1, (int) ceil($recentTrackTotal / $recentTrackPerPage));
        $recentTrackPage = min($recentTrackPage, $recentTrackPages);
        $recentTrackOffset = ($recentTrackPage - 1) * $recentTrackPerPage;

        $recentStmt = $pdo->prepare('SELECT id, title, file_path, storage_type, archive_path, image_url, source, description, lyrics, lrc_path, created_at, mastering_status, mastered_preview_path, mastered_file_path, duration_label, duration_seconds FROM songs ' . $whereSql . ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset');
        $recentStmt->bindValue(':user_id', (int) $_SESSION['user_id'], PDO::PARAM_INT);
        if ($trackSearch !== '') {
            $recentStmt->bindValue(':track_search', '%' . $trackSearch . '%', PDO::PARAM_STR);
        }
        $recentStmt->bindValue(':limit', $recentTrackPerPage, PDO::PARAM_INT);
        $recentStmt->bindValue(':offset', $recentTrackOffset, PDO::PARAM_INT);
        $recentStmt->execute();
        $recentTracks = $recentStmt->fetchAll();
    }
} catch (Throwable $e) {
    $currentUser = null;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>STAR.AI | 星浪音乐</title>
    <link href="<?php echo htmlspecialchars(siteAssetUrl('css/bootstrap.css')); ?>" rel="stylesheet" type="text/css" media="all" />
    <link href="<?php echo htmlspecialchars(siteAssetUrl('css/style.css')); ?>" rel="stylesheet" type="text/css" media="all" />
    <link href="<?php echo htmlspecialchars(siteAssetUrl('css/starwaves.css')); ?>" rel="stylesheet" type="text/css" media="all" />
    <link href="<?php echo htmlspecialchars(siteAssetUrl('css/font-awesome.css')); ?>" rel="stylesheet">
    <style>
        html, body {
            max-width: 100%;
            overflow-x: hidden;
        }
        .swai-shell {
            min-height: 100vh;
            max-width: 100%;
            overflow-x: hidden;
            background:
                radial-gradient(circle at top right, rgba(230, 182, 92, 0.24), transparent 24%),
                radial-gradient(circle at left center, rgba(78, 123, 185, 0.15), transparent 20%),
                linear-gradient(180deg, #080d13, #121a24 38%, #efe7d8 38%, #efe7d8 100%);
        }
        .swai-top-space { padding-top: 138px; padding-bottom: 72px; }
        .swai-workbench {
            display: grid;
            grid-template-columns: minmax(0, 1.45fr) minmax(320px, .8fr);
            gap: 26px;
            align-items: start;
        }
        .swai-main-panel, .swai-side-card, .swai-recent-card, .swai-package-card {
            background: rgba(255,255,255,.96);
            border-radius: 30px;
            box-shadow: 0 22px 60px rgba(8, 10, 14, 0.14);
            min-width: 0;
            max-width: 100%;
        }
        .swai-main-panel { padding: 30px; }
        .swai-side-card, .swai-recent-card { padding: 24px; margin-bottom: 18px; overflow: visible; }
        .swai-kicker {
            display:inline-flex;
            padding:8px 14px;
            border-radius:999px;
            background:rgba(230,182,92,.14);
            color:#9e6c13;
            font-size:12px;
            letter-spacing:1px;
            text-transform:uppercase;
            font-weight:700;
        }
        .swai-hero { display:grid; grid-template-columns: minmax(0, 1.1fr) minmax(240px, .9fr); gap:22px; margin-top: 18px; }
        .swai-title { font-size: 9px; line-height: 1.4; margin: 2px 0 0; color:#b57f1f; font-weight: 500; }
        .swai-title span { color: inherit; display:inline; }
        .swai-sub { color:#645c51; line-height:1.86; font-size:16px; }
        .swai-stats {
            display:grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 14px;
        }
        .swai-stat-box {
            padding: 18px; border-radius: 24px; background: linear-gradient(180deg, #171e28, #0f1319); color:#fff;
        }
        .swai-stat-box strong { display:block; font-size: 28px; color:#f2c468; margin-top: 8px; }
        .swai-tabs { display:flex; flex-wrap:wrap; gap: 12px; margin-top: 26px; }
        .swai-tab {
            border: 1px solid rgba(16,21,28,.12); background:#fff; color:#171c22; border-radius:999px; padding:12px 18px; font-weight:700;
        }
        .swai-tab.active { background: linear-gradient(135deg, #e6b65c, #f3ca78); color:#17120b; border-color: transparent; }
        .swai-tab-panel { display:none; margin-top: 22px; }
        .swai-tab-panel.active { display:block !important; }
        .swai-form { display:grid; gap: 18px; }
        .swai-form label { display:block; font-weight:700; color:#171c22; margin-bottom: 8px; }
        .swai-form input,
        .swai-form textarea,
        .swai-form select {
            width:100%;
            border: 1px solid rgba(16,21,28,.11);
            background:#fff;
            color:#171c22;
            border-radius:20px;
            padding:15px 16px;
            font-size:15px;
        }
        .swai-form textarea { min-height: 126px; resize: vertical; }
        .swai-auto-grow-lyrics {
            min-height: 126px;
            max-height: 50vh;
            overflow-y: auto;
            resize: none;
            transition: height .16s ease;
        }
        .swai-row { display:grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 16px; }
        .swai-row-3 { display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 16px; }
        .swai-hint { color:#7a7164; font-size: 13px; line-height:1.65; margin-top: 8px; }
        .swai-chip-row { display:flex; flex-wrap:wrap; gap: 10px; }
        .swai-chip {
            padding: 10px 14px; border-radius:999px; background:#f5eddc; color:#3a3126; font-weight:700; cursor:pointer;
            border: 1px solid rgba(230,182,92,.2);
        }
        .swai-chip.active { background:#151b22; color:#fff; border-color:#151b22; }
        .swai-chip-row.helper { margin-top: 12px; }
        .swai-slider-wrap {
            padding: 18px; border-radius: 22px; background: #f7f1e4; border:1px solid rgba(230,182,92,.16);
        }
        .swai-slider-value { font-weight: 800; color:#a87214; }
        .swai-submit {
            border:0; border-radius:999px; background: linear-gradient(135deg, #e6b65c, #f4cb7a); color:#15120d;
            padding: 15px 24px; font-size:15px; font-weight:800;
        }
        .swai-status {
            margin-top: 18px; padding: 14px 16px; border-radius: 18px; background:#f6efe2; color:#4b4032; display:none;
        }
        .swai-result-item {
            padding: 12px 0;
            border-top: 1px solid rgba(0,0,0,.08);
        }
        .swai-result-item:first-child {
            border-top: 0;
            padding-top: 0;
        }
        .swai-result-item audio {
            width: 100%;
            margin-top: 10px;
        }
        .swai-side-card h4, .swai-recent-card h4 { margin: 14px 0 10px; color:#10151c; }
        .swai-side-card ul { padding-left: 18px; margin: 0; }
        .swai-side-card li { margin-bottom: 10px; color:#5d564c; line-height: 1.7; }
        .swai-toggle {
            width:100%; border:0; border-radius:18px; background:#121a24; color:#fff; padding:14px 18px;
            display:flex; align-items:center; justify-content:space-between; font-size:15px; font-weight:700;
        }
        .swai-collapsible { display:none; margin-top:18px; }
        .swai-collapsible.open { display:block; }
        .swai-package-grid { display:grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 14px; }
        .swai-package-card { padding: 20px; border-radius: 22px; border:1px solid rgba(230,182,92,.15); cursor: pointer; transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease; }
        .swai-package-card:hover { transform: translateY(-2px); border-color: rgba(181,127,31,.45); box-shadow: 0 12px 30px rgba(0,0,0,.08); }
        .swai-package-card.active { border-color: #b57f1f; box-shadow: 0 0 0 2px rgba(181,127,31,.14); }
        .swai-package-card strong { display:block; font-size: 26px; color:#10151c; margin: 8px 0 6px; }
        .swai-package-card span { color:#8f6628; font-weight:700; }
        .swai-mini { color:#6b6258; line-height:1.75; }
        .swai-payment {
            display:flex;
            width:fit-content;
            max-width:100%;
            flex-wrap:wrap;
            gap:10px;
            margin:12px auto 0;
            justify-content:center;
            align-items:center;
            text-align:center;
        }
        .swai-payment span { padding:8px 12px; border-radius:999px; background:#121a24; color:#fff; font-size:12px; letter-spacing:.4px; }
        .swai-custom-topup {
            margin-top: 16px;
            padding: 18px;
            border-radius: 22px;
            background: #f7f1e4;
            border: 1px solid rgba(230,182,92,.16);
        }
        .swai-custom-topup strong {
            color: #10151c;
            display: block;
            margin-bottom: 8px;
        }
        .swai-custom-topup input {
            width: 100%;
            border: 1px solid rgba(16,21,28,.12);
            border-radius: 16px;
            padding: 14px 16px;
            font-size: 15px;
            background: #fff;
            color: #171c22;
        }
        .swai-pay-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 16px;
        }
        .swai-pay-tab {
            border: 0;
            border-radius: 999px;
            background: #efe4cd;
            color: #2f271d;
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 700;
        }
        .swai-pay-modal,
        .swai-cover-modal {
            position: fixed;
            inset: 0;
            background: rgba(7, 10, 15, 0.72);
            display: none;
            align-items: flex-end; /* align dialog to bottom */
            justify-content: center;
            padding: 20px;
            z-index: 100100;
        }
        .swai-pay-modal.open,
        .swai-cover-modal.open {
            display: flex;
        }
        body.swai-cover-open .sw-global-player {
            display: none !important;
        }
        .swai-pay-dialog {
            width: min(420px, 100%);
            background: #fffaf1;
            border-radius: 28px;
            padding: 24px;
            box-shadow: 0 24px 80px rgba(0,0,0,.26);
            position: relative;
        }
        .swai-cover-dialog {
            width: min(560px, 100%);
            max-height: min(90vh, 860px);
            overflow: auto;
            background: linear-gradient(180deg, #fffaf1 0%, #f7efdf 100%);
            border-radius: 32px;
            padding: 22px;
            box-shadow: 0 24px 90px rgba(0,0,0,.28);
            position: relative;
            transform: translateY(100%);
            transition: transform 0.5s ease-out;
        }
        .swai-cover-modal.open .swai-cover-dialog {
            transform: translateY(0);
        }
        .swai-pay-close,
        .swai-cover-close {
            position: absolute;
            top: 14px;
            right: 16px;
            border: 0;
            background: transparent;
            font-size: 24px;
            color: #6d6355;
        }
        .swai-pay-qr {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 220px;
            border-radius: 22px;
            background: linear-gradient(180deg, #f1e5cc, #f8f1e5);
            border: 1px dashed rgba(181,127,31,.4);
            color: #7a6238;
            text-align: center;
            padding: 18px;
            margin-top: 14px;
            overflow: hidden;
        }
        .swai-pay-qr img {
            width: 100%;
            max-width: 280px;
            border-radius: 18px;
            display: block;
        }
        .swai-pay-summary {
            margin-top: 12px;
            padding: 14px 16px;
            border-radius: 18px;
            background: #f5ecdb;
            color: #433625;
            line-height: 1.7;
        }
        .swai-recent-list { display:grid; gap: 12px; }
        .swai-recent-item {
            padding: 16px 18px; border-radius: 20px; background: #f7f2e8; border:1px solid rgba(16,21,28,.06);
        }
        .swai-recent-item strong { display:block; color:#151b22; margin-bottom: 4px; }
        .swai-recent-item span { color:#776f65; font-size:13px; }
        .swai-right-column { display:grid; gap:18px; }
        .swai-library-card { padding:24px; }
        .swai-library-toolbar {
            display:flex;
            align-items:center;
            gap:10px;
            margin-top:14px;
        }
        .swai-library-search {
            flex:1;
            min-width:0;
            height:42px;
            border-radius:999px;
            border:1px solid rgba(18,26,36,.12);
            background:#fffaf1;
            padding:0 16px;
            color:#20180f;
            font-size:14px;
        }
        .swai-library-search-btn {
            border:0;
            height:42px;
            padding:0 16px;
            border-radius:999px;
            background:#171b21;
            color:#fff8eb;
            font-weight:800;
            white-space:nowrap;
        }
        .swai-library-list { display:grid; gap:12px; margin-top:14px; }
        .swai-library-pagination {
            display:flex;
            align-items:center;
            justify-content:center;
            flex-wrap:nowrap;
            gap:10px;
            margin-top:18px;
            width:100%;
            min-width:0;
        }
        .swai-page-btn {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            flex:0 1 auto;
            min-width:0;
            padding:10px 14px;
            border-radius:999px;
            background:#121a24;
            color:#fff;
            font-weight:700;
            white-space:nowrap;
        }
        .swai-page-btn.disabled {
            opacity:.35;
            pointer-events:none;
        }
        .swai-page-indicator {
            flex:0 0 auto;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-width:96px;
            height:32px;
            padding:0 12px;
            border-radius:999px;
            background:#f3ecde;
            color:#5f564a;
            font-size:12px;
            font-weight:700;
            line-height:1;
            white-space:nowrap;
            cursor:pointer;
            position:relative;
        }
        .swai-page-indicator.is-editing {
            padding:0 8px;
            background:#efe4cf;
        }
        .swai-page-indicator-text {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            width:100%;
        }
        .swai-page-indicator-input {
            display:none;
            width:100%;
            height:100%;
            border:0;
            outline:none;
            background:transparent;
            color:#2c241b;
            text-align:center;
            font-size:12px;
            font-weight:700;
            padding:0;
        }
        .swai-page-indicator.is-editing .swai-page-indicator-text {
            display:none;
        }
        .swai-page-indicator.is-editing .swai-page-indicator-input {
            display:block;
        }
        .swai-mobile-library { display:none; }
        .swai-library-item {
            display:flex;
            align-items:center;
            gap:12px;
            padding:12px;
            border-radius:18px;
            background:#f7f2e8;
            border:1px solid rgba(16,21,28,.06);
            overflow:visible;
            position:relative;
            cursor:pointer;
        }
        .swai-library-item.is-cover-pending {
            background: linear-gradient(135deg, rgba(15, 19, 25, 0.94), rgba(47, 55, 66, 0.86), rgba(22, 27, 34, 0.94));
            background-size: 220% 220%;
            border-color: rgba(255,255,255,0.08);
            color: #f6f1e7;
            box-shadow: inset 0 0 0 1px rgba(255,255,255,0.03);
            animation: swaiCoverPendingPulse 2.8s ease-in-out infinite;
        }
        .swai-library-item.is-cover-pending .swai-track-style,
        .swai-library-item.is-cover-pending .swai-track-cover-time {
            color: rgba(246, 241, 231, 0.72);
        }
        .swai-library-item.is-cover-pending .swai-track-cover {
            background: linear-gradient(135deg, #313843, #161b22);
            box-shadow: 0 10px 24px rgba(0,0,0,.22);
        }
        .swai-library-item.is-cover-pending .swai-track-wave span {
            opacity: .45;
            background: rgba(255,255,255,.34);
        }
        .swai-track-pending-badge {
            display:inline-flex;
            align-items:center;
            gap:6px;
            margin-left:8px;
            margin-top:2px;
            padding:4px 9px;
            border-radius:999px;
            background: rgba(255,255,255,.08);
            color: rgba(246, 241, 231, 0.86);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            animation: swaiCoverPendingBadgeBlink 1.8s ease-in-out infinite;
        }
        @keyframes swaiCoverPendingPulse {
            0% {
                background-position: 0% 50%;
                filter: brightness(0.9);
            }
            50% {
                background-position: 100% 50%;
                filter: brightness(1.08);
            }
            100% {
                background-position: 0% 50%;
                filter: brightness(0.9);
            }
        }
        @keyframes swaiCoverPendingBadgeBlink {
            0%, 100% {
                opacity: 0.58;
                transform: translateY(0);
            }
            50% {
                opacity: 1;
                transform: translateY(-1px);
            }
        }
        .swai-track-cover {
            position:relative;
            width:72px;
            min-width:72px;
            height:72px;
            border-radius:18px;
            overflow:hidden;
            background:linear-gradient(135deg, #d7c4a1, #8d7147);
            box-shadow:0 10px 26px rgba(0,0,0,.12);
        }
        .swai-track-cover img {
            width:100%;
            height:100%;
            object-fit:cover;
            display:block;
        }
        .swai-track-cover::after {
            content:'▶';
            position:absolute;
            left:50%;
            top:50%;
            transform:translate(-46%, -55%);
            width:34px;
            height:34px;
            border-radius:999px;
            display:flex;
            align-items:center;
            justify-content:center;
            background:rgba(255,255,255,.88);
            color:#2b2117;
            font-size:14px;
            box-shadow:0 10px 20px rgba(0,0,0,.18);
            z-index:1;
        }
        .swai-track-wave {
            position:absolute;
            left:50%;
            top:50%;
            transform:translate(-50%, -50%);
            width:18px;
            height:14px;
            display:flex;
            align-items:flex-end;
            justify-content:center;
            gap:2px;
            z-index:2;
            opacity:0;
            transition:opacity .18s ease;
        }
        .swai-track-wave span {
            width:3px;
            height:4px;
            border-radius:999px;
            background:#2b2117;
            opacity:.9;
            transition:height .18s ease, opacity .18s ease;
        }
        .swai-track-wave span:nth-child(2) { height:8px; }
        .swai-track-wave span:nth-child(3) { height:6px; }
        .swai-track-wave span:nth-child(4) { height:10px; }
        .swai-library-item.is-playing .swai-track-cover::after {
            opacity:0;
        }
        .swai-library-item.is-playing .swai-track-cover img {
            filter:brightness(.58) saturate(.9);
        }
        .swai-library-item.is-playing .swai-track-wave {
            opacity:1;
        }
        .swai-library-item.is-playing .swai-track-wave span {
            animation:swaiTrackWave .8s ease-in-out infinite;
            background:#fff7e2;
            box-shadow:0 0 10px rgba(255,247,226,.35);
        }
        .swai-library-item.is-playing .swai-track-wave span:nth-child(2) { animation-delay:.12s; }
        .swai-library-item.is-playing .swai-track-wave span:nth-child(3) { animation-delay:.24s; }
        .swai-library-item.is-playing .swai-track-wave span:nth-child(4) { animation-delay:.36s; }
        @keyframes swaiTrackWave {
            0%, 100% { height:4px; opacity:.72; }
            50% { height:13px; opacity:1; }
        }
        .swai-track-cover-time {
            position:absolute;
            left:50%;
            bottom:5px;
            transform:translateX(-50%);
            min-width:34px;
            padding:1px 5px;
            border-radius:999px;
            background:rgba(0,0,0,.86);
            color:#ffffff;
            text-align:center;
            font-size:8px;
            font-weight:800;
            letter-spacing:0;
            line-height:1.1;
            box-shadow:0 3px 10px rgba(0,0,0,.22);
            text-shadow:0 1px 2px rgba(0,0,0,.35);
            z-index:3;
        }
        .swai-track-main {
            min-width:0;
            flex:1;
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:10px;
        }
        .swai-track-copy {
            min-width:0;
            flex:1;
        }
        .swai-track-title-row {
            display:grid;
            grid-template-columns:minmax(0, 1fr) auto auto;
            align-items:start;
            gap:8px;
            min-width:0;
        }
        .swai-track-unheard-dot {
            width:8px;
            height:8px;
            border-radius:999px;
            background:#ff6b6b;
            box-shadow:0 0 0 3px rgba(255,107,107,.16);
            flex:0 0 auto;
            margin-top:6px;
        }
        .swai-library-item strong {
            display:block;
            color:#151b22;
            margin:0;
            font-size:16px;
            line-height:1.22;
            max-width:100%;
            min-width:0;
            white-space:normal;
            overflow:visible;
            text-overflow:clip;
            word-break:break-word;
            overflow-wrap:anywhere;
        }
        .swai-library-item strong.is-long-title {
            font-size:14px;
            line-height:1.18;
        }
        .swai-library-item strong.is-very-long-title {
            font-size:12px;
            line-height:1.15;
            letter-spacing:-0.01em;
        }
        .swai-track-style.is-long-style {
            font-size:11px;
            line-height:1.35;
        }
        .swai-track-style.is-very-long-style {
            font-size:10px;
            line-height:1.3;
        }
        .swai-track-style {
            margin-top:5px;
            color:#6b6258;
            font-size:12px;
            line-height:1.4;
            display:-webkit-box;
            -webkit-line-clamp:2;
            -webkit-box-orient:vertical;
            overflow:hidden;
            overflow-wrap:anywhere;
            word-break:break-word;
        }
        .swai-track-duration {
            color:#6b6258;
            font-size:12px;
            font-weight:700;
            letter-spacing:.02em;
        }
        .swai-track-menu-wrap { position:relative; margin-left:auto; z-index:80; }
        .swai-track-menu-wrap[open] { z-index:1200; }
        .swai-track-menu-wrap summary { list-style: none; }
        .swai-track-menu-wrap[open] summary { opacity:0; pointer-events:none; }
        .swai-track-menu-wrap summary::-webkit-details-marker { display:none; }
        .swai-track-menu-btn {
            border:0;
            background:#efe4cd;
            color:#2f271d;
            width:30px;
            height:30px;
            border-radius:999px;
            padding:0;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:16px;
            font-weight:800;
            line-height:1;
            text-align:center;
        }
        .swai-track-menu {
            position:absolute;
            top:12px;
            right:0;
            min-width:148px;
            padding:6px;
            border-radius:16px;
            background:#fffaf1;
            box-shadow:0 16px 40px rgba(0,0,0,.16);
            border:1px solid rgba(16,21,28,.08);
            display:none;
            z-index:999;
        }
        .swai-track-menu.open { display:block; }
        .swai-track-menu-wrap[open] .swai-track-menu { display:block; }
        .swai-track-menu button,
        .swai-track-menu .swai-track-link {
            width:100%;
            border:0;
            background:transparent;
            text-align:left;
            padding:8px 10px;
            border-radius:12px;
            color:#221c15;
            font-weight:700;
        }
        .swai-track-menu button:hover,
        .swai-track-menu .swai-track-link:hover { background:#f5ecdb; }
        .swai-track-submenu {
            margin-top:6px;
            padding-top:6px;
        }
        .swai-submenu-toggle {
            position:relative;
            padding-right:26px !important;
        }
        .swai-submenu-toggle::after {
            content:'+';
            position:absolute;
            right:10px;
            top:50%;
            transform:translateY(-50%);
            font-size:15px;
            font-weight:800;
        }
        .swai-submenu-toggle.open::after { content:'-'; }
        .swai-submenu-items {
            display:none;
            margin-top:6px;
            padding-left:10px;
        }
        .swai-submenu-items.open { display:block; }
        .swai-track-tag {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            align-self:start;
            margin-top:2px;
            padding:2px 8px;
            border-radius:999px;
            font-size:11px;
            font-weight:800;
            letter-spacing:.4px;
            color:#111;
            background:#7ec8ff;
        }
        .swai-track-tag.ai { background:#f3ca78; }
        .swai-cover-sheet-bar {
            width: 56px;
            height: 6px;
            border-radius: 999px;
            background: rgba(70, 57, 39, .14);
            margin: 0 auto 16px;
        }
        .swai-cover-header {
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:12px;
            margin-bottom:16px;
        }
        .swai-cover-title h4 {
            margin:0;
            color:#171b21;
            font-size:24px;
        }
        .swai-cover-title p {
            margin:6px 0 0;
            color:#716756;
            line-height:1.6;
        }
        .swai-cover-track {
            display:flex;
            gap:14px;
            padding:14px;
            border-radius:24px;
            background:rgba(255,255,255,.72);
            border:1px solid rgba(16,21,28,.06);
            box-shadow:inset 0 1px 0 rgba(255,255,255,.6);
        }
        .swai-cover-art {
            width:88px;
            min-width:88px;
            height:88px;
            border-radius:24px;
            overflow:hidden;
            background:linear-gradient(135deg, #d7c4a1, #8d7147);
            position:relative;
        }
        .swai-cover-art img { width:100%; height:100%; object-fit:cover; display:block; }
        .swai-cover-track-copy { flex:1; min-width:0; }
        .swai-cover-track-copy strong {
            display:block;
            color:#171b21;
            font-size:18px;
            line-height:1.3;
            margin-bottom:6px;
        }
        .swai-cover-track-copy span {
            display:block;
            color:#6f6659;
            font-size:13px;
            line-height:1.5;
        }
        .swai-cover-wave {
            margin-top:16px;
            padding:16px 18px 14px;
            border-radius:24px;
            background:linear-gradient(180deg, rgba(23,27,33,.96), rgba(42,47,56,.92));
            color:#fff;
        }
        .swai-cover-wave-meta {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:12px;
        }
        .swai-cover-wave-btn {
            width:42px;
            height:42px;
            border:0;
            border-radius:999px;
            background:#f3ca78;
            color:#171b21;
            font-size:16px;
            font-weight:800;
        }
        .swai-cover-wave-btn.is-playing { background:#fff; }
        .swai-cover-waveform {
            position:relative;
            height:74px;
            border-radius:22px;
            background:linear-gradient(180deg, rgba(255,255,255,.12), rgba(255,255,255,.03));
            overflow:hidden;
            display:flex;
            align-items:flex-end;
            gap:3px;
            padding:10px 14px;
        }
        .swai-cover-waveform span {
            flex:1;
            border-radius:999px 999px 0 0;
            background:linear-gradient(180deg, rgba(243,202,120,.95), rgba(243,202,120,.35));
            min-height:12px;
            opacity:.9;
        }
        .swai-cover-wave-progress {
            margin-top:10px;
            display:flex;
            justify-content:space-between;
            color:rgba(255,255,255,.74);
            font-size:12px;
            font-weight:700;
        }
        .swai-cover-form-grid {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:14px;
            margin-top:16px;
        }
        .swai-cover-field {
            padding:14px;
            border-radius:22px;
            background:rgba(255,255,255,.72);
            border:1px solid rgba(16,21,28,.06);
        }
        .swai-cover-field.full { grid-column:1 / -1; }
        .swai-cover-field label {
            display:block;
            margin-bottom:10px;
            color:#443829;
            font-size:12px;
            font-weight:800;
            letter-spacing:.08em;
            text-transform:uppercase;
        }
        .swai-cover-chip-group { display:flex; flex-wrap:wrap; gap:8px; }
        .swai-cover-chip {
            border:0;
            border-radius:999px;
            padding:9px 12px;
            background:#efe4cd;
            color:#2f271d;
            font-size:13px;
            font-weight:700;
        }
        .swai-cover-chip.active {
            background:#171b21;
            color:#fff7e9;
            box-shadow:0 10px 20px rgba(0,0,0,.12);
        }
        .swai-cover-input,
        .swai-cover-textarea,
        .swai-cover-select {
            width:100%;
            border:1px solid rgba(16,21,28,.08);
            border-radius:18px;
            background:#fffdf9;
            padding:12px 14px;
            color:#171b21;
        }
        .swai-cover-input {
            min-height:46px;
        }
        .swai-cover-textarea {
            min-height:88px;
            resize:vertical;
        }
        .swai-rename-dialog {
            max-width: 420px;
            background: linear-gradient(180deg, #0d131a, #111821);
            color: #f5efe3;
            box-shadow: 0 30px 80px rgba(0,0,0,.42);
        }
        .swai-rename-dialog .swai-cover-title h4,
        .swai-rename-dialog .swai-cover-title p,
        .swai-rename-dialog .swai-cover-field label,
        .swai-rename-dialog .swai-cover-status {
            color: #f5efe3;
        }
        .swai-rename-dialog .swai-cover-field {
            background: rgba(255,255,255,.04);
            border-color: rgba(255,255,255,.08);
        }
        .swai-rename-dialog .swai-cover-textarea {
            min-height: 54px;
            background: #0a1016;
            border-color: rgba(255,255,255,.1);
            color: #fff7e9;
        }
        .swai-rename-dialog .swai-cover-submit {
            background: linear-gradient(135deg, #f2c46b, #f59f53);
            color: #17120b;
        }
        .swai-remix-choice-grid {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:10px;
        }
        .swai-remix-strength-grid {
            display:grid;
            grid-template-columns:repeat(3, minmax(0, 1fr));
            gap:10px;
        }
        .swai-remix-option {
            border:1px solid rgba(16,21,28,.08);
            border-radius:18px;
            background:#fffdf9;
            padding:12px 12px 11px;
            color:#171b21;
            text-align:left;
            transition:all .18s ease;
            box-shadow:0 4px 14px rgba(0,0,0,.03);
        }
        .swai-remix-option strong {
            display:block;
            font-size:14px;
            line-height:1.2;
            margin-bottom:4px;
        }
        .swai-remix-option span {
            display:block;
            color:#6d6355;
            font-size:11px;
            line-height:1.4;
        }
        .swai-remix-option.active {
            background:#171b21;
            border-color:#171b21;
            box-shadow:0 14px 30px rgba(0,0,0,.16);
        }
        .swai-remix-option.active strong,
        .swai-remix-option.active span {
            color:#fff8eb;
        }
        .swai-remix-mini-note {
            margin-top:10px;
            color:#6f6659;
            font-size:12px;
            line-height:1.5;
        }
        .swai-cover-footer {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-top:16px;
            padding-top:14px;
        }
        .swai-cover-submit {
            border:0;
            border-radius:999px;
            padding:14px 24px;
            background:#171b21;
            color:#fff8eb;
            font-weight:800;
            font-size:15px;
            box-shadow:0 14px 30px rgba(0,0,0,.18);
        }
        .swai-cover-status {
            min-height:20px;
            color:#6d6355;
            font-size:13px;
            line-height:1.5;
        }
        .swai-cover-status.is-error { color:#a13c2d; }
        .swai-cover-status.is-success { color:#1d6a42; }
        .swai-master-pill {
            display:inline-flex;
            align-items:center;
            gap:6px;
            margin-top:8px;
            padding:6px 10px;
            border-radius:999px;
            background:rgba(230,182,92,.18);
            color:#6f4d10;
            font-size:11px;
            font-weight:800;
            letter-spacing:.04em;
            text-transform:uppercase;
        }
        .swai-master-pill.is-processing { background:rgba(21,27,33,.10); color:#1f2b37; }
        .swai-master-pill.is-completed { background:rgba(33,140,86,.14); color:#1d6a42; }
        .swai-master-pill.is-failed { background:rgba(161,60,45,.12); color:#a13c2d; }
        .swai-cover-result-card {
            margin-top:14px;
            padding:18px;
            border-radius:28px;
            background:linear-gradient(180deg, #eef7ef 0%, #edf4ea 100%);
            box-shadow:inset 0 0 0 1px rgba(255,255,255,.75);
        }
        .swai-cover-result-card .swai-result-item {
            margin-top:0;
            padding:0;
            border:0;
            background:transparent;
            box-shadow:none;
        }
        .swai-cover-result-card .swai-result-cover {
            border-radius:22px;
            margin-top:14px;
        }
        .swai-cover-result-card .swai-result-links {
            margin-top:16px;
        }
        .swai-bottom-center { width:min(760px, 100%); margin:28px auto 0; }
        @media (max-width: 1080px) {
            .swai-workbench, .swai-hero { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .container.swai-top-space {
                width:100%;
                padding-left:0;
                padding-right:0;
                margin-left:auto;
                margin-right:auto;
            }
            .swai-workbench {
                width:100%;
                margin:0 auto;
                gap:18px;
                justify-items:center;
            }
            .swai-main-panel {
                width:calc(100% - 8px);
                max-width:none;
                margin:0 auto;
                padding:18px 10px;
                border-radius:22px;
            }
            .swai-mobile-library.swai-library-card,
            .swai-recent-card.swai-library-card {
                padding-left:10px;
                padding-right:10px;
            }
            .swai-library-item {
                width:100%;
                border-radius:16px;
                padding:10px;
            }
            .swai-library-toolbar {
                gap:8px;
            }
            .swai-library-search {
                height:40px;
                padding:0 14px;
                font-size:13px;
            }
            .swai-library-search-btn {
                height:40px;
                padding:0 14px;
                font-size:13px;
            }
            .swai-tabs {
                justify-content:center;
            }
            .swai-right-column { display:none !important; }
            .swai-mobile-library { display:block !important; margin-top:22px; }
            .swai-bottom-center { margin-top:22px; }
            .swai-library-pagination {
                gap:8px;
            }
            .swai-page-btn {
                padding:8px 10px;
                font-size:12px;
            }
            .swai-page-indicator {
                padding:6px 10px;
                font-size:11px;
            }
            .swai-cover-modal {
                align-items:flex-end;
                padding:calc(12px + env(safe-area-inset-top, 0px)) 12px 12px;
            }
            .swai-cover-dialog {
                width:100%;
                max-height:calc(100vh - 24px - env(safe-area-inset-top, 0px));
                border-radius:28px 28px 0 0;
                padding:18px 16px calc(24px + env(safe-area-inset-bottom, 0px));
            }
            .swai-cover-form-grid {
                grid-template-columns:1fr;
            }
            .swai-cover-footer {
                flex-direction:column;
                align-items:stretch;
            }
        }
        @media (max-width: 768px) {
            .swai-top-space { padding-top: 118px; }
            .swai-title { font-size: 11px; line-height: 1.45; }
            .swai-row, .swai-row-3, .swai-package-grid, .swai-stats { grid-template-columns: 1fr; }
            .swai-main-panel { padding: 18px 10px; }
            .navbar-collapse.mobile-open {
                display: block !important;
                clear: both;
                width: 100%;
                background: rgba(8, 13, 19, 0.96);
                margin-top: 12px;
                padding: 14px 12px 16px;
                border-radius: 18px;
            }
            .navbar-collapse.mobile-open .nav {
                float: none !important;
                margin: 0;
            }
            .navbar-collapse.mobile-open .nav > li {
                float: none;
                display: block;
            }
            .navbar-collapse.mobile-open .nav > li > a,
            .navbar-collapse.mobile-open .site-login-pill,
            .navbar-collapse.mobile-open .site-entry-actions a {
                display: block;
                width: 100%;
                margin: 0 0 10px;
            }
            .navbar-collapse.mobile-open .site-user-chip {
                display: flex;
                width: fit-content;
                min-width: 180px;
                margin: 8px auto 0;
                justify-content: center;
            }
            .navbar-collapse.mobile-open .site-entry-actions {
                display: grid;
                gap: 10px;}
    </style>
<style>
.swai-login-note {
    margin-top: 18px;
    padding: 16px 18px;
    border-radius: 18px;
    background: linear-gradient(135deg, rgba(18,26,36,.96), rgba(32,42,56,.94));
    color: #f5efe3;
}
.swai-login-note a {
    color: #f3ca78;
    font-weight: 700;
}
.swai-status.is-error {
    background: #f8e5e1;
    color: #8a3528;
}
.swai-status.is-success {
    background: #e8f5ea;
    color: #21673a;
}
.swai-result-cover {
    width: 100%;
    max-height: 260px;
    object-fit: cover;
    border-radius: 18px;
    margin-top: 12px;
}
.swai-result-links {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 12px;
}
.swai-result-links a,
.swai-result-links button {
    border: 0;
    border-radius: 999px;
    padding: 10px 14px;
    background: #121a24;
    color: #fff;
    font-weight: 700;
}
.song-actions {
    display:flex !important;
    gap:8px !important;
}
</style>
<body class="swai-shell has-song-bottom-player">
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
                            <li class="active"><a href="star-ai.php" class="hvr-ripple-in">STAR.AI</a></li>
                            <li><a href="top_songs.php" class="hvr-ripple-in">STAR TOP音乐榜</a></li>
                            <li><a href="starwaves-mix.php" class="hvr-ripple-in">混音</a></li>
                            <li><a href="starwaves-master.php" class="hvr-ripple-in">母带</a></li>
                        </ul>
                    </nav>
                    <?php if ($isLoggedIn && $currentUser): ?>
                        <?php $navAvatar = !empty($currentUser['avatar_path']) ? 'backend/' . ltrim($currentUser['avatar_path'], '/') : 'images/starwaves-logo.svg'; ?>
                        <a class="site-user-chip" href="backend/admin.php">
                            <img src="<?php echo htmlspecialchars($navAvatar); ?>" alt="avatar">
                            <span><?php echo htmlspecialchars($currentUser['full_name'] ?: $currentUser['username']); ?></span>
                        </a>
                    <?php else: ?>
                        <a class="site-login-pill" href="<?php echo htmlspecialchars($loginTarget); ?>">登录</a>
                    <?php endif; ?>
                </div>
            </nav>
            <div class="clearfix"></div>
        </div>
    </div>

    <div class="container swai-top-space">
        <div class="swai-workbench">
            <section class="swai-main-panel">
                <span class="swai-kicker">STAR.AI Workbench</span>
                <div class="swai-tabs">
                    <button type="button" class="swai-tab active" data-tab="simple">简单创作</button>
                    <button type="button" class="swai-tab" data-tab="pro">精准创作</button>
                </div>

                <?php if (!$isLoggedIn): ?>
                    <div class="swai-login-note">
                        当前可浏览 `STAR.AI` 页面，但提交做歌、充值、下载等实际操作前需要先登录。
                        <a href="<?php echo htmlspecialchars($loginTarget); ?>">立即登录 / 注册</a>
                    </div>
                <?php endif; ?>

                <form id="swaiSimpleForm" class="swai-form swai-tab-panel active" enctype="multipart/form-data">
                    <input type="hidden" name="creation_mode" value="simple">
                    <div>
                        <label>一句话灵感 / Prompt</label>
                        <textarea id="swaiSimplePromptInput" class="swai-auto-grow-lyrics" name="prompt" maxlength="500" placeholder="例如：写一首带希望感的中文流行歌，像凌晨天快亮时的城市街头，副歌大一点。" required></textarea>
                        <div class="swai-hint">按当前使用的官方生成接口文档，简单创作这类非自定义模式 `prompt` 最长 500 字符。</div>
                    </div>
                    <div style="text-align:center;">
                        <button type="submit" class="swai-submit">简单创作：开始生成歌曲（1 积分）</button>
                    </div>
                    <div id="swaiSimpleStatus" class="swai-status"></div>
                </form>

                <form id="swaiProForm" class="swai-form swai-tab-panel" enctype="multipart/form-data" style="display:none;">
                    <input type="hidden" name="creation_mode" value="pro">
                    <div class="swai-row">
                        <div>
                            <label>歌曲标题</label>
                            <input type="text" name="title" placeholder="例如：凌晨以后">
                        </div>
                        <div>
                            <label>风格 / Genre</label>
                            <input type="text" name="genre" id="proGenreInput" placeholder="Pop / Future Bass / 国风流行 / Cinematic Pop">
                            <div class="swai-chip-row helper" data-append-target="proGenreInput">
                                <span class="swai-chip">流行</span>
                                <span class="swai-chip">R&B</span>
                                <span class="swai-chip">国风</span>
                                <span class="swai-chip">电子</span>
                                <span class="swai-chip">抒情</span>
                                <span class="swai-chip">说唱</span>
                                <span class="swai-chip">摇滚</span>
                            </div>
                            <div class="swai-hint">精准创作也支持直接点风格按钮，系统会自动把所选风格追加到输入框。</div>
                        </div>
                    </div>
                    <div class="swai-row-3">
                        <div>
                            <label>歌手性别</label>
                            <select name="voice_gender">
                                <option value="auto">自动匹配</option>
                                <option value="male">男声</option>
                                <option value="female">女声</option>
                            </select>
                        </div>
                        <div>
                            <label>语言</label>
                            <select name="language">
                                <option value="zh">中文</option>
                                <option value="en">English</option>
                                <option value="yue">粤语</option>
                                <option value="auto">自由语言</option>
                            </select>
                        </div>
                        <div>
                            <label>人声模式</label>
                            <select name="mode">
                                <option value="vocal">带人声</option>
                                <option value="instrumental">纯音乐</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label>歌词 / Lyrics</label>
                        <textarea id="swaiLyricsInput" class="swai-auto-grow-lyrics" name="lyrics" placeholder="把歌词直接贴在这里，精准创作时优先参考这里的内容来做歌。"></textarea>
                        <div class="swai-hint">后续还会继续补歌词上传、句段编辑和时间轴联动。</div>
                    </div>
                    <div class="swai-row">
                        <div>
                            <label>参考歌曲</label>
                            <input type="file" name="reference_audio" accept="audio/*">
                        </div>
                        <div>
                            <label>额外说明</label>
                            <input type="text" name="prompt" placeholder="例如：副歌更炸一点，主歌克制，结尾要有尾奏留白。">
                        </div>
                    </div>
                    <div class="swai-slider-wrap">
                        <label for="reference_strength">参考歌曲仿照度：<span class="swai-slider-value" id="referenceStrengthLabel">50%</span></label>
                        <input type="range" id="reference_strength" name="reference_strength" min="0" max="100" step="10" value="50">
                        <div class="swai-hint">`0%` 表示自由发挥，`50%` 表示保留气质参考，`80%` 更接近参考歌方向，`100%` 代表最大参考强度。</div>
                    </div>
                    <div style="text-align:center;">
                        <button type="submit" class="swai-submit">精准创作：提交高级做歌任务（1 积分）</button>
                    </div>
                    <div id="swaiProStatus" class="swai-status"></div>
                </form>

                <div class="swai-mobile-library swai-recent-card swai-library-card">
                    <span class="swai-kicker">作品</span>
                    <h4>我的歌曲</h4>
                    <?php if (!$isLoggedIn): ?>
                        <p class="swai-mini">登录后这里会显示你最近生成和上传的歌曲。</p>
                    <?php else: ?>
                        <form class="swai-library-toolbar" method="get">
                            <input type="hidden" name="tracks_page" value="1">
                            <input class="swai-library-search" type="search" name="track_search" value="<?php echo htmlspecialchars($trackSearch, ENT_QUOTES); ?>" placeholder="搜索歌名 / 风格 / 歌词">
                            <button class="swai-library-search-btn" type="submit">搜索</button>
                        </form>
                        <?php if (empty($recentTracks)): ?>
                            <p class="swai-mini"><?php echo $trackSearch !== '' ? '没搜到相关歌曲，换个关键词试试。' : '你现在还没有歌曲，先生成或上传一首试试。'; ?></p>
                        <?php else: ?>
                            <div class="swai-library-list">
                                <?php foreach ($recentTracks as $track): ?>
                                    <?php
                                        $trackAudio = !empty($track['mastered_preview_path'])
                                            ? resolveStoredAudioPath((string) $track['mastered_preview_path'], 'frontend')
                                            : resolveSongAudioUrl($track, 'frontend');
                                        $trackStyle = inferTrackStyle($track);
                                        $trackDurationLabel = songDurationLabel($track);
                                        $trackLyricsJson = json_encode(parseStarAiLrcFile($track['lrc_path'] ?? null), JSON_UNESCAPED_UNICODE);
                                        $masteringStatus = (string) ($track['mastering_status'] ?? 'none');
                                        $masteringLabel = $masteringStatus === 'completed' ? '已完成母带' : ($masteringStatus === 'processing' ? '自动母带处理中' : ($masteringStatus === 'failed' ? '母带失败' : '未母带'));
                                        $masteringClass = $masteringStatus === 'completed' ? 'is-completed' : ($masteringStatus === 'processing' ? 'is-processing' : ($masteringStatus === 'failed' ? 'is-failed' : ''));
                                    ?>
                                    <div class="swai-library-item" data-song-id="<?php echo (int) $track['id']; ?>" data-track-play data-track-src="<?php echo htmlspecialchars($trackAudio, ENT_QUOTES); ?>" data-track-title="<?php echo htmlspecialchars($track['title'], ENT_QUOTES); ?>" data-track-image="<?php echo htmlspecialchars((string) ($track['image_url'] ?? ''), ENT_QUOTES); ?>" data-track-lyrics="<?php echo htmlspecialchars((string) ($track['lyrics'] ?? ''), ENT_QUOTES); ?>" data-track-lyrics-json="<?php echo htmlspecialchars((string) $trackLyricsJson, ENT_QUOTES); ?>" data-track-duration-label="<?php echo htmlspecialchars($trackDurationLabel, ENT_QUOTES); ?>">
                                        <div class="swai-track-cover">
                                            <?php if (!empty($track['image_url'])): ?>
                                                <img src="<?php echo htmlspecialchars($track['image_url'], ENT_QUOTES); ?>" alt="<?php echo htmlspecialchars($track['title'], ENT_QUOTES); ?>" onerror="this.style.display='none'; this.onerror=null;">
                                            <?php endif; ?>
                                            <div class="swai-track-wave" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                                            <?php if (($track['source'] ?? '') === 'ai'): ?><span class="swai-track-unheard-dot" data-unheard-dot aria-label="未试听新歌"></span><?php endif; ?>
                                            <span class="swai-track-cover-time swai-track-duration" data-track-duration data-track-src="<?php echo htmlspecialchars($trackAudio, ENT_QUOTES); ?>" data-duration-label="<?php echo htmlspecialchars($trackDurationLabel, ENT_QUOTES); ?>"><?php echo htmlspecialchars($trackDurationLabel, ENT_QUOTES); ?></span>
                                        </div>
                                        <div class="swai-track-main">
                                            <div class="swai-track-copy">
                                                <div class="swai-track-title-row">
                                                    <strong><?php echo htmlspecialchars($track['title']); ?></strong>
                                                    <span class="swai-track-tag <?php echo (($track['source'] ?? '') === 'ai') ? 'ai' : ''; ?>"><?php echo (($track['source'] ?? '') === 'ai') ? 'AI' : 'UP'; ?></span>
                                                </div>
                                                <div class="swai-track-style"><?php echo htmlspecialchars($trackStyle); ?></div>
                                            <div class="swai-master-pill <?php echo $masteringClass; ?>" data-master-status="<?php echo htmlspecialchars($masteringStatus, ENT_QUOTES); ?>"><?php echo htmlspecialchars($masteringLabel); ?></div>
                                            </div>
                                            <details class="swai-track-menu-wrap">
                                                <summary class="swai-track-menu-btn">&middot;&middot;&middot;</summary>
                                                <div class="swai-track-menu">
                                                    <button type="button" data-track-action="rename" data-song-id="<?php echo (int) $track['id']; ?>">重命名</button>
                                                    <button type="button" data-track-action="cover" data-song-id="<?php echo (int) $track['id']; ?>">翻唱</button>
                                                    <button type="button" data-track-action="auto-master" data-song-id="<?php echo (int) $track['id']; ?>"><?php echo $masteringStatus === 'completed' ? '重新母带' : '自动母带'; ?></button>
                                                    <button type="button" data-track-action="extend" data-song-id="<?php echo (int) $track['id']; ?>" onclick="window.submitExtend && window.submitExtend('<?php echo (int) $track['id']; ?>', this, event)">延长</button>
                                                    <button type="button" data-track-action="remix" data-song-id="<?php echo (int) $track['id']; ?>">重新混音</button>
                                                    <div class="swai-track-submenu">
                                                        <button type="button" class="swai-submenu-toggle" onclick="if(event){event.preventDefault();event.stopPropagation();} this.classList.toggle('open'); this.nextElementSibling && this.nextElementSibling.classList.toggle('open');">获取分轨</button>
                                                        <div class="swai-submenu-items">
                                                            <button type="button" data-track-action="stems-submit" data-stem-mode="separate_vocal" data-song-id="<?php echo (int) $track['id']; ?>">人声 + 伴奏</button>
                                                            <button type="button" data-track-action="stems-submit" data-stem-mode="split_stem" data-song-id="<?php echo (int) $track['id']; ?>">完整分轨</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </details>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($recentTrackPages > 1): ?>
                                <div class="swai-library-pagination" data-page-jump-wrap data-page-count="<?php echo $recentTrackPages; ?>" data-search="<?php echo htmlspecialchars($trackSearch, ENT_QUOTES); ?>">
                                    <?php $prevPage = max(1, $recentTrackPage - 1); ?>
                                    <?php $nextPage = min($recentTrackPages, $recentTrackPage + 1); ?>
                                    <a class="swai-page-btn <?php echo $recentTrackPage <= 1 ? 'disabled' : ''; ?>" href="?tracks_page=<?php echo $prevPage; ?>&amp;track_search=<?php echo rawurlencode($trackSearch); ?>">上一页</a>
                                    <span class="swai-page-indicator" data-page-jump-trigger role="button" tabindex="0"><span class="swai-page-indicator-text">第 <?php echo $recentTrackPage; ?> / <?php echo $recentTrackPages; ?> 页</span><input class="swai-page-indicator-input" type="number" min="1" max="<?php echo $recentTrackPages; ?>" value="<?php echo $recentTrackPage; ?>" inputmode="numeric" data-page-inline-input></span>
                                    <a class="swai-page-btn <?php echo $recentTrackPage >= $recentTrackPages ? 'disabled' : ''; ?>" href="?tracks_page=<?php echo $nextPage; ?>&amp;track_search=<?php echo rawurlencode($trackSearch); ?>">下一页</a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>

            <aside class="swai-right-column">
                <div class="swai-recent-card swai-library-card">
                    <span class="swai-kicker">作品</span>
                    <h4>我的歌曲</h4>
                    <?php if (!$isLoggedIn): ?>
                        <p class="swai-mini">登录后这里会显示你最近生成和上传的歌曲。</p>
                    <?php else: ?>
                        <form class="swai-library-toolbar" method="get">
                            <input type="hidden" name="tracks_page" value="1">
                            <input class="swai-library-search" type="search" name="track_search" value="<?php echo htmlspecialchars($trackSearch, ENT_QUOTES); ?>" placeholder="搜索歌名 / 风格 / 歌词">
                            <button class="swai-library-search-btn" type="submit">搜索</button>
                        </form>
                        <?php if (empty($recentTracks)): ?>
                            <p class="swai-mini"><?php echo $trackSearch !== '' ? '没搜到相关歌曲，换个关键词试试。' : '你现在还没有歌曲，先生成或上传一首试试。'; ?></p>
                        <?php else: ?>
                        <div class="swai-library-list">
                            <?php foreach ($recentTracks as $track): ?>
                                <?php
                                    $trackAudio = !empty($track['mastered_preview_path'])
                                        ? resolveStoredAudioPath((string) $track['mastered_preview_path'], 'frontend')
                                        : resolveSongAudioUrl($track, 'frontend');
                                    $trackStyle = inferTrackStyle($track);
                                    $trackDurationLabel = songDurationLabel($track);
                                    $trackLyricsJson = json_encode(parseStarAiLrcFile($track['lrc_path'] ?? null), JSON_UNESCAPED_UNICODE);
                                    $masteringStatus = (string) ($track['mastering_status'] ?? 'none');
                                    $masteringLabel = $masteringStatus === 'completed' ? '已完成母带' : ($masteringStatus === 'processing' ? '自动母带处理中' : ($masteringStatus === 'failed' ? '母带失败' : '未母带'));
                                    $masteringClass = $masteringStatus === 'completed' ? 'is-completed' : ($masteringStatus === 'processing' ? 'is-processing' : ($masteringStatus === 'failed' ? 'is-failed' : ''));
                                ?>
                                <div class="swai-library-item" data-song-id="<?php echo (int) $track['id']; ?>" data-track-play data-track-src="<?php echo htmlspecialchars($trackAudio, ENT_QUOTES); ?>" data-track-title="<?php echo htmlspecialchars($track['title'], ENT_QUOTES); ?>" data-track-image="<?php echo htmlspecialchars((string) ($track['image_url'] ?? ''), ENT_QUOTES); ?>" data-track-lyrics="<?php echo htmlspecialchars((string) ($track['lyrics'] ?? ''), ENT_QUOTES); ?>" data-track-lyrics-json="<?php echo htmlspecialchars((string) $trackLyricsJson, ENT_QUOTES); ?>" data-track-duration-label="<?php echo htmlspecialchars($trackDurationLabel, ENT_QUOTES); ?>">
                                    <div class="swai-track-cover">
                                        <?php if (!empty($track['image_url'])): ?>
                                            <img src="<?php echo htmlspecialchars($track['image_url'], ENT_QUOTES); ?>" alt="<?php echo htmlspecialchars($track['title'], ENT_QUOTES); ?>" onerror="this.style.display='none'; this.onerror=null;">
                                        <?php endif; ?>
                                        <div class="swai-track-wave" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                                        <span class="swai-track-cover-time swai-track-duration" data-track-duration data-track-src="<?php echo htmlspecialchars($trackAudio, ENT_QUOTES); ?>" data-duration-label="<?php echo htmlspecialchars($trackDurationLabel, ENT_QUOTES); ?>"><?php echo htmlspecialchars($trackDurationLabel, ENT_QUOTES); ?></span>
                                    </div>
                                    <div class="swai-track-main">
                                        <div class="swai-track-copy">
                                            <div class="swai-track-title-row">
                                                <strong><?php echo htmlspecialchars($track['title']); ?></strong>
                                                <span class="swai-track-tag <?php echo (($track['source'] ?? '') === 'ai') ? 'ai' : ''; ?>"><?php echo (($track['source'] ?? '') === 'ai') ? 'AI' : 'UP'; ?></span>
                                            </div>
                                            <div class="swai-track-style"><?php echo htmlspecialchars($trackStyle); ?></div>
                                        </div>
                                        <details class="swai-track-menu-wrap">
                                            <summary class="swai-track-menu-btn">&middot;&middot;&middot;</summary>
                                            <div class="swai-track-menu">
                                                <button type="button" data-track-action="rename" data-song-id="<?php echo (int) $track['id']; ?>">重命名</button>
                                                <button type="button" data-track-action="cover" data-song-id="<?php echo (int) $track['id']; ?>">翻唱</button>
                                                <button type="button" data-track-action="extend" data-song-id="<?php echo (int) $track['id']; ?>" onclick="window.submitExtend && window.submitExtend('<?php echo (int) $track['id']; ?>', this, event)">延长</button>
                                                <button type="button" data-track-action="remix" data-song-id="<?php echo (int) $track['id']; ?>">重新混音</button>
                                                <div class="swai-track-submenu">
                                                    <button type="button" class="swai-submenu-toggle" onclick="if(event){event.preventDefault();event.stopPropagation();} this.classList.toggle('open'); this.nextElementSibling && this.nextElementSibling.classList.toggle('open');">获取分轨</button>
                                                    <div class="swai-submenu-items">
                                                        <button type="button" data-track-action="stems-submit" data-stem-mode="separate_vocal" data-song-id="<?php echo (int) $track['id']; ?>">人声 + 伴奏</button>
                                                        <button type="button" data-track-action="stems-submit" data-stem-mode="split_stem" data-song-id="<?php echo (int) $track['id']; ?>">完整分轨</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </details>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($recentTrackPages > 1): ?>
                            <div class="swai-library-pagination" data-page-jump-wrap data-page-count="<?php echo $recentTrackPages; ?>" data-search="<?php echo htmlspecialchars($trackSearch, ENT_QUOTES); ?>">
                                <?php $prevPage = max(1, $recentTrackPage - 1); ?>
                                <?php $nextPage = min($recentTrackPages, $recentTrackPage + 1); ?>
                                <a class="swai-page-btn <?php echo $recentTrackPage <= 1 ? 'disabled' : ''; ?>" href="?tracks_page=<?php echo $prevPage; ?>&amp;track_search=<?php echo rawurlencode($trackSearch); ?>">上一页</a>
                                <span class="swai-page-indicator" data-page-jump-trigger role="button" tabindex="0"><span class="swai-page-indicator-text">第 <?php echo $recentTrackPage; ?> / <?php echo $recentTrackPages; ?> 页</span><input class="swai-page-indicator-input" type="number" min="1" max="<?php echo $recentTrackPages; ?>" value="<?php echo $recentTrackPage; ?>" inputmode="numeric" data-page-inline-input></span>
                                <a class="swai-page-btn <?php echo $recentTrackPage >= $recentTrackPages ? 'disabled' : ''; ?>" href="?tracks_page=<?php echo $nextPage; ?>&amp;track_search=<?php echo rawurlencode($trackSearch); ?>">下一页</a>
                            </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </aside>
        </div>

        <div class="swai-bottom-center">
            <div class="swai-side-card">
                <span class="swai-kicker">充值</span>
                <h4>STAR.AI 会员充值</h4>
                <button type="button" class="swai-toggle" id="swaiRechargeToggle">
                    <span>点击查看 5 档固定充值套餐</span>
                    <strong id="swaiRechargeArrow">+</strong>
                </button>
                <div class="swai-collapsible" id="swaiRechargePanel">
                    <div class="swai-package-grid">
                        <?php foreach ($packages as $package): ?>
                            <div class="swai-package-card" data-package-price="<?php echo htmlspecialchars($package['price']); ?>" data-package-credits="<?php echo htmlspecialchars($package['credits']); ?>">
                                <span><?php echo htmlspecialchars($package['label']); ?></span>
                                <strong><?php echo htmlspecialchars($package['price']); ?> 元</strong>
                                <div class="swai-mini"><?php echo htmlspecialchars($package['credits']); ?> 积分</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="swai-payment">
                        <span>微信支付</span>
                        <span>支付宝</span>
                        <span>USDT(TRC20)</span>
                    </div>
                    <div class="swai-custom-topup">
                        <strong>自定义充值金额</strong>
                        <input id="customTopupAmount" type="number" min="1" step="1" placeholder="输入人民币金额，例如 300">
                        <div class="swai-hint">按当前规则换算：1 元 = 2 积分。预计获得 <span id="customTopupCredits">0</span> 积分。</div>
                        <div class="swai-hint">当前 USDT 参考汇率：<span id="usdtRateText">加载中...</span></div>
                    </div>
                    <div class="swai-pay-tabs">
                        <button type="button" class="swai-pay-tab" data-pay-open="wechat">微信支付</button>
                        <button type="button" class="swai-pay-tab" data-pay-open="alipay">支付宝</button>
                        <button type="button" class="swai-pay-tab" data-pay-open="usdt">USDT(TRC20)</button>
                    </div>
                    <p class="swai-mini" style="margin-top:12px;">点击支付方式后弹出对应二维码。固定套餐仍优先展示；如需自定义金额，系统会按当前比例自动换算积分。</p>
                </div>
            </div>
        </div>
    </div>

    <div id="swaiPayModal" class="swai-pay-modal">
        <div class="swai-pay-dialog">
            <button type="button" class="swai-pay-close" data-pay-close>&times;</button>
            <span class="swai-kicker">支付</span>
            <h4 id="swaiPayModalTitle" style="margin: 12px 0 6px; color:#10151c;">微信支付</h4>
            <p id="swaiPayModalHint" class="swai-mini">把收款码放进来后，客户即可直接扫码充值。</p>
            <div id="swaiPayModalSummary" class="swai-pay-summary">当前未选择套餐，默认使用你刚刚点开的金额或自定义金额。</div>
            <div class="swai-pay-qr"><img id="swaiPayModalQr" src="images/payments/wechat.jpg" alt="支付二维码"></div>
            <div style="margin-top:16px; text-align:center;">
                <a id="swaiPayProofLink" class="swai-submit" href="backend/payment_request.php">我已付款，生成唯一充值链</a>
            </div>
        </div>
    </div>

    <div id="swaiCoverModal" class="swai-cover-modal" aria-hidden="true">
        <div class="swai-cover-dialog" role="dialog" aria-modal="true" aria-labelledby="swaiCoverTitle">
            <div class="swai-cover-sheet-bar" aria-hidden="true"></div>
            <button type="button" class="swai-cover-close" data-cover-close>&times;</button>
            <div class="swai-cover-header">
                <div class="swai-cover-title">
                    <span class="swai-kicker">翻唱</span>
                    <h4 id="swaiCoverTitle">开始翻唱</h4>
                    <p>先试听当前歌曲，再填写新的音乐风格和补充说明，然后一键提交翻唱。本次翻唱扣 <?php echo (int) $creditCosts['cover']; ?> 积分。</p>
                </div>
            </div>
            <div class="swai-cover-track">
                <div class="swai-cover-art" id="swaiCoverArt"></div>
                <div class="swai-cover-track-copy">
                    <strong id="swaiCoverTrackTitle">当前歌曲</strong>
                    <span id="swaiCoverTrackMeta">等待选择歌曲</span>
                    <span id="swaiCoverTrackHint">翻唱会基于这首歌当前的可用音频来发起。</span>
                </div>
            </div>
            <div class="swai-cover-wave">
                <div class="swai-cover-wave-meta">
                    <button type="button" id="swaiCoverPreviewBtn" class="swai-cover-wave-btn">▶</button>
                </div>
                <div class="swai-cover-waveform" id="swaiCoverWaveform" aria-hidden="true">
                    <span style="height:24%;"></span><span style="height:42%;"></span><span style="height:58%;"></span><span style="height:35%;"></span><span style="height:68%;"></span><span style="height:48%;"></span><span style="height:72%;"></span><span style="height:34%;"></span><span style="height:56%;"></span><span style="height:76%;"></span><span style="height:30%;"></span><span style="height:62%;"></span><span style="height:40%;"></span><span style="height:74%;"></span><span style="height:50%;"></span><span style="height:66%;"></span><span style="height:36%;"></span><span style="height:60%;"></span><span style="height:28%;"></span><span style="height:70%;"></span>
                </div>
                <div class="swai-cover-wave-progress">
                    <span id="swaiCoverCurrentTime">00:00</span>
                    <span id="swaiCoverDuration">--:--</span>
                </div>
            </div>
            <div class="swai-cover-form-grid">
                <div class="swai-cover-field full">
                    <label for="swaiCoverPrompt">风格 / Style (可写描述)</label>
                    <textarea id="swaiCoverPrompt" class="swai-cover-textarea" placeholder="例如：rock, heavy guitars, fast tempo, 保留原曲旋律和情绪" rows="3"></textarea>
                </div>
                <div class="swai-cover-field full">
                    <label for="swaiCoverLyrics">歌词（可手动修改）</label>
                    <textarea id="swaiCoverLyrics" class="swai-cover-textarea" placeholder="这里会先带出原歌词；你可以直接改，再提交翻唱。" rows="9"></textarea>
                </div>
            </div>
            <div class="swai-cover-footer">
                <div id="swaiCoverStatus" class="swai-cover-status"></div>
                <button type="button" id="swaiCoverSubmit" class="swai-cover-submit">开始翻唱</button>
            </div>
        </div>
    </div>

    <div id="swaiRemixModal" class="swai-cover-modal" aria-hidden="true">
        <div class="swai-cover-dialog swai-remix-dialog" role="dialog" aria-modal="true" aria-labelledby="swaiRemixTitle">
            <div class="swai-cover-sheet-bar" aria-hidden="true"></div>
            <button type="button" class="swai-cover-close" data-remix-close>&times;</button>
            <div class="swai-cover-header">
                <div class="swai-cover-title">
                    <span class="swai-kicker">Boost + Cover</span>
                    <h4 id="swaiRemixTitle">重新混音 · <?php echo (int) $creditCosts['remaster']; ?> 积分</h4>
                    <p>默认会用一套更保守的 remaster 提示词：尽量不改伴奏架构、旋律走向、人声意图和歌曲长度，只提升整体质感、层次、空间感与清晰度。</p>
                </div>
            </div>
            <div class="swai-cover-track">
                <div class="swai-cover-art" id="swaiRemixArt"></div>
                <div class="swai-cover-track-copy">
                    <strong id="swaiRemixTrackTitle">当前歌曲</strong>
                    <span id="swaiRemixTrackMeta">等待选择歌曲</span>
                    <span id="swaiRemixTrackHint">会先做一次风格增强，再基于当前歌曲音频提交更保守的 remaster 型重做。</span>
                </div>
            </div>
            <div class="swai-cover-wave">
                <div class="swai-cover-wave-meta">
                    <button type="button" id="swaiRemixPreviewBtn" class="swai-cover-wave-btn">▶</button>
                </div>
                <div class="swai-cover-waveform" id="swaiRemixWaveform" aria-hidden="true">
                    <span style="height:24%;"></span><span style="height:42%;"></span><span style="height:58%;"></span><span style="height:35%;"></span><span style="height:68%;"></span><span style="height:48%;"></span><span style="height:72%;"></span><span style="height:34%;"></span><span style="height:56%;"></span><span style="height:76%;"></span><span style="height:30%;"></span><span style="height:62%;"></span><span style="height:40%;"></span><span style="height:74%;"></span><span style="height:50%;"></span><span style="height:66%;"></span><span style="height:36%;"></span><span style="height:60%;"></span><span style="height:28%;"></span><span style="height:70%;"></span>
                </div>
                <div class="swai-cover-wave-progress">
                    <span id="swaiRemixCurrentTime">00:00</span>
                    <span id="swaiRemixDuration">--:--</span>
                </div>
            </div>
            <div class="swai-cover-form-grid">
                <div class="swai-cover-field">
                    <label for="swaiRemixModel">Model</label>
                    <input type="hidden" id="swaiRemixModel" value="V4_5">
                    <div class="swai-remix-choice-grid" data-remix-group="model">
                        <button type="button" class="swai-remix-option active" data-remix-value="V4_5">
                            <strong>V4.5</strong>
                            <span>稳一点，当前默认</span>
                        </button>
                        <button type="button" class="swai-remix-option" data-remix-value="V4_5PLUS">
                            <strong>V4.5+</strong>
                            <span>更厚一点</span>
                        </button>
                    </div>
                    <div class="swai-remix-mini-note">Boost Music Style 文档挂在 V4.5 体系下，这里先保留最稳的两档模型。</div>
                </div>
                <div class="swai-cover-field">
                    <label for="swaiRemixVariation">Variation strength</label>
                    <input type="hidden" id="swaiRemixVariation" value="normal">
                    <div class="swai-remix-strength-grid" data-remix-group="variation">
                        <button type="button" class="swai-remix-option" data-remix-value="subtle">
                            <strong>Subtle</strong>
                            <span>尽量贴近原曲</span>
                        </button>
                        <button type="button" class="swai-remix-option active" data-remix-value="normal">
                            <strong>Normal</strong>
                            <span>默认刷新感</span>
                        </button>
                        <button type="button" class="swai-remix-option" data-remix-value="high">
                            <strong>High</strong>
                            <span>变化更明显</span>
                        </button>
                    </div>
                    <div class="swai-remix-mini-note">默认建议先用 `Normal` 测试听感；如果想尽量不动原歌，就优先选 `Subtle`。</div>
                </div>
                <div class="swai-cover-field full">
                    <div class="swai-hint">当前实现走公开文档里的 `Boost Music Style` + `Upload Cover`，并内置保守型默认提示词：尽量保留原曲旋律、结构、伴奏骨架、人声气质和时长，只做质感增强，不主动改编成另一版。</div>
                </div>
            </div>
            <div class="swai-cover-footer">
                <div id="swaiRemixStatus" class="swai-cover-status"></div>
                <button type="button" id="swaiRemixSubmit" class="swai-cover-submit">开始重新混音</button>
            </div>
        </div>
    </div>

    <div id="swaiRenameModal" class="swai-cover-modal" aria-hidden="true">
        <div class="swai-cover-dialog swai-rename-dialog" role="dialog" aria-modal="true" aria-labelledby="swaiRenameTitle">
            <div class="swai-cover-sheet-bar" aria-hidden="true"></div>
            <button type="button" class="swai-cover-close" data-rename-close>&times;</button>
            <div class="swai-cover-header">
                <div class="swai-cover-title">
                    <span class="swai-kicker">重命名</span>
                    <h4 id="swaiRenameTitle">修改歌曲名</h4>
                    <p>换一个更顺口、更好记的名字，保存后会直接更新到这首歌卡片上。</p>
                </div>
            </div>
            <div class="swai-cover-form-grid">
                <div class="swai-cover-field full">
                    <label for="swaiRenameInput">新的歌曲名</label>
                    <input id="swaiRenameInput" class="swai-cover-textarea" type="text" maxlength="120" placeholder="输入新的歌曲名">
                </div>
            </div>
            <div class="swai-cover-footer">
                <div id="swaiRenameStatus" class="swai-cover-status"></div>
                <button type="button" id="swaiRenameSubmit" class="swai-cover-submit">保存新名字</button>
            </div>
        </div>
    </div>

    <script>
// STAR.AI menu/runtime v20260407-0648
    const isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
    const loginTarget = <?php echo json_encode($loginTarget, JSON_UNESCAPED_UNICODE); ?>;

    const navToggle = document.querySelector('.navbar-toggle');
    const navCollapse = document.getElementById('bs-example-navbar-collapse-1');
    if (navToggle && navCollapse) {
        navToggle.addEventListener('click', function () {
            navCollapse.classList.toggle('mobile-open');
            navCollapse.classList.toggle('in');
            navToggle.classList.toggle('collapsed');
            navToggle.setAttribute('aria-expanded', navCollapse.classList.contains('mobile-open') ? 'true' : 'false');
        });
    }

    const tabs = document.querySelectorAll('.swai-tab');
    const panels = document.querySelectorAll('.swai-tab-panel');

    function setActivePanel(panelId) {
        panels.forEach(function (panel) {
            const isActive = panel.id === panelId;
            panel.classList.toggle('active', isActive);
            panel.style.display = isActive ? 'block' : 'none';
        });
    }

    setActivePanel('swaiSimpleForm');

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (item) { item.classList.remove('active'); });
            tab.classList.add('active');
            setActivePanel(tab.dataset.tab === 'simple' ? 'swaiSimpleForm' : 'swaiProForm');
        });
    });

    const rechargeToggle = document.getElementById('swaiRechargeToggle');
    const rechargePanel = document.getElementById('swaiRechargePanel');
    const rechargeArrow = document.getElementById('swaiRechargeArrow');
    rechargeToggle.addEventListener('click', function () {
        rechargePanel.classList.toggle('open');
        rechargeArrow.textContent = rechargePanel.classList.contains('open') ? '-' : '+';
    });

    const referenceStrength = document.getElementById('reference_strength');
    const referenceStrengthLabel = document.getElementById('referenceStrengthLabel');
    referenceStrength.addEventListener('input', function () {
        referenceStrengthLabel.textContent = referenceStrength.value + '%';
    });

    function bindAutoGrowTextarea(textarea) {
        if (!textarea) {
            return;
        }
        function syncHeight() {
            textarea.style.height = '126px';
            const nextHeight = Math.min(textarea.scrollHeight, Math.floor(window.innerHeight * 0.5));
            textarea.style.height = Math.max(126, nextHeight) + 'px';
        }
        ['input', 'focus', 'change'].forEach(function (eventName) {
            textarea.addEventListener(eventName, syncHeight);
        });
        window.addEventListener('resize', syncHeight);
        syncHeight();
    }

    bindAutoGrowTextarea(document.getElementById('swaiLyricsInput'));
    bindAutoGrowTextarea(document.getElementById('swaiSimplePromptInput'));

    const customTopupAmount = document.getElementById('customTopupAmount');
    const customTopupCredits = document.getElementById('customTopupCredits');
    const usdtRateText = document.getElementById('usdtRateText');
    let usdtCnyRate = 7;

    function updateCustomTopupView() {
        if (!customTopupAmount || !customTopupCredits) {
            return;
        }
        const amount = Math.max(0, parseInt(customTopupAmount.value || '0', 10) || 0);
        customTopupCredits.textContent = String(amount * 2);
        if (usdtRateText) {
            const usdtAmount = amount > 0 ? (amount / usdtCnyRate).toFixed(2) : '0.00';
            usdtRateText.textContent = '1 USDT ≈ ' + usdtCnyRate.toFixed(2) + ' RMB，当前约需 ' + usdtAmount + ' USDT';
        }
    }

    if (customTopupAmount && customTopupCredits) {
        customTopupAmount.addEventListener('input', updateCustomTopupView);
    }

    fetch('https://api.coingecko.com/api/v3/simple/price?ids=tether&vs_currencies=cny')
        .then(function (response) { return response.json(); })
        .then(function (data) {
            const rate = Number(data && data.tether && data.tether.cny);
            if (rate > 0) {
                usdtCnyRate = rate;
            }
            updateCustomTopupView();
        })
        .catch(function () {
            updateCustomTopupView();
        });

    updateCustomTopupView();

    const payOpenButtons = document.querySelectorAll('[data-pay-open]');
    const payModal = document.getElementById('swaiPayModal');
    const payModalTitle = document.getElementById('swaiPayModalTitle');
    const payModalQr = document.getElementById('swaiPayModalQr');
    const payModalHint = document.getElementById('swaiPayModalHint');
    const payModalSummary = document.getElementById('swaiPayModalSummary');
    const payProofLink = document.getElementById('swaiPayProofLink');
    const payCloseButtons = document.querySelectorAll('[data-pay-close]');
    const packageCards = document.querySelectorAll('.swai-package-card');
    let selectedPackage = null;
    const payConfigs = {
        wechat: {
            title: '微信支付',
            qr: 'images/payments/wechat.jpg',
            hint: '请使用微信扫码完成充值。'
        },
        alipay: {
            title: '支付宝',
            qr: 'images/payments/alipay.jpg',
            hint: '请使用支付宝扫码完成充值。'
        },
        usdt: {
            title: 'USDT(TRC20)',
            qr: 'images/payments/usdt-trc20.jpg',
            hint: '请使用支持 TRC20 的钱包扫码转账。'
        }
    };
    packageCards.forEach(function (card) {
        card.addEventListener('click', function () {
            packageCards.forEach(function (item) { item.classList.remove('active'); });
            card.classList.add('active');
            selectedPackage = {
                price: card.dataset.packagePrice,
                credits: card.dataset.packageCredits
            };
        });
    });

    payOpenButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            if (!isLoggedIn) {
                window.location.href = loginTarget;
                return;
            }
            const key = button.dataset.payOpen;
            const config = payConfigs[key];
            if (!config) {
                return;
            }
            const customAmount = Math.max(0, parseInt(customTopupAmount.value || '0', 10) || 0);
            const customCredits = customAmount * 2;
            payModalTitle.textContent = config.title;
            payModalQr.src = config.qr;
            payModalHint.textContent = config.hint;
            let amount = 0;
            let credits = 0;
            if (customAmount > 0) {
                amount = customAmount;
                credits = customCredits;
                payModalSummary.textContent = '当前充值金额：' + amount + ' 元，预计到账：' + credits + ' 积分，约合 ' + (amount / usdtCnyRate).toFixed(2) + ' USDT。';
            } else if (selectedPackage) {
                amount = parseInt(selectedPackage.price, 10) || 0;
                credits = parseInt(selectedPackage.credits, 10) || 0;
                payModalSummary.textContent = '当前选择套餐：' + amount + ' 元，预计到账：' + credits + ' 积分，约合 ' + (amount / usdtCnyRate).toFixed(2) + ' USDT。';
            } else {
                payModalSummary.textContent = '你还没选固定套餐，也没填自定义金额；可先返回选择金额后再支付。';
            }
            payProofLink.href = 'backend/payment_request.php?amount=' + amount + '&credits=' + credits + '&method=' + key;
            payModal.classList.add('open');
        });
    });
    payCloseButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            payModal.classList.remove('open');
        });
    });
    payModal.addEventListener('click', function (event) {
        if (event.target === payModal) {
            payModal.classList.remove('open');
        }
    });


    document.querySelectorAll('[data-append-target]').forEach(function (group) {
        const target = document.getElementById(group.dataset.appendTarget);
        group.querySelectorAll('.swai-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                const value = chip.textContent.trim();
                const current = target.value.split('/').map(function (item) {
                    return item.trim();
                }).filter(Boolean);
                if (!current.includes(value)) {
                    current.push(value);
                    target.value = current.join(' / ');
                }
                chip.classList.add('active');
            });
        });
    });

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>\"']/g, function (char) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char];
        });
    }

    function setStatusState(status, message, type) {
        status.style.display = 'block';
        status.classList.remove('is-error', 'is-success');
        if (type) {
            status.classList.add(type);
        }
        status.textContent = message;
    }

    function bindVisibilityButtons(scope, taskId) {
        scope.querySelectorAll('.swai-visibility').forEach(function (button) {
            button.addEventListener('click', async function () {
                const idx = button.dataset.index;
                try {
                    const resp = await fetch('backend/track_visibility.php?track_index=' + encodeURIComponent(idx) + '&task_id=' + encodeURIComponent(taskId));
                    const data = await resp.json();
                    if (data.ok) {
                        button.textContent = data.visibility === 'public' ? '设为公开' : '设为私密';
                    }
                } catch (error) {
                    console.error('切换可见性失败', error);
                }
            });
        });
    }

    function renderTrackResult(track, index, options) {
        const config = options || {};
        const title = escapeHtml(track.title || ('候选歌曲 ' + (index + 1)));
        const tags = track.tags ? '<div class="swai-hint">风格标签：' + escapeHtml(track.tags) + '</div>' : '';
        const audioUrl = escapeHtml(track.audio_url || track.stream_audio_url || '');
        const imageUrl = track.image_url ? '<img class="swai-result-cover" src="' + escapeHtml(track.image_url) + '" alt="' + title + '" onerror="this.style.display=\'none\';this.onerror=null;">' : '';
        const playButton = audioUrl
            ? '<button type="button" class="swai-track-link" data-track-play data-track-src="' + audioUrl + '" data-track-title="' + title + '">试听</button>'
            : '';
        const downloadLink = audioUrl ? '<a href="' + audioUrl + '" target="_blank" rel="noopener">下载音频</a>' : '';
        const visibilityButton = config.hideVisibility
            ? ''
            : '<button type="button" class="swai-visibility" data-index="' + index + '">切换公开状态</button>';
        return '<div class="swai-result-item">'
            + '<strong>' + title + '</strong>'
            + tags
            + imageUrl
            + '<div class="swai-result-links">'
            + playButton
            + downloadLink
            + visibilityButton
            + '</div>'
            + '</div>';
    }

    function renderCoverResultCard(tracks) {
        const list = Array.isArray(tracks) ? tracks : (tracks ? [tracks] : []);
        return '<div class="swai-cover-result-card">'
            + '<div class="swai-hint" style="margin-bottom:12px;">翻唱已生成成功，下面是刚出来的两首结果卡；同时也已经自动插入到下方歌曲列表。</div>'
            + list.map(function (track, index) {
                return renderTrackResult(track, index, { hideVisibility: true });
            }).join('')
            + '</div>';
    }

    function buildGeneratedLibraryItem(track, index) {
        const title = escapeHtml(track.title || ('候选歌曲 ' + (index + 1)));
        const audioUrl = escapeHtml(track.audio_url || track.stream_audio_url || '');
        const imageMarkup = track.image_url
            ? '<img src="' + escapeHtml(track.image_url) + '" alt="' + title + '">'
            : '';
        const styleText = escapeHtml(track.tags || 'STAR.AI 新生成作品');
        const trackImage = escapeHtml(track.image_url || '');
        return '<div class="swai-library-item" data-track-play data-track-src="' + audioUrl + '" data-track-title="' + title + '" data-track-image="' + trackImage + '" data-track-new="1">'
            + '<div class="swai-track-cover">'
            + imageMarkup
            + '<div class="swai-track-wave" aria-hidden="true"><span></span><span></span><span></span><span></span></div>'
            + '<span class="swai-track-cover-time swai-track-duration" data-track-duration data-track-src="' + audioUrl + '">--:--</span>'
            + '</div>'
            + '<div class="swai-track-main">'
            + '<div class="swai-track-copy">'
            + '<div class="swai-track-title-row"><strong>' + title + '</strong><span class="swai-track-unheard-dot" data-unheard-dot aria-label="未试听新歌"></span><span class="swai-track-tag ai">AI</span></div>'
            + '<div class="swai-track-style">' + styleText + '</div>'
            + '</div>'
            + '</div>'
            + '</div>';
    }

    function buildPendingCoverItem(title, index) {
        const pendingTitle = escapeHtml(title || '翻唱生成中');
        return '<div class="swai-library-item is-cover-pending" data-cover-pending-item="1" data-cover-slot="' + index + '">'
            + '<div class="swai-track-cover">'
            + '<div class="swai-track-wave" aria-hidden="true"><span></span><span></span><span></span><span></span></div>'
            + '<span class="swai-track-cover-time">生成中</span>'
            + '</div>'
            + '<div class="swai-track-main">'
            + '<div class="swai-track-copy">'
            + '<div class="swai-track-title-row"><strong>' + pendingTitle + '</strong><span class="swai-track-pending-badge">生成中</span></div>'
            + '<div class="swai-track-style">STAR.AI引擎正在运行...</div>'
            + '</div>'
            + '</div>'
            + '</div>';
    }

    function renderPendingCoverItems(title) {
        return buildPendingCoverItem(title, 0) + buildPendingCoverItem(title, 1);
    }

    function injectPendingCoverItems(title) {
        document.querySelectorAll('.swai-library-list').forEach(function (list) {
            if (list.querySelector('[data-cover-pending-item="1"]')) {
                return;
            }
            list.insertAdjacentHTML('afterbegin', renderPendingCoverItems(title));
        });
    }

    function clearPendingCoverItems() {
        document.querySelectorAll('[data-cover-pending-item="1"]').forEach(function (node) {
            node.remove();
        });
    }

    function getVisualTextWeight(text) {
        return Array.from(text || '').reduce(function (total, char) {
            // CJK/full-width chars visually occupy more space than plain ASCII.
            return total + (/[^\u0000-\u00ff]/.test(char) ? 1.9 : 1);
        }, 0);
    }

    function applyTrackTextCompaction(scope) {
        const root = scope && typeof scope.querySelectorAll === 'function' ? scope : document;
        root.querySelectorAll('.swai-library-item strong').forEach(function (node) {
            const text = (node.textContent || '').trim();
            const weight = getVisualTextWeight(text);
            node.classList.remove('is-long-title', 'is-very-long-title');
            if (weight >= 22) {
                node.classList.add('is-very-long-title');
            } else if (weight >= 14) {
                node.classList.add('is-long-title');
            }
        });
        root.querySelectorAll('.swai-track-style').forEach(function (node) {
            const text = (node.textContent || '').trim();
            const weight = getVisualTextWeight(text);
            node.classList.remove('is-long-style', 'is-very-long-style');
            if (weight >= 80) {
                node.classList.add('is-very-long-style');
            } else if (weight >= 36) {
                node.classList.add('is-long-style');
            }
        });
    }

    function prependGeneratedTracksToLibrary(tracks) {
        document.querySelectorAll('.swai-library-list').forEach(function (list) {
            const pendingItems = Array.from(list.querySelectorAll('[data-cover-pending-item="1"]'));
            tracks.forEach(function (track, index) {
                const audioUrl = track.audio_url || track.stream_audio_url || '';
                if (!audioUrl) {
                    return;
                }
                const slotIndex = Number.isInteger(track.slot_index) ? track.slot_index : index;
                const html = buildGeneratedLibraryItem(track, slotIndex);
                if (pendingItems[slotIndex]) {
                    pendingItems[slotIndex].outerHTML = html;
                } else {
                    list.insertAdjacentHTML('afterbegin', html);
                }

                const duplicates = Array.from(list.querySelectorAll('[data-track-src]')).filter(function (node) {
                    return (node.getAttribute('data-track-src') || '') === audioUrl;
                });
                duplicates.slice(1).forEach(function (node) {
                    node.remove();
                });
            });
            applyTrackTextCompaction(list);
        });
        hydrateTrackDurations();
        syncTrackWaveState();
        syncUnheardTrackDots();
    }

    async function submitStarAiForm(formId, statusId) {
        const form = document.getElementById(formId);
        const status = document.getElementById(statusId);
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!isLoggedIn) {
                window.location.href = loginTarget;
                return;
            }

            setStatusState(status, '正在提交 STAR.AI 任务...', '');
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;

            const formData = new FormData(form);
            let poll = null;
            let pollTimes = 0;
            const maxPollTimes = 60;

            try {
                const response = await fetch('backend/star_ai_generate.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (!response.ok || !result.ok) {
                    if (result.login_required && result.login_url) {
                        window.location.href = result.login_url;
                        return;
                    }
                    throw new Error(result.error || '提交失败');
                }
                setStatusState(status, 'STAR.AI 正在生成歌曲，通常需要几十秒，请稍候...', '');
                if (result.task_id) {
                    poll = setInterval(async function () {
                        pollTimes += 1;
                        if (pollTimes > maxPollTimes) {
                            clearInterval(poll);
                            if (submitBtn) submitBtn.disabled = false;
                            setStatusState(status, '任务仍在处理中，请稍后刷新页面或重新查询任务状态。', '');
                            return;
                        }
                        try {
                            const pollResp = await fetch('backend/star_ai_status.php?task_id=' + encodeURIComponent(result.task_id));
                            const pollResult = await pollResp.json();
                            if (!pollResp.ok || !pollResult.ok) {
                                if (pollResult.login_required && pollResult.login_url) {
                                    window.location.href = pollResult.login_url;
                                    return;
                                }
                                return;
                            }
                            if (pollResult.status === 'completed') {
                                clearInterval(poll);
                                if (submitBtn) submitBtn.disabled = false;
                                const tracks = Array.isArray(pollResult.tracks) ? pollResult.tracks : [];
                                if (!tracks.length && pollResult.result) {
                                    tracks.push(pollResult.result);
                                }
                                if (!tracks.length) {
                                    setStatusState(status, '任务已完成，但暂时没有拿到歌曲结果。', 'is-error');
                                    return;
                                }
                                status.classList.remove('is-error');
                                status.classList.add('is-success');
                                status.innerHTML = '<div class="swai-hint" style="margin-bottom:12px;">已生成完成，结果图区保留封面；播放统一走下方歌曲栏和底部播放器。</div>'
                                    + tracks.map(renderTrackResult).join('');
                                prependGeneratedTracksToLibrary(tracks);
                                bindVisibilityButtons(status, result.task_id);
                            }
                        } catch (error) {
                            console.error('轮询任务失败', error);
                        }
                    }, 3000);
                }
            } catch (error) {
                if (poll) {
                    clearInterval(poll);
                }
                setStatusState(status, '提交失败：' + error.message, 'is-error');
                if (submitBtn) submitBtn.disabled = false;
            }
        });
    }

    submitStarAiForm('swaiSimpleForm', 'swaiSimpleStatus');
    submitStarAiForm('swaiProForm', 'swaiProStatus');
    </script>

<div class="swai-right-sidebar"></div>
<script>
function closeTrackMenus() {
  document.querySelectorAll('.swai-track-menu-wrap[open]').forEach(function (menuWrap) {
    menuWrap.removeAttribute('open');
  });
  document.querySelectorAll('.swai-submenu-toggle.open').forEach(function (toggle) {
    toggle.classList.remove('open');
  });
  document.querySelectorAll('.swai-submenu-items.open').forEach(function (panel) {
    panel.classList.remove('open');
  });
}

function hideStarAiPlayer() {
  if (window.StarwavesGlobalPlayer && typeof window.StarwavesGlobalPlayer.hide === 'function') {
    window.StarwavesGlobalPlayer.hide();
  }
}

document.querySelectorAll('.swai-track-menu-wrap').forEach(function (menuWrap) {
  menuWrap.addEventListener('toggle', function () {
    if (!menuWrap.hasAttribute('open')) {
      return;
    }
    document.querySelectorAll('.swai-track-menu-wrap[open]').forEach(function (otherMenuWrap) {
      if (otherMenuWrap !== menuWrap) {
        otherMenuWrap.removeAttribute('open');
      }
    });
    document.querySelectorAll('.swai-submenu-toggle.open').forEach(function (toggle) {
      const owner = toggle.closest('.swai-track-menu-wrap');
      if (owner !== menuWrap) {
        toggle.classList.remove('open');
      }
    });
    document.querySelectorAll('.swai-submenu-items.open').forEach(function (panel) {
      const owner = panel.closest('.swai-track-menu-wrap');
      if (owner !== menuWrap) {
        panel.classList.remove('open');
      }
    });
  });
});

document.addEventListener('click', function (event) {
  if (event.target.closest('.swai-track-menu-wrap')) {
    return;
  }
  closeTrackMenus();
});

document.addEventListener('keydown', function (event) {
  if (event.key === 'Escape') {
    closeTrackMenus();
  }
});

async function postJson(url, payload) {
  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });
  const result = await response.json();
  if (!response.ok || !result.ok) {
    throw new Error(result.error || '请求失败');
  }
  return result;
}

// Open cover modal on 翻唱 button click
function updateMasteringUi(item, status, message, job) {
  if (!item) return;
  const pill = item.querySelector('[data-master-status]');
  if (pill) {
    pill.setAttribute('data-master-status', status || 'none');
    pill.classList.remove('is-processing', 'is-completed', 'is-failed');
    pill.textContent = status === 'completed' ? '已完成母带' : (status === 'processing' || status === 'queued' ? '自动母带处理中' : (status === 'failed' || status === 'processing_failed' ? '母带失败' : '未母带'));
    if (status === 'completed') pill.classList.add('is-completed');
    else if (status === 'processing' || status === 'queued') pill.classList.add('is-processing');
    else if (status === 'failed' || status === 'processing_failed') pill.classList.add('is-failed');
  }
  const menuBtn = item.querySelector('[data-track-action="auto-master"]');
  if (menuBtn) {
    menuBtn.disabled = status === 'processing' || status === 'queued';
    menuBtn.textContent = status === 'completed' ? '重新母带' : ((status === 'processing' || status === 'queued') ? '母带处理中...' : '自动母带');
  }
  if (message) {
    console.log('Auto master:', message, job || '');
  }
}

document.querySelectorAll('[data-track-action="auto-master"]').forEach(function (button) {
  button.addEventListener('click', function (event) {
    event.preventDefault();
    event.stopPropagation();
    const item = button.closest('.swai-library-item');
    if (!item) return;
    const songId = button.getAttribute('data-song-id');
    if (!songId) return;
    updateMasteringUi(item, 'queued', '正在提交自动母带任务');
    postJson('backend/master.php?action=create', { song_id: Number(songId), mastering_type: 'software' })
      .then(function (result) {
        const status = (result.job && result.job.status) || 'queued';
        updateMasteringUi(item, status === 'completed' ? 'completed' : (status === 'processing_failed' ? 'failed' : 'processing'), result.job && result.job.notes, result.job);
        if (result.job && result.job.preview_file) {
          item.setAttribute('data-track-src', result.job.preview_file);
          const durationNode = item.querySelector('[data-track-duration]');
          if (durationNode) {
            durationNode.setAttribute('data-track-src', result.job.preview_file);
            durationNode.textContent = '--:--';
          }
        }
      })
      .catch(function (error) {
        updateMasteringUi(item, 'failed', error.message || '自动母带提交失败');
        alert(error.message || '自动母带提交失败');
      });
  });
});

document.querySelectorAll('[data-track-action="cover"]').forEach(function (button) {
  button.addEventListener('click', function (event) {
    event.preventDefault();
    event.stopPropagation();
    hideStarAiPlayer();
    const item = button.closest('.swai-library-item');
    if (!item) return;
    const songId = button.getAttribute('data-song-id');
    const title = item.getAttribute('data-track-title') || '';
    const coverImg = item.querySelector('.swai-track-cover img');
    const imgUrl = item.getAttribute('data-track-image') || (coverImg ? coverImg.getAttribute('src') : '') || '';
    const trackSrc = item.getAttribute('data-track-src') || '';
    const trackLyrics = item.getAttribute('data-track-lyrics') || '';
    const lyricsField = document.getElementById('swaiCoverLyrics');
    // Populate modal
    document.getElementById('swaiCoverTrackTitle').textContent = title;
    lyricsField.value = trackLyrics;
    const artDiv = document.getElementById('swaiCoverArt');
    artDiv.innerHTML = '';
    if (imgUrl) {
      const img = document.createElement('img');
      img.src = imgUrl;
      img.alt = title;
      artDiv.appendChild(img);
    } else {
      artDiv.textContent = '暂无封面';
    }
    const modal = document.getElementById('swaiCoverModal');
    modal.dataset.songId = songId;
    modal.dataset.trackSrc = trackSrc;
    modal.dataset.trackTitle = title;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('swai-cover-open');
    // Reset preview state when opening a new track
    coverPreviewAudio.pause();
    coverPreviewAudio.currentTime = 0;
    if (trackSrc) {
      coverPreviewAudio.src = trackSrc;
    }
    coverPreviewBtn.textContent = '▶';
    syncCoverWave();
    // Reset status
    document.getElementById('swaiCoverStatus').textContent = '';

    if (songId) {
      fetch(`backend/song_detail.php?song_id=${encodeURIComponent(songId)}`)
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (!data || !data.ok || !data.song) {
            return;
          }
          if (String(modal.dataset.songId || '') !== String(songId)) {
            return;
          }
          lyricsField.value = data.song.lyrics || '';
        })
        .catch(function (error) {
          console.error('Load full cover lyrics failed', error);
        });
    }
  });
});

// Close cover modal
document.querySelectorAll('[data-cover-close]').forEach(function (el) {
  el.addEventListener('click', function () {
    closeCoverModal();
  });
});

// Chip group toggle for cover modal
document.querySelectorAll('.swai-cover-chip-group').forEach(function (group) {
  group.addEventListener('click', function (e) {
    const chip = e.target.closest('.swai-cover-chip');
    if (!chip) return;
    group.querySelectorAll('.swai-cover-chip').forEach(function (c) { c.classList.remove('active'); });
    chip.classList.add('active');
  });
});

// Add swipe-down to close cover modal (mobile)
(function(){
  const modal = document.getElementById('swaiCoverModal');
  let startY = 0;
  let moving = false;
  modal.addEventListener('touchstart', function(e){
    // Only start swipe when touching the top sheet bar
    const targetBar = e.target.closest('.swai-cover-sheet-bar');
    if (!targetBar) return;
    if(e.touches.length===1){
      startY = e.touches[0].clientY;
      moving = true;
    }
  });
  modal.addEventListener('touchmove', function(e){
    if(!moving) return;
    const dy = e.touches[0].clientY - startY;
    if(dy > 0){
      const dialog = modal.querySelector('.swai-cover-dialog');
      if(dialog) dialog.style.transform = `translateY(${dy}px)`;
    }
  });
  modal.addEventListener('touchend', function(e){
    if(!moving) return;
    const dy = e.changedTouches[0].clientY - startY;
    const dialog = modal.querySelector('.swai-cover-dialog');
    if(dy > 80){
      modal.classList.remove('open');
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('swai-cover-open');
      coverPreviewAudio.pause();
      coverPreviewBtn.textContent = '▶';
      coverPreviewAudio.currentTime = 0;
      syncCoverWave();
    }
    if(dialog) dialog.style.transform = '';
    moving = false;
  });
})();

const coverPreviewAudio = new Audio();
coverPreviewAudio.preload = 'metadata';
const coverPreviewBtn = document.getElementById('swaiCoverPreviewBtn');
const coverCurrentTime = document.getElementById('swaiCoverCurrentTime');
const coverDuration = document.getElementById('swaiCoverDuration');
const coverWaveSpans = Array.from(document.querySelectorAll('#swaiCoverWaveform span'));
const remixPreviewAudio = new Audio();
remixPreviewAudio.preload = 'metadata';
const remixPreviewBtn = document.getElementById('swaiRemixPreviewBtn');
const remixCurrentTime = document.getElementById('swaiRemixCurrentTime');
const remixDuration = document.getElementById('swaiRemixDuration');
const remixWaveSpans = Array.from(document.querySelectorAll('#swaiRemixWaveform span'));
const coverPageQuery = new URLSearchParams(window.location.search);

function closeCoverModal() {
  const modal = document.getElementById('swaiCoverModal');
  modal.classList.remove('open');
  modal.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('swai-cover-open');
  coverPreviewAudio.pause();
  coverPreviewBtn.textContent = '▶';
  coverPreviewAudio.currentTime = 0;
  syncCoverWave();
}

function closeRemixModal() {
  const modal = document.getElementById('swaiRemixModal');
  modal.classList.remove('open');
  modal.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('swai-cover-open');
  remixPreviewAudio.pause();
  remixPreviewBtn.textContent = '▶';
  remixPreviewAudio.currentTime = 0;
  syncRemixWave();
}

function goToFirstTrackPage(taskType, taskId, pending, taskTitle) {
  const nextUrl = new URL(window.location.href);
  nextUrl.searchParams.set('tracks_page', '1');
  const taskIdKey = taskType + '_task_id';
  const pendingKey = taskType + '_pending';
  const titleKey = taskType + '_title';
  if (taskId) {
    nextUrl.searchParams.set(taskIdKey, taskId);
  } else {
    nextUrl.searchParams.delete(taskIdKey);
  }
  if (pending) {
    nextUrl.searchParams.set(pendingKey, '1');
  } else {
    nextUrl.searchParams.delete(pendingKey);
  }
  if (taskTitle) {
    nextUrl.searchParams.set(titleKey, taskTitle);
  } else {
    nextUrl.searchParams.delete(titleKey);
  }
  window.location.href = nextUrl.toString();
}

function bootCoverResultPolling() {
  const coverTaskId = (coverPageQuery.get('cover_task_id') || '').trim();
  const coverPending = coverPageQuery.get('cover_pending') === '1';
  const coverTitle = (coverPageQuery.get('cover_title') || '').trim();
  if (!coverTaskId || !coverPending) {
    return;
  }

  let pendingInjected = false;
  let pollCount = 0;
  const pollIntervalMs = 3000;
  const maxPollCount = 120;

  async function checkCoverStatus() {
    const resp = await fetch(`backend/cover_status.php?cover_task_id=${encodeURIComponent(coverTaskId)}&_=${Date.now()}`, {
      cache: 'no-store'
    });
    return resp.json();
  }

  function stopPendingPolling() {
    if (poll) {
      window.clearInterval(poll);
    }
  }

  function handleResolvedState(data) {
    stopPendingPolling();
    // Clean URL params so that on next load we don't think a pending task is still active
    const cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete('cover_pending');
    // keep cover_task_id for navigation if needed, but it's not required after success
    // we replace state to avoid another reload before goToFirstTrackPage
    window.history.replaceState(null, '', cleanUrl.toString());
    if (Array.isArray(data.tracks) && data.tracks.length) {
      prependGeneratedTracksToLibrary(data.tracks);
    }
    clearPendingCoverItems();
    goToFirstTrackPage('cover', coverTaskId, false, '');
  }

  function ensurePendingState() {
    if (!pendingInjected) {
      injectPendingCoverItems(coverTitle || '翻唱生成中');
      pendingInjected = true;
    }
  }

  const poll = window.setInterval(async function () {
    pollCount += 1;
    try {
      const data = await checkCoverStatus();
      if (!data || !data.ok) {
        ensurePendingState();
        if (pollCount >= maxPollCount) {
          stopPendingPolling();
          clearPendingCoverItems();
        }
        return;
      }
      if (Array.isArray(data.tracks) && data.tracks.length) {
        ensurePendingState();
        prependGeneratedTracksToLibrary(data.tracks);
      }
      if (data.status === 'success') {
        handleResolvedState(data);
        return;
      }
      if (data.status === 'failed') {
        stopPendingPolling();
        clearPendingCoverItems();
        goToFirstTrackPage('cover', coverTaskId, false, '');
        return;
      }
      ensurePendingState();
      if (pollCount >= maxPollCount) {
        stopPendingPolling();
        clearPendingCoverItems();
        return;
      }
    } catch (error) {
      console.error('Cover page poll error', error);
      ensurePendingState();
      if (pollCount >= maxPollCount) {
        stopPendingPolling();
        clearPendingCoverItems();
      }
    }
  }, pollIntervalMs);

  checkCoverStatus().then(function (data) {
    if (!data || !data.ok) {
      ensurePendingState();
      return;
    }
    if (Array.isArray(data.tracks) && data.tracks.length) {
      ensurePendingState();
      prependGeneratedTracksToLibrary(data.tracks);
    }
    if (data.status === 'success') {
      handleResolvedState(data);
      return;
    }
    if (data.status === 'failed') {
      stopPendingPolling();
      goToFirstTrackPage('cover', coverTaskId, false, '');
      return;
    }
    ensurePendingState();
  }).catch(function (error) {
    console.error('Initial cover status check failed', error);
    ensurePendingState();
  });
}

bootCoverResultPolling();

function bootRemixResultPolling() {
  const remixTaskId = (coverPageQuery.get('remix_task_id') || '').trim();
  const remixPending = coverPageQuery.get('remix_pending') === '1';
  const remixTitle = (coverPageQuery.get('remix_title') || '').trim();
  if (!remixTaskId || !remixPending) {
    return;
  }

  let pendingInjected = false;
  let pollCount = 0;
  const pollIntervalMs = 3000;
  const maxPollCount = 120;

  async function checkRemixStatus() {
    const resp = await fetch(`backend/remix_status.php?remix_task_id=${encodeURIComponent(remixTaskId)}&_=${Date.now()}`, {
      cache: 'no-store'
    });
    return resp.json();
  }

  function stopPendingPolling() {
    if (poll) {
      window.clearInterval(poll);
    }
  }

  function handleResolvedState(data) {
    stopPendingPolling();
    const cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete('remix_pending');
    window.history.replaceState(null, '', cleanUrl.toString());
    if (Array.isArray(data.tracks) && data.tracks.length) {
      prependGeneratedTracksToLibrary(data.tracks);
    }
    clearPendingCoverItems();
    goToFirstTrackPage('remix', remixTaskId, false, '');
  }

  function ensurePendingState() {
    if (!pendingInjected) {
      injectPendingCoverItems(remixTitle || '重新混音生成中');
      pendingInjected = true;
    }
  }

  const poll = window.setInterval(async function () {
    pollCount += 1;
    try {
      const data = await checkRemixStatus();
      if (!data || !data.ok) {
        ensurePendingState();
        if (pollCount >= maxPollCount) {
          stopPendingPolling();
          clearPendingCoverItems();
        }
        return;
      }
      if (Array.isArray(data.tracks) && data.tracks.length) {
        ensurePendingState();
        prependGeneratedTracksToLibrary(data.tracks);
      }
      if (data.status === 'success') {
        handleResolvedState(data);
        return;
      }
      if (data.status === 'failed') {
        stopPendingPolling();
        clearPendingCoverItems();
        goToFirstTrackPage('remix', remixTaskId, false, '');
        return;
      }
      ensurePendingState();
      if (pollCount >= maxPollCount) {
        stopPendingPolling();
        clearPendingCoverItems();
      }
    } catch (error) {
      console.error('Remix page poll error', error);
      ensurePendingState();
      if (pollCount >= maxPollCount) {
        stopPendingPolling();
        clearPendingCoverItems();
      }
    }
  }, pollIntervalMs);

  checkRemixStatus().then(function (data) {
    if (!data || !data.ok) {
      ensurePendingState();
      return;
    }
    if (Array.isArray(data.tracks) && data.tracks.length) {
      ensurePendingState();
      prependGeneratedTracksToLibrary(data.tracks);
    }
    if (data.status === 'success') {
      handleResolvedState(data);
      return;
    }
    if (data.status === 'failed') {
      stopPendingPolling();
      goToFirstTrackPage('remix', remixTaskId, false, '');
      return;
    }
    ensurePendingState();
  }).catch(function (error) {
    console.error('Initial remix status check failed', error);
    ensurePendingState();
  });
}

bootRemixResultPolling();

function bootExtendResultPolling() {
  const extendTaskId = (coverPageQuery.get('extend_task_id') || '').trim();
  const extendPending = coverPageQuery.get('extend_pending') === '1';
  const extendTitle = (coverPageQuery.get('extend_title') || '').trim();
  if (!extendTaskId || !extendPending) {
    return;
  }

  let pendingInjected = false;
  let pollCount = 0;
  const pollIntervalMs = 3000;
  const maxPollCount = 120;

  async function checkExtendStatus() {
    const resp = await fetch(`backend/extend_status.php?extend_task_id=${encodeURIComponent(extendTaskId)}&_=${Date.now()}`, {
      cache: 'no-store'
    });
    return resp.json();
  }

  function stopPendingPolling() {
    if (poll) {
      window.clearInterval(poll);
    }
  }

  function handleResolvedState(data) {
    stopPendingPolling();
    const cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete('extend_pending');
    window.history.replaceState(null, '', cleanUrl.toString());
    if (Array.isArray(data.tracks) && data.tracks.length) {
      prependGeneratedTracksToLibrary(data.tracks);
    }
    clearPendingCoverItems();
    goToFirstTrackPage('extend', extendTaskId, false, '');
  }

  function ensurePendingState() {
    if (!pendingInjected) {
      injectPendingCoverItems(extendTitle || '延长生成中');
      pendingInjected = true;
    }
  }

  const poll = window.setInterval(async function () {
    pollCount += 1;
    try {
      const data = await checkExtendStatus();
      if (!data || !data.ok) {
        ensurePendingState();
        if (pollCount >= maxPollCount) {
          stopPendingPolling();
          clearPendingCoverItems();
        }
        return;
      }
      if (Array.isArray(data.tracks) && data.tracks.length) {
        ensurePendingState();
        prependGeneratedTracksToLibrary(data.tracks);
      }
      if (data.status === 'success') {
        handleResolvedState(data);
        return;
      }
      if (data.status === 'failed') {
        stopPendingPolling();
        clearPendingCoverItems();
        goToFirstTrackPage('extend', extendTaskId, false, '');
        return;
      }
      ensurePendingState();
      if (pollCount >= maxPollCount) {
        stopPendingPolling();
        clearPendingCoverItems();
        return;
      }
    } catch (error) {
      console.error('Extend page poll error', error);
      ensurePendingState();
      if (pollCount >= maxPollCount) {
        stopPendingPolling();
        clearPendingCoverItems();
      }
    }
  }, pollIntervalMs);

  // Initial immediate check
  checkExtendStatus().then(function (data) {
    if (!data || !data.ok) {
      ensurePendingState();
      return;
    }
    if (Array.isArray(data.tracks) && data.tracks.length) {
      ensurePendingState();
      prependGeneratedTracksToLibrary(data.tracks);
    }
    if (data.status === 'success') {
      handleResolvedState(data);
      return;
    }
    if (data.status === 'failed') {
      stopPendingPolling();
      goToFirstTrackPage('extend', extendTaskId, false, '');
      return;
    }
    ensurePendingState();
  }).catch(function (error) {
    console.error('Initial extend status check failed', error);
    ensurePendingState();
  });
}

bootExtendResultPolling();

function formatCoverTime(seconds) {
  if (!isFinite(seconds) || seconds < 0) return '00:00';
  const total = Math.floor(seconds);
  const minutes = Math.floor(total / 60);
  const secs = total % 60;
  return String(minutes).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
}

function syncCoverWave() {
  const duration = coverPreviewAudio.duration || 0;
  const current = coverPreviewAudio.currentTime || 0;
  const ratio = duration > 0 ? current / duration : 0;
  const activeCount = Math.round(ratio * coverWaveSpans.length);
  coverCurrentTime.textContent = formatCoverTime(current);
  coverDuration.textContent = duration > 0 ? formatCoverTime(duration) : '--:--';
  coverWaveSpans.forEach(function (span, index) {
    span.style.opacity = index < activeCount ? '1' : '0.35';
    span.style.filter = index < activeCount ? 'brightness(1.1)' : 'none';
  });
}

function syncRemixWave() {
  const duration = remixPreviewAudio.duration || 0;
  const current = remixPreviewAudio.currentTime || 0;
  const ratio = duration > 0 ? current / duration : 0;
  const activeCount = Math.round(ratio * remixWaveSpans.length);
  remixCurrentTime.textContent = formatCoverTime(current);
  remixDuration.textContent = duration > 0 ? formatCoverTime(duration) : '--:--';
  remixWaveSpans.forEach(function (span, index) {
    span.style.opacity = index < activeCount ? '1' : '0.35';
    span.style.filter = index < activeCount ? 'brightness(1.1)' : 'none';
  });
}

coverPreviewAudio.addEventListener('loadedmetadata', syncCoverWave);
coverPreviewAudio.addEventListener('timeupdate', syncCoverWave);
coverPreviewAudio.addEventListener('ended', function () {
  coverPreviewBtn.textContent = '▶';
  syncCoverWave();
});
remixPreviewAudio.addEventListener('loadedmetadata', syncRemixWave);
remixPreviewAudio.addEventListener('timeupdate', syncRemixWave);
remixPreviewAudio.addEventListener('ended', function () {
  remixPreviewBtn.textContent = '▶';
  syncRemixWave();
});

coverPreviewBtn.addEventListener('click', async function () {
  const modal = document.getElementById('swaiCoverModal');
  const src = modal.dataset.trackSrc || '';
  if (!src) {
    return;
  }
  const absoluteSrc = new URL(src, window.location.href).href;
  if (coverPreviewAudio.src !== absoluteSrc) {
    coverPreviewAudio.src = src;
  }
  if (coverPreviewAudio.paused) {
    try {
      await coverPreviewAudio.play();
      coverPreviewBtn.textContent = '❚❚';
      syncCoverWave();
    } catch (error) {
      console.error('翻唱试听播放失败', error);
    }
  } else {
    coverPreviewAudio.pause();
    coverPreviewBtn.textContent = '▶';
  }
});

remixPreviewBtn.addEventListener('click', async function () {
  const modal = document.getElementById('swaiRemixModal');
  const src = modal.dataset.trackSrc || '';
  if (!src) {
    return;
  }
  const absoluteSrc = new URL(src, window.location.href).href;
  if (remixPreviewAudio.src !== absoluteSrc) {
    remixPreviewAudio.src = src;
  }
  if (remixPreviewAudio.paused) {
    try {
      await remixPreviewAudio.play();
      remixPreviewBtn.textContent = '❚❚';
      syncRemixWave();
    } catch (error) {
      console.error('重新混音试听播放失败', error);
    }
  } else {
    remixPreviewAudio.pause();
    remixPreviewBtn.textContent = '▶';
  }
});

// Submit cover request from modal
document.getElementById('swaiCoverSubmit').addEventListener('click', async function () {
  const modal = document.getElementById('swaiCoverModal');
  const songId = modal.dataset.songId;
  if (!songId) {
    alert('未选择歌曲');
    return;
  }
  const prompt = document.getElementById('swaiCoverPrompt').value.trim();
  const lyricsOverride = document.getElementById('swaiCoverLyrics').value.trim();
  if (!prompt) {
    alert('请先填写风格或描述');
    return;
  }
  const submitBtn = document.getElementById('swaiCoverSubmit');
  const originalSubmitLabel = submitBtn.textContent;
  const payload = {
    song_id: songId,
    prompt: prompt,
    lyrics_override: lyricsOverride
  };
  const statusEl = document.getElementById('swaiCoverStatus');
  submitBtn.disabled = true;
  submitBtn.textContent = '提交中...';
  statusEl.textContent = '提交中...';
  try {
    const result = await postJson('backend/cover.php', payload);
    statusEl.classList.remove('is-error');
    statusEl.classList.add('is-success');
    statusEl.textContent = '提交成功，正在跳到第一页查看生成进度...';
    coverPreviewAudio.pause();
    coverPreviewBtn.textContent = '▶';
    coverPreviewAudio.currentTime = 0;
    syncCoverWave();
    const coverTaskId = result.cover_task_id || '';
    const coverTitle = modal.dataset.trackTitle || document.getElementById('swaiCoverTrackTitle').textContent.trim() || '翻唱生成中';
    submitBtn.disabled = false;
    submitBtn.textContent = originalSubmitLabel;
    closeTrackMenus();
    window.setTimeout(function () {
      closeCoverModal();
      goToFirstTrackPage('cover', coverTaskId, true, coverTitle);
    }, 220);
    return;
  } catch (e) {
    statusEl.textContent = '提交失败: ' + e.message;
    alert('翻唱提交失败：' + e.message);
  }
  submitBtn.disabled = false;
  submitBtn.textContent = originalSubmitLabel;
});

function closeRenameModal() {
  const modal = document.getElementById('swaiRenameModal');
  if (!modal) return;
  modal.classList.remove('open');
  modal.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('swai-cover-open');
}

document.querySelectorAll('[data-rename-close]').forEach(function (el) {
  el.addEventListener('click', function () {
    closeRenameModal();
  });
});

document.querySelectorAll('[data-track-action="rename"]').forEach(function (button) {
  button.addEventListener('click', function (event) {
    event.preventDefault();
    event.stopPropagation();
    const item = button.closest('.swai-library-item');
    if (!item) return;
    const songId = button.getAttribute('data-song-id');
    const titleEl = item.querySelector('.swai-track-title-row strong');
    const currentTitle = (titleEl ? titleEl.textContent : '').trim();
    const modal = document.getElementById('swaiRenameModal');
    const input = document.getElementById('swaiRenameInput');
    const statusEl = document.getElementById('swaiRenameStatus');
    const submitBtn = document.getElementById('swaiRenameSubmit');
    if (!modal || !input || !statusEl || !submitBtn) return;
    modal.dataset.songId = songId;
    modal.dataset.currentTitle = currentTitle;
    modal.dataset.itemSelector = item.getAttribute('data-song-id') || '';
    input.value = currentTitle;
    statusEl.textContent = '';
    submitBtn.disabled = false;
    submitBtn.textContent = '保存新名字';
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('swai-cover-open');
    window.setTimeout(function () { input.focus(); input.select(); }, 60);
    closeTrackMenus();
  });
});

document.getElementById('swaiRenameSubmit').addEventListener('click', async function () {
  const modal = document.getElementById('swaiRenameModal');
  const input = document.getElementById('swaiRenameInput');
  const statusEl = document.getElementById('swaiRenameStatus');
  const submitBtn = document.getElementById('swaiRenameSubmit');
  const songId = modal ? modal.dataset.songId : '';
  const cleanTitle = (input ? input.value : '').trim();
  if (!songId || !cleanTitle) {
    statusEl.textContent = '歌名不能为空';
    statusEl.classList.add('is-error');
    return;
  }
  statusEl.classList.remove('is-error', 'is-success');
  statusEl.textContent = '保存中...';
  submitBtn.disabled = true;
  try {
    const result = await postJson('backend/rename_song.php', { song_id: songId, title: cleanTitle });
    document.querySelectorAll('.swai-library-item[data-song-id="' + songId + '"]').forEach(function (item) {
      const titleEl = item.querySelector('.swai-track-title-row strong');
      if (titleEl) {
        titleEl.textContent = result.title || cleanTitle;
      }
      item.setAttribute('data-track-title', result.title || cleanTitle);
    });
    statusEl.classList.add('is-success');
    statusEl.textContent = '已保存';
    window.setTimeout(function () {
      closeRenameModal();
    }, 260);
  } catch (error) {
    statusEl.classList.add('is-error');
    statusEl.textContent = '重命名失败：' + error.message;
  } finally {
    submitBtn.disabled = false;
    submitBtn.textContent = '保存新名字';
  }
});

document.querySelectorAll('[data-track-action="extend"]').forEach(function (button) {
  button.addEventListener('click', async function (event) {
    event.preventDefault();
    event.stopPropagation();
    hideStarAiPlayer();
    const songId = button.getAttribute('data-song-id');
    const original = button.textContent;
    button.disabled = true;
    button.textContent = '提交中...';
    try {
      const result = await postJson('backend/extend.php', { song_id: songId });
      // After submission, redirect to first page with polling parameters
      const taskId = result.extend_task_id || '';
      if (taskId) {
        goToFirstTrackPage('extend', taskId, true, '延长生成中');
      } else {
        alert('延长任务已提交，但未返回任务号');
      }
    } catch (error) {
      alert('延长提交失败：' + error.message);
    } finally {
      button.disabled = false;
      button.textContent = original;
      closeTrackMenus();
    }
  });
});

document.querySelectorAll('[data-track-action="remix"]').forEach(function (button) {
  button.addEventListener('click', function (event) {
    event.preventDefault();
    event.stopPropagation();
    hideStarAiPlayer();
    const item = button.closest('.swai-library-item');
    if (!item) return;
    const songId = button.getAttribute('data-song-id');
    const title = item.getAttribute('data-track-title') || '';
    const coverImg = item.querySelector('.swai-track-cover img');
    const imgUrl = item.getAttribute('data-track-image') || (coverImg ? coverImg.getAttribute('src') : '') || '';
    const trackSrc = item.getAttribute('data-track-src') || '';
    document.getElementById('swaiRemixTrackTitle').textContent = title;
    document.getElementById('swaiRemixTrackMeta').textContent = '先增强风格，再基于当前音频重做一版';
    const artDiv = document.getElementById('swaiRemixArt');
    artDiv.innerHTML = '';
    if (imgUrl) {
      const img = document.createElement('img');
      img.src = imgUrl;
      img.alt = title;
      artDiv.appendChild(img);
    } else {
      artDiv.textContent = '暂无封面';
    }
    const modal = document.getElementById('swaiRemixModal');
    modal.dataset.songId = songId;
    modal.dataset.trackSrc = trackSrc;
    modal.dataset.trackTitle = title;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('swai-cover-open');
    remixPreviewAudio.pause();
    remixPreviewAudio.currentTime = 0;
    if (trackSrc) {
      remixPreviewAudio.src = trackSrc;
    }
    remixPreviewBtn.textContent = '▶';
    syncRemixWave();
    document.getElementById('swaiRemixStatus').textContent = '';
    closeTrackMenus();
  });
});

document.querySelectorAll('[data-remix-close]').forEach(function (el) {
  el.addEventListener('click', function () {
    closeRemixModal();
  });
});

(function(){
  const modal = document.getElementById('swaiRemixModal');
  let startY = 0;
  let moving = false;
  modal.addEventListener('touchstart', function(e){
    const targetBar = e.target.closest('.swai-cover-sheet-bar');
    if (!targetBar) return;
    if(e.touches.length===1){
      startY = e.touches[0].clientY;
      moving = true;
    }
  });
  modal.addEventListener('touchmove', function(e){
    if(!moving) return;
    const dy = e.touches[0].clientY - startY;
    if(dy > 0){
      const dialog = modal.querySelector('.swai-cover-dialog');
      if(dialog) dialog.style.transform = `translateY(${dy}px)`;
    }
  });
  modal.addEventListener('touchend', function(e){
    if(!moving) return;
    const dy = e.changedTouches[0].clientY - startY;
    const dialog = modal.querySelector('.swai-cover-dialog');
    if(dy > 80){
      closeRemixModal();
    }
    if(dialog) dialog.style.transform = '';
    moving = false;
  });
})();

document.querySelectorAll('[data-remix-group]').forEach(function (group) {
  group.addEventListener('click', function (event) {
    const option = event.target.closest('.swai-remix-option');
    if (!option) {
      return;
    }
    const hiddenId = group.getAttribute('data-remix-group') === 'model' ? 'swaiRemixModel' : 'swaiRemixVariation';
    group.querySelectorAll('.swai-remix-option').forEach(function (item) {
      item.classList.remove('active');
    });
    option.classList.add('active');
    document.getElementById(hiddenId).value = option.getAttribute('data-remix-value') || '';
  });
});

document.getElementById('swaiRemixSubmit').addEventListener('click', async function () {
  const modal = document.getElementById('swaiRemixModal');
  const songId = modal.dataset.songId;
  if (!songId) {
    alert('未选择歌曲');
    return;
  }
  const submitBtn = document.getElementById('swaiRemixSubmit');
  const originalLabel = submitBtn.textContent;
  const statusEl = document.getElementById('swaiRemixStatus');
  const payload = {
    song_id: songId,
    model: document.getElementById('swaiRemixModel').value,
    variation_strength: document.getElementById('swaiRemixVariation').value
  };
  submitBtn.disabled = true;
  submitBtn.textContent = '提交中...';
  statusEl.classList.remove('is-error');
  statusEl.classList.remove('is-success');
  statusEl.textContent = '正在按保守 remaster 提示词先做风格增强，再提交重新混音...';
  try {
    const result = await postJson('backend/remix.php', payload);
    statusEl.classList.remove('is-error');
    statusEl.classList.add('is-success');
    statusEl.textContent = '提交成功，正在跳到第一页查看生成进度...';
    remixPreviewAudio.pause();
    remixPreviewBtn.textContent = '▶';
    remixPreviewAudio.currentTime = 0;
    syncRemixWave();
    const remixTaskId = result.remix_task_id || '';
    const remixTitle = modal.dataset.trackTitle || document.getElementById('swaiRemixTrackTitle').textContent.trim() || '重新混音生成中';
    submitBtn.disabled = false;
    submitBtn.textContent = originalLabel;
    closeTrackMenus();
    window.setTimeout(function () {
      closeRemixModal();
      goToFirstTrackPage('remix', remixTaskId, true, remixTitle);
    }, 220);
    return;
  } catch (error) {
    statusEl.classList.remove('is-success');
    statusEl.classList.add('is-error');
    statusEl.textContent = '提交失败: ' + error.message;
    alert('重新混音提交失败：' + error.message);
  }
  submitBtn.disabled = false;
  submitBtn.textContent = originalLabel;
});

document.querySelectorAll('[data-track-action="stems-submit"]').forEach(function (button) {
  button.addEventListener('click', async function (event) {
    event.preventDefault();
    event.stopPropagation();
    hideStarAiPlayer();
    const songId = button.getAttribute('data-song-id');
    const mode = button.getAttribute('data-stem-mode') || 'separate_vocal';
    const original = button.textContent;
    button.disabled = true;
    button.textContent = '提交中...';
    try {
      const result = await postJson('backend/get_stems.php', { song_id: songId, mode: mode });
      alert((mode === 'split_stem' ? '完整分轨' : '人声+伴奏分轨') + '任务已提交，任务号：' + (result.stem_task_id || '已提交'));
    } catch (error) {
      alert('获取分轨提交失败：' + error.message);
    } finally {
      button.disabled = false;
      button.textContent = original;
      closeTrackMenus();
    }
  });
});
</script>
<div id="globalBottomPlayer" class="song-bottom-player">
    <div class="song-bottom-player__inner">
        <div class="song-bottom-player__meta">
            <div id="globalBottomTitle" class="song-bottom-player__title">请选择歌曲</div>
            <div id="globalBottomTime" class="song-bottom-player__time">00:00 / 00:00</div>
        </div>
        <audio id="globalBottomAudio" controls preload="none" class="song-bottom-player__audio"></audio>
    </div>
</div>
<script src="<?php echo htmlspecialchars(siteAssetUrl('js/global-player.js')); ?>"></script>
<script>
if (window.StarwavesGlobalPlayer && typeof window.StarwavesGlobalPlayer.hide === 'function') {
    window.StarwavesGlobalPlayer.hide();
}
(function () {
    function formatTime(seconds) {
        if (!isFinite(seconds) || seconds <= 0) {
            return '--:--';
        }
        var total = Math.round(seconds);
        var minutes = Math.floor(total / 60);
        var secs = total % 60;
        return String(minutes).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
    }

    function hydrateTrackDurations() {
        var nodes = document.querySelectorAll('[data-track-duration]');
        var cache = {};
        nodes.forEach(function (node) {
            var presetLabel = (node.getAttribute('data-duration-label') || '').trim();
            if (presetLabel && presetLabel !== '--:--' && presetLabel !== '生成中') {
                node.textContent = presetLabel;
                return;
            }
            var src = node.getAttribute('data-track-src') || '';
            if (!src) {
                return;
            }
            if (cache[src]) {
                cache[src].then(function (label) {
                    node.textContent = label;
                });
                return;
            }
            cache[src] = new Promise(function (resolve) {
                var audio = new Audio();
                audio.preload = 'metadata';
                audio.src = src;
                audio.addEventListener('loadedmetadata', function () {
                    resolve(formatTime(audio.duration));
                }, { once: true });
                audio.addEventListener('error', function () {
                    resolve('--:--');
                }, { once: true });
            });
            cache[src].then(function (label) {
                node.textContent = label;
            });
        });
    }

    function normalizeTrackSrc(src) {
        if (!src) {
            return '';
        }
        var clean = src.split('#')[0];
        try {
            var parsed = new URL(clean, window.location.href);
            parsed.searchParams.delete('_swts');
            clean = parsed.pathname + (parsed.search || '');
        } catch (error) {
            // Keep the original value if URL parsing fails.
        }
        return clean.replace(/\\/g, '/');
    }

    function buildPlayableTrackSrc(src) {
        if (!src || src.indexOf('backend/netdisk_stream.php?') === -1) {
            return src || '';
        }
        try {
            var parsed = new URL(src, window.location.href);
            parsed.searchParams.set('_swts', String(Date.now()));
            return parsed.pathname + parsed.search;
        } catch (error) {
            var joiner = src.indexOf('?') === -1 ? '?' : '&';
            return src + joiner + '_swts=' + Date.now();
        }
    }

    var heardTrackStorageKey = 'swai-heard-tracks';

    function getHeardTrackMap() {
        try {
            return JSON.parse(localStorage.getItem(heardTrackStorageKey) || '{}') || {};
        } catch (error) {
            return {};
        }
    }

    function markTrackAsHeard(src) {
        var normalized = normalizeTrackSrc(src || '');
        if (!normalized) {
            return;
        }
        var heardMap = getHeardTrackMap();
        heardMap[normalized] = true;
        try {
            localStorage.setItem(heardTrackStorageKey, JSON.stringify(heardMap));
        } catch (error) {
            // Ignore storage failures and still update current UI.
        }
        document.querySelectorAll('.swai-library-item[data-track-src]').forEach(function (item) {
            var itemSrc = normalizeTrackSrc(item.getAttribute('data-track-src') || '');
            if (itemSrc === normalized) {
                item.removeAttribute('data-track-new');
                var dot = item.querySelector('[data-unheard-dot]');
                if (dot) {
                    dot.remove();
                }
            }
        });
    }

    function syncUnheardTrackDots() {
        var heardMap = getHeardTrackMap();
        document.querySelectorAll('.swai-library-item[data-track-src]').forEach(function (item) {
            var itemSrc = normalizeTrackSrc(item.getAttribute('data-track-src') || '');
            var dot = item.querySelector('[data-unheard-dot]');
            if (itemSrc && heardMap[itemSrc]) {
                item.removeAttribute('data-track-new');
                if (dot) {
                    dot.remove();
                }
                return;
            }
            if (item.getAttribute('data-track-new') === '1' && !dot) {
                var titleRow = item.querySelector('.swai-track-title-row');
                var tag = titleRow ? titleRow.querySelector('.swai-track-tag') : null;
                if (titleRow) {
                    var dotNode = document.createElement('span');
                    dotNode.className = 'swai-track-unheard-dot';
                    dotNode.setAttribute('data-unheard-dot', '');
                    dotNode.setAttribute('aria-label', '未试听新歌');
                    if (tag) {
                        titleRow.insertBefore(dotNode, tag);
                    } else {
                        titleRow.appendChild(dotNode);
                    }
                }
            }
        });
    }

    function getActivePlayerAudio() {
        return document.getElementById('starwavesGlobalPlayerAudio') || document.getElementById('globalBottomAudio') || null;
    }

    function isSameTrackSrc(left, right) {
        if (!left || !right) {
            return false;
        }
        return left === right || left.slice(-right.length) === right || right.slice(-left.length) === left;
    }

    function setPlayingTrackBySrc(src, forcePlaying) {
        var normalizedTarget = normalizeTrackSrc(src || '');
        document.querySelectorAll('.swai-library-item[data-track-src]').forEach(function (item) {
            var itemSrc = normalizeTrackSrc(item.getAttribute('data-track-src') || '');
            item.classList.toggle('is-playing', !!forcePlaying && isSameTrackSrc(normalizedTarget, itemSrc));
        });
    }

    function syncTrackWaveState() {
        var playerAudio = getActivePlayerAudio();
        var currentSrc = playerAudio ? (playerAudio.currentSrc || playerAudio.src || '') : '';
        setPlayingTrackBySrc(currentSrc, !!playerAudio && !playerAudio.paused);
    }

    document.addEventListener('click', function (event) {
        var menuAction = event.target.closest('.swai-track-menu-wrap, .swai-track-menu button, .swai-submenu-toggle, .swai-submenu-items button');
        if (menuAction) {
            return;
        }
        var playBtn = event.target.closest('[data-track-play]');
        if (!playBtn || !window.StarwavesGlobalPlayer || typeof window.StarwavesGlobalPlayer.playTrack !== 'function') {
            return;
        }
        event.preventDefault();
        var targetSrc = playBtn.getAttribute('data-track-src') || '';
        var playableSrc = buildPlayableTrackSrc(targetSrc);
        var lyricsJson = playBtn.getAttribute('data-track-lyrics-json') || '[]';
        var lyrics = [];
        try {
            lyrics = JSON.parse(lyricsJson);
        } catch (error) {
            lyrics = [];
        }
        setPlayingTrackBySrc(targetSrc, true);
        window.StarwavesGlobalPlayer.playTrack({
            src: playableSrc,
            title: playBtn.getAttribute('data-track-title') || '当前歌曲',
            durationLabel: playBtn.getAttribute('data-track-duration-label') || '--:--',
            lyrics: Array.isArray(lyrics) ? lyrics : []
        });
        markTrackAsHeard(targetSrc);
        setTimeout(syncTrackWaveState, 80);
        setTimeout(syncTrackWaveState, 240);
    });

    hydrateTrackDurations();
    syncTrackWaveState();
    syncUnheardTrackDots();
    applyTrackTextCompaction(document);

    document.querySelectorAll('[data-page-jump-wrap]').forEach(function (wrap) {
        var trigger = wrap.querySelector('[data-page-jump-trigger]');
        var input = wrap.querySelector('[data-page-inline-input]');
        var nextButton = wrap.querySelector('.swai-page-btn:last-child');
        var indicatorText = wrap.querySelector('.swai-page-indicator-text');
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
            nextUrl.searchParams.set('tracks_page', String(targetPage));
            var searchText = wrap.getAttribute('data-search') || '';
            if (searchText) {
                nextUrl.searchParams.set('track_search', searchText);
            } else {
                nextUrl.searchParams.delete('track_search');
            }
            return nextUrl.toString();
        }

        function syncJumpPreview() {
            var targetPage = normalizeTargetPage(input.value);
            if (!targetPage) {
                return;
            }
            if (indicatorText) {
                var pageCount = parseInt(wrap.getAttribute('data-page-count') || '1', 10) || 1;
                indicatorText.textContent = '第 ' + targetPage + ' / ' + pageCount + ' 页';
            }
            if (nextButton && !nextButton.classList.contains('disabled')) {
                nextButton.setAttribute('href', buildJumpUrl(targetPage));
            }
        }

        function commitPageJump() {
            var targetPage = normalizeTargetPage(input.value);
            if (!targetPage) {
                trigger.classList.remove('is-editing');
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
        trigger.addEventListener('touchend', startEdit, { passive: false });
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
            }
        });
        input.addEventListener('blur', function () {
            commitPageJump();
        });
    });

    var bottomAudio = getActivePlayerAudio();
    if (bottomAudio) {
        ['play', 'pause', 'ended', 'loadedmetadata', 'emptied', 'seeked'].forEach(function (eventName) {
            bottomAudio.addEventListener(eventName, syncTrackWaveState);
        });
    }

    document.addEventListener('starwaves:player-state', function (event) {
        var detail = event && event.detail ? event.detail : {};
        setPlayingTrackBySrc(detail.src || '', !!detail.playing);
        if (detail.playing && detail.src) {
            markTrackAsHeard(detail.src);
        }
    });

    document.addEventListener('click', function () {
        setTimeout(syncTrackWaveState, 60);
    });
})();
</script>

<style>
    .xingzai-float {
        position: fixed;
        right: 18px;
        bottom: 98px;
        z-index: 1200;
        touch-action: none;
        user-select: none;
        -webkit-user-select: none;
    }
    .xingzai-trigger {
        width: 68px;
        height: 68px;
        border: 0;
        border-radius: 50%;
        background: #f5d9a0;
        box-shadow: 0 18px 40px rgba(0, 0, 0, .22);
        padding: 0;
        overflow: hidden;
    }
    .xingzai-trigger img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .xingzai-panel {
        position: absolute;
        right: 0;
        bottom: 84px;
        width: min(360px, calc(100vw - 28px));
        background: rgba(16, 21, 28, .98);
        color: #fff;
        border-radius: 24px;
        overflow: hidden;
        display: none;
        box-shadow: 0 24px 60px rgba(0, 0, 0, .34);
    }
    .xingzai-panel.open { display: block; }
    .xingzai-head {
        padding: 16px 18px;
        background: linear-gradient(135deg, #f2c46b, #f59f53);
        color: #16110a;
        font-weight: 800;
    }
    .xingzai-head small { display: block; margin-top: 4px; font-weight: 600; opacity: .8; }
    .xingzai-messages {
        max-height: 320px;
        overflow-y: auto;
        padding: 14px;
        background: #111821;
    }
    .xingzai-msg {
        position: relative;
        padding: 12px 14px;
        border-radius: 18px;
        margin-bottom: 10px;
        line-height: 1.75;
        white-space: pre-wrap;
    }
    .xingzai-msg.bot { background: #1b2430; }
    .xingzai-msg.user { background: #f2c46b; color: #17120b; }
    .xingzai-lyric-box {
        position: relative;
        margin-top: 12px;
        padding: 14px 14px 42px;
        border-radius: 16px;
        background: rgba(8, 12, 18, 0.55);
        border: 1px solid rgba(255,255,255,.06);
        white-space: pre-wrap;
        line-height: 1.8;
    }
    .xingzai-copy-btn {
        position: absolute;
        right: 10px;
        bottom: 8px;
        width: 34px;
        height: 34px;
        border: 0;
        border-radius: 999px;
        background: rgba(255,255,255,.08);
        color: #ffd28a;
        font-size: 16px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .xingzai-copy-btn.is-done {
        color: #7df2b1;
    }
    .xingzai-suggestions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        padding: 0 14px 12px;
        background: #111821;
    }
    .xingzai-suggestions button {
        border: 0;
        border-radius: 999px;
        padding: 8px 12px;
        background: #243142;
        color: #fff;
        font-size: 12px;
    }
    .xingzai-form {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 10px;
        padding: 14px;
        background: #0d131a;
    }
    .xingzai-form textarea {
        border: 1px solid rgba(255,255,255,.08);
        border-radius: 16px;
        background: #131c25;
        color: #fff;
        padding: 12px 14px;
        min-height: 74px;
        resize: none;
        width: 100%;
    }
    .xingzai-form button {
        border: 0;
        border-radius: 16px;
        min-width: 74px;
        background: linear-gradient(135deg, #f2c46b, #f59f53);
        color: #17120b;
        font-weight: 800;
    }
    @media (max-width: 768px) {
        .xingzai-float { right: 12px; bottom: 88px; }
        .xingzai-trigger { width: 60px; height: 60px; font-size: 26px; }
        .xingzai-panel { width: min(340px, calc(100vw - 20px)); bottom: 74px; }
    }
</style>

<div class="xingzai-float" id="xingzaiFloat">
    <div class="xingzai-panel" id="xingzaiPanel">
        <div class="xingzai-head">星仔机器人<small><?php echo $isLoggedIn ? '可以回答站内音乐问题，也能直接帮你作词' : '未登录时仅引导注册或登录'; ?></small></div>
        <div class="xingzai-messages" id="xingzaiMessages">
            <div class="xingzai-msg bot"><?php echo $isLoggedIn ? '你好，我是星仔。你可以直接问我：STAR.AI 怎么用、混音和母带有什么区别，或者说“帮我写一段副歌歌词”。' : '请先注册或登录星浪账号，登录后我再继续回答你的问题。'; ?></div>
        </div>
        <div class="xingzai-suggestions" id="xingzaiSuggestions">
            <?php if ($isLoggedIn): ?>
            <button type="button">STAR.AI 怎么用</button>
            <button type="button">混音和母带区别</button>
            <button type="button">帮我写一段副歌歌词</button>
            <?php else: ?>
            <button type="button">立即登录 / 注册</button>
            <?php endif; ?>
        </div>
        <form class="xingzai-form" id="xingzaiForm">
            <textarea id="xingzaiInput" placeholder="例如：帮我写一段伤感流行歌副歌，或者网站怎么上传歌曲"></textarea>
            <button type="submit">发送</button>
        </form>
    </div>
    <button type="button" class="xingzai-trigger" id="xingzaiTrigger" aria-label="打开星仔机器人"><img src="images/xingzai-avatar.jpg" alt="星仔"></button>
</div>

<script>
(function () {
    var trigger = document.getElementById('xingzaiTrigger');
    var panel = document.getElementById('xingzaiPanel');
    var form = document.getElementById('xingzaiForm');
    var input = document.getElementById('xingzaiInput');
    var messages = document.getElementById('xingzaiMessages');
    var suggestions = document.getElementById('xingzaiSuggestions');

    function splitLyricMessage(text) {
        var raw = (text || '').trim();
        var markerIndex = raw.search(/\[(主歌|副歌|verse|chorus)\]/i);
        if (markerIndex === -1) {
            return { intro: raw, lyrics: '', outro: '' };
        }
        var intro = raw.slice(0, markerIndex).trim();
        var lyricPart = raw.slice(markerIndex).trim();
        var outro = '';
        var tailIndex = lyricPart.indexOf('\n\n如果你愿意');
        if (tailIndex !== -1) {
            outro = lyricPart.slice(tailIndex).trim();
            lyricPart = lyricPart.slice(0, tailIndex).trim();
        }
        return { intro: intro, lyrics: lyricPart, outro: outro };
    }

    function fallbackCopyText(text) {
        var helper = document.createElement('textarea');
        helper.value = text;
        helper.setAttribute('readonly', 'readonly');
        helper.style.position = 'fixed';
        helper.style.opacity = '0';
        helper.style.pointerEvents = 'none';
        helper.style.left = '-9999px';
        helper.style.top = '0';
        document.body.appendChild(helper);
        helper.focus();
        helper.select();
        var copied = false;
        try {
            copied = document.execCommand('copy');
        } catch (error) {
            copied = false;
        }
        document.body.removeChild(helper);
        return copied;
    }

    function bindCopyButton(button, copiedText) {
        button.addEventListener('click', function () {
            var markDone = function () {
                button.textContent = '✓';
                button.classList.add('is-done');
                window.setTimeout(function () {
                    button.textContent = '⧉';
                    button.classList.remove('is-done');
                }, 1200);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(copiedText).then(markDone).catch(function () {
                    if (fallbackCopyText(copiedText)) {
                        markDone();
                    }
                });
            } else if (fallbackCopyText(copiedText)) {
                markDone();
            }
        });
    }

    function appendMessage(role, text) {
        var item = document.createElement('div');
        item.className = 'xingzai-msg ' + role;
        if (role === 'bot') {
            var parts = splitLyricMessage(text);
            if (parts.intro) {
                var intro = document.createElement('div');
                intro.textContent = parts.intro;
                item.appendChild(intro);
            }
            if (parts.lyrics) {
                var lyricBox = document.createElement('div');
                lyricBox.className = 'xingzai-lyric-box';
                lyricBox.appendChild(document.createTextNode(parts.lyrics));
                var copyBtn = document.createElement('button');
                copyBtn.type = 'button';
                copyBtn.className = 'xingzai-copy-btn';
                copyBtn.textContent = '⧉';
                bindCopyButton(copyBtn, parts.lyrics);
                lyricBox.appendChild(copyBtn);
                item.appendChild(lyricBox);
            }
            if (parts.outro) {
                var outro = document.createElement('div');
                outro.style.marginTop = '12px';
                outro.textContent = parts.outro;
                item.appendChild(outro);
            }
            if (!parts.intro && !parts.lyrics && !parts.outro) {
                item.textContent = text;
            }
        } else {
            item.textContent = text;
        }
        messages.appendChild(item);
        messages.scrollTop = messages.scrollHeight;
    }

    function renderSuggestions(items, loginUrl) {
        suggestions.innerHTML = '';
        (items || []).slice(0, 3).forEach(function (label) {
            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = label;
            button.addEventListener('click', function () {
                if (label.indexOf('登录') !== -1 && loginUrl) {
                    window.location.href = loginUrl;
                    return;
                }
                input.value = label;
                form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
            });
            suggestions.appendChild(button);
        });
    }

    var wrap = document.getElementById('xingzaiFloat');
    var dragState = {
        active: false,
        moved: false,
        startX: 0,
        startY: 0,
        originLeft: 0,
        originTop: 0
    };

    function beginDrag(clientX, clientY) {
        var rect = wrap.getBoundingClientRect();
        wrap.style.left = rect.left + 'px';
        wrap.style.top = rect.top + 'px';
        wrap.style.right = 'auto';
        wrap.style.bottom = 'auto';
        dragState.active = true;
        dragState.moved = false;
        dragState.startX = clientX;
        dragState.startY = clientY;
        dragState.originLeft = rect.left;
        dragState.originTop = rect.top;
    }

    function moveDrag(clientX, clientY) {
        if (!dragState.active) {
            return;
        }
        var dx = clientX - dragState.startX;
        var dy = clientY - dragState.startY;
        if (Math.abs(dx) > 4 || Math.abs(dy) > 4) {
            dragState.moved = true;
        }
        var maxLeft = Math.max(0, window.innerWidth - wrap.offsetWidth);
        var maxTop = Math.max(0, window.innerHeight - wrap.offsetHeight);
        var nextLeft = Math.min(maxLeft, Math.max(0, dragState.originLeft + dx));
        var nextTop = Math.min(maxTop, Math.max(0, dragState.originTop + dy));
        wrap.style.left = nextLeft + 'px';
        wrap.style.top = nextTop + 'px';
    }

    function endDrag() {
        if (!dragState.active) {
            return false;
        }
        dragState.active = false;
        return dragState.moved;
    }

    trigger.addEventListener('click', function (event) {
        if (dragState.moved) {
            dragState.moved = false;
            event.preventDefault();
            return;
        }
        panel.classList.toggle('open');
    });

    trigger.addEventListener('mousedown', function (event) {
        beginDrag(event.clientX, event.clientY);
    });
    document.addEventListener('mousemove', function (event) {
        moveDrag(event.clientX, event.clientY);
    });
    document.addEventListener('mouseup', function () {
        endDrag();
    });

    trigger.addEventListener('touchstart', function (event) {
        var touch = event.touches[0];
        beginDrag(touch.clientX, touch.clientY);
    }, { passive: true });
    document.addEventListener('touchmove', function (event) {
        if (!dragState.active) {
            return;
        }
        var touch = event.touches[0];
        moveDrag(touch.clientX, touch.clientY);
    }, { passive: true });
    document.addEventListener('touchend', function () {
        endDrag();
    });

    document.addEventListener('click', function (event) {
        if (!wrap.contains(event.target)) {
            panel.classList.remove('open');
        }
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var value = input.value.trim();
        if (!value) {
            return;
        }
        appendMessage('user', value);
        input.value = '';
        fetch('backend/xingzai_chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: value })
        })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            appendMessage('bot', data.reply || '我刚刚有点走神了，你再问我一次。');
            renderSuggestions(data.suggestions || [], data.login_url || '');
            if (data.login_required) {
                input.placeholder = '请先登录后再和星仔继续聊天';
            }
        })
        .catch(function () {
            appendMessage('bot', '我刚刚没有接上，请你再发一次。');
        });
    });
})();
</script>
<script src="/js/xingzai-widget.js" data-api="/backend/xingzai_chat.php" data-avatar="/images/xingzai-avatar.jpg"></script>
</body>
</html>
