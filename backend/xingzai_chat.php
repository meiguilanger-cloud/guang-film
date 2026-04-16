<?php
require_once __DIR__ . '/utils.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '仅支持 POST 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '请求内容格式不正确'], JSON_UNESCAPED_UNICODE);
    exit;
}

$message = trim((string) ($data['message'] ?? ''));
if ($message === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '请输入问题或需求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$isLoggedIn = !empty($_SESSION['user_id']);
if (!$isLoggedIn) {
    echo json_encode([
        'ok' => true,
        'reply' => "请先注册或登录星浪账号，登录后我再继续回答你的问题。\n\n登录后你就可以继续问我歌词、STAR.AI、混音、母带和站内功能。",
        'suggestions' => ['立即登录 / 注册'],
        'login_required' => true,
        'login_url' => loginUrlWithReturn('../star-ai.php', true),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function xingzaiContains(string $text, array $keywords): bool {
    foreach ($keywords as $keyword) {
        if ($keyword !== '' && mb_stripos($text, $keyword) !== false) {
            return true;
        }
    }
    return false;
}

function xingzaiLyricReply(string $message): string {
    $style = '流行';
    if (xingzaiContains($message, ['说唱', 'rap', 'hiphop', 'hip-hop'])) {
        $style = '说唱';
    } elseif (xingzaiContains($message, ['民谣', '木吉他', 'folk'])) {
        $style = '民谣';
    } elseif (xingzaiContains($message, ['国风', '古风'])) {
        $style = '国风';
    } elseif (xingzaiContains($message, ['r&b', 'rb', '灵魂'])) {
        $style = 'R&B';
    }

    $theme = '夜晚和心事';
    if (preg_match('/关于(.{1,12})/u', $message, $matches)) {
        $theme = trim($matches[1]);
    } elseif (preg_match('/写一首(.{1,12})/u', $message, $matches)) {
        $theme = trim($matches[1]);
    }

    $lyrics = [
        '[主歌]',
        '风吹过 ' . $theme . ' 的边缘，我把沉默写进了街灯里面',
        '你留下的回声还在盘旋，像一段没说完的从前',
        '我把心事藏进节拍里面，让每次呼吸都慢一点',
        '等副歌落下来的瞬间，才发现自己还在想念',
        '',
        '[副歌]',
        '如果你也听见这首歌，就让晚风替我把答案说',
        '那些来不及说出口的，都化成星光落进你眼眸',
        '如果此刻世界太沉默，就让我继续把故事唱活',
        '哪怕只剩一个人附和，我也想为你把心跳写成歌'
    ];

    return "可以，我先给你一版 {$style} 方向的歌词草稿，主题偏「{$theme}」。\n\n" . implode("\n", $lyrics) . "\n\n如果你愿意，我下一条可以继续帮你补：歌名、风格提示词、主副歌结构，或者直接改成适合 STAR.AI 提交的版本。";
}

$reply = '我是星仔，可以帮你解答星浪音乐站内的音乐问题，也能帮你作词、梳理风格、整理 STAR.AI 提示词。';
$suggestions = ['帮我写一段副歌歌词', 'STAR.AI 怎么用', '混音和母带有什么区别'];

if (xingzaiContains($message, ['作词', '写词', '写歌', '歌词', '副歌', '主歌'])) {
    $reply = xingzaiLyricReply($message);
    $suggestions = ['再写一段主歌', '帮我取歌名', '整理成 STAR.AI 提示词'];
} elseif (xingzaiContains($message, ['star.ai', '做歌', '生成歌曲', '提示词', 'prompt'])) {
    $reply = "STAR.AI 这边现在主要有两种用法：\n\n1. 简单创作：输入一句灵感、情绪或风格，快速出 2 首候选歌\n2. 精准创作：填写歌词、风格、语言、男女声，还能加参考歌曲\n\n如果你告诉我你想做什么风格，我可以直接帮你整理一版可提交的提示词。";
    $suggestions = ['帮我整理一版提示词', '写一首伤感流行歌', '参考周杰伦风格可以吗'];
} elseif (xingzaiContains($message, ['混音', '母带', '区别'])) {
    $reply = "简单说：\n\n- 混音：处理人声、伴奏、空间感、层次和平衡，让歌更完整\n- 母带：在混音完成后做整体响度、频响和最终听感整理，适合上线发布\n\n在星浪音乐里，你可以先用 STAR.AI 生成，再进入混音和母带流程。";
    $suggestions = ['自动混音怎么收费', '软件母带怎么用', '先混音还是先母带'];
} elseif (xingzaiContains($message, ['登录', '注册', '上传', '网站', '页面', '功能'])) {
    $reply = "站内规则目前是：游客可以浏览，但真正操作前需要先登录。\n\n比如 STAR.AI 生成、上传歌曲、充值、后台操作这些，都会要求先登录。\n\n如果你告诉我你想完成哪一步，我可以直接告诉你入口在哪。";
    $suggestions = ['上传歌曲入口在哪', '怎么登录后台', '充值入口在哪'];
}

echo json_encode([
    'ok' => true,
    'reply' => $reply,
    'suggestions' => $suggestions,
], JSON_UNESCAPED_UNICODE);
