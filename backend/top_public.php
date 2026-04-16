<?php
require_once __DIR__ . '/utils.php';
header('Content-Type: application/json; charset=utf-8');

$pdo = getPdo();
$stmt = $pdo->prepare('SELECT id, title, file_path, storage_type, archive_path, image_url, play_count, created_at FROM songs WHERE visibility = :vis ORDER BY play_count DESC LIMIT 10');
$stmt->execute([':vis' => 'public']);
$tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($tracks as &$track) {
    $track['audio_url'] = resolveSongAudioUrl($track, 'frontend');
}
unset($track);

echo json_encode(['ok'=>true,'tracks'=>$tracks], JSON_UNESCAPED_UNICODE);
?>