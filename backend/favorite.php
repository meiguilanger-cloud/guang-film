<?php
require_once __DIR__.'/db.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo '请先登录';
    exit;
}
$userId = (int)$_SESSION['user_id'];
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['song_id'])) {
    http_response_code(400);
    echo 'Invalid request';
    exit;
}
$songId = (int)$_POST['song_id'];
$action = $_POST['action'] ?? 'add'; // add or remove
$pdo = getPdo();
if ($action === 'remove') {
    $stmt = $pdo->prepare('DELETE FROM favorites WHERE user_id = :uid AND song_id = :sid');
    $stmt->execute([':uid' => $userId, ':sid' => $songId]);
    echo 'removed';
} else {
    // avoid duplicate
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO favorites (user_id, song_id) VALUES (:uid, :sid)');
    $stmt->execute([':uid' => $userId, ':sid' => $songId]);
    echo 'added';
}
?>