<?php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?expired=1');
    exit;
}

$error = '';
$success = '';
$requestRow = null;
$pdo = getPdo();
$userId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = '无效的 CSRF token';
    } else {
        $amount = max(1, (int) ($_POST['amount'] ?? 0));
        $credits = max(1, (int) ($_POST['credits'] ?? 0));
        $paymentMethod = trim($_POST['payment_method'] ?? '');
        $note = trim($_POST['note'] ?? '');

        if (!in_array($paymentMethod, ['wechat', 'alipay', 'usdt'], true)) {
            $error = '支付方式不正确';
        } else {
            $token = 'topup_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(8)), 0, 10);
            $stmt = $pdo->prepare('INSERT INTO payment_requests (user_id, request_token, amount, credits, payment_method, note, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $userId,
                $token,
                $amount,
                $credits,
                $paymentMethod,
                $note,
                'pending'
            ]);
            $requestId = (int) $pdo->lastInsertId();
            $success = '充值申请已生成，请把下面这条唯一充值链发给管理员确认到账。';
            $requestRow = [
                'id' => $requestId,
                'request_token' => $token,
                'amount' => $amount,
                'credits' => $credits,
                'payment_method' => $paymentMethod,
                'note' => $note,
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }
    }
}

if (!$requestRow && !empty($_GET['token'])) {
    $stmt = $pdo->prepare('SELECT * FROM payment_requests WHERE request_token = ? AND user_id = ? LIMIT 1');
    $stmt->execute([trim($_GET['token']), $userId]);
    $requestRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>生成充值链</title>
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
        .backend-card[style*="max-width:760px"] { max-width: none !important; }
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
                <strong>生成充值链</strong>
                <span>生成唯一 token，管理员确认到账后再充值积分</span>
            </div>
        </div>
        <button type="button" class="backend-mobile-toggle" id="backendMobileToggle" aria-expanded="false" aria-controls="backendLinks">☰</button>
        <div class="backend-links" id="backendLinks">
            <a href="../star-ai.php">返回 STAR.AI</a>
            <a href="admin.php">返回后台</a>
        </div>
    </div>

    <div class="backend-card" style="max-width:760px;">
        <span class="backend-kicker">Topup Token</span>
        <h1>我已付款，生成唯一充值链</h1>
        <?php if ($error): ?><div class="error-msg"><?php echo e($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="success-msg"><?php echo e($success); ?></div><?php endif; ?>

        <form class="backend-form" method="post">
            <?php echo csrfInput(); ?>
            <div class="form-grid">
                <div>
                    <label>付款金额（元）</label>
                    <input type="text" name="amount" required value="<?php echo e($_POST['amount'] ?? ($_GET['amount'] ?? '')); ?>">
                </div>
                <div>
                    <label>预计积分</label>
                    <input type="text" name="credits" required value="<?php echo e($_POST['credits'] ?? ($_GET['credits'] ?? '')); ?>">
                </div>
                <div>
                    <label>支付方式</label>
                    <select name="payment_method" style="width:100%; box-sizing:border-box; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12); color:#fff; border-radius:16px; padding:14px 16px; font-size:15px;">
                        <option value="wechat" <?php echo (($_POST['payment_method'] ?? ($_GET['method'] ?? '')) === 'wechat') ? 'selected' : ''; ?>>微信支付</option>
                        <option value="alipay" <?php echo (($_POST['payment_method'] ?? ($_GET['method'] ?? '')) === 'alipay') ? 'selected' : ''; ?>>支付宝</option>
                        <option value="usdt" <?php echo (($_POST['payment_method'] ?? ($_GET['method'] ?? '')) === 'usdt') ? 'selected' : ''; ?>>USDT(TRC20)</option>
                    </select>
                </div>
                <div>
                    <label>备注（可选）</label>
                    <input type="text" name="note" value="<?php echo e($_POST['note'] ?? ''); ?>" placeholder="可填写付款说明">
                </div>
            </div>
            <button type="submit" class="primary-btn">生成唯一充值链</button>
        </form>

        <?php if ($requestRow): ?>
            <div class="backend-card" style="margin-top:22px; background:rgba(255,255,255,0.04);">
                <span class="backend-kicker">Token</span>
                <p><strong>唯一充值链：</strong></p>
                <p style="word-break:break-all; font-size:16px;"><?php echo e($requestRow['request_token']); ?></p>
                <p class="muted">金额：<?php echo e((string) $requestRow['amount']); ?> 元 ｜ 积分：<?php echo e((string) $requestRow['credits']); ?> ｜ 支付方式：<?php echo e($requestRow['payment_method']); ?></p>
                <p class="muted">把这条链发给管理员确认到账，确认成功后再给你挂积分。</p>
            </div>
        <?php endif; ?>
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
});
</script>
<script src="/js/xingzai-widget.js" data-api="/backend/xingzai_chat.php" data-avatar="/images/xingzai-avatar.jpg"></script>
</body>
</html>
