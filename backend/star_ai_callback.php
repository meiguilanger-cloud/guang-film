<?php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input') ?: '';
if ($raw !== '') {
    logMessage('STAR.AI callback: ' . mb_substr($raw, 0, 1000));
    $data = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $tracks = $data['response']['sunoData'] ?? [];
        // Try to retrieve task identifier for Extend tasks
        $taskId = $data['taskId'] ?? $data['task_id'] ?? '';
        if (!empty($tracks)) {
            $pdo = getPdo();
            foreach ($tracks as $track) {
                $title = $track['title'] ?? '未命名';
                $audioUrl = $track['audio_url'] ?? '';
                $imageUrl = $track['image_url'] ?? '';
                $userId = 1; // default admin user
                $stmt = $pdo->prepare('INSERT INTO songs (title, file_path, image_url, source, user_id) VALUES (:title, :path, :img, :src, :uid)');
                $stmt->execute([
                    ':title' => $title,
                    ':path' => $audioUrl,
                    ':img' => $imageUrl,
                    ':src' => 'ai',
                    ':uid' => $userId,
                ]);
                logMessage("Inserted AI track: {$title}");
            }
            // If this callback pertains to an Extend task, mark original song as completed
            if ($taskId !== '') {
                $stmt = $pdo->prepare('UPDATE songs SET extend_status = :status WHERE extend_task_id = :task_id');
                $stmt->execute([
                    ':status' => 'completed',
                    ':task_id' => $taskId,
                ]);
                logMessage("Extend task {$taskId} marked as completed.");
            }
        }
    } else {
        logMessage('Invalid JSON in STAR.AI callback');
    }
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
