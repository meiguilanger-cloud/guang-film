<?php
// SQLite wrapper with lightweight migrations for new_music_project.

function getPdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbFile = __DIR__ . '/data.db';
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    initializeSchema($pdo);
    runMigrations($pdo);

    return $pdo;
}

function initializeSchema(PDO $pdo): void {
    $sqlFile = __DIR__ . '/db.sql';
    if (!file_exists($sqlFile)) {
        throw new RuntimeException('缺少数据库初始化脚本 db.sql');
    }
    $pdo->exec(file_get_contents($sqlFile));
}

function runMigrations(PDO $pdo): void {
    ensureColumn($pdo, 'users', 'mobile', 'TEXT');
    ensureColumn($pdo, 'users', 'full_name', 'TEXT');
    ensureColumn($pdo, 'users', 'bio', 'TEXT');
    ensureColumn($pdo, 'users', 'avatar_path', 'TEXT');
    ensureColumn($pdo, 'users', 'credits', 'INTEGER NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'users', 'remember_token', 'TEXT');
    ensureColumn($pdo, 'users', 'remember_expires', 'DATETIME');
    ensureColumn($pdo, 'users', 'password_hash', 'TEXT');
    ensureColumn($pdo, 'users', 'password', 'TEXT');
    ensureColumn($pdo, 'songs', 'lyrics', 'TEXT');
    ensureColumn($pdo, 'songs', 'lrc_path', 'TEXT');
    ensureColumn($pdo, 'songs', 'lyrics_status', "TEXT NOT NULL DEFAULT 'none'");
    ensureColumn($pdo, 'songs', 'lyrics_note', 'TEXT');
    ensureColumn($pdo, 'songs', 'lrc_generated_at', 'DATETIME');
    ensureColumn($pdo, 'songs', 'storage_type', "TEXT NOT NULL DEFAULT 'local'");
    ensureColumn($pdo, 'songs', 'archive_path', 'TEXT');
    ensureColumn($pdo, 'songs', 'archived_at', 'DATETIME');
    ensureColumn($pdo, 'songs', 'play_count', 'INTEGER NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'songs', 'duration_seconds', 'REAL');
    ensureColumn($pdo, 'songs', 'duration_label', 'TEXT');
    ensureColumn($pdo, 'songs', 'visibility', "TEXT NOT NULL DEFAULT 'private'");
    ensureColumn($pdo, 'songs', 'image_url', "TEXT");
    ensureColumn($pdo, 'songs', 'source', "TEXT NOT NULL DEFAULT 'user'");
    ensureColumn($pdo, 'songs', 'ai_task_id', 'TEXT');
    ensureColumn($pdo, 'songs', 'ai_audio_id', 'TEXT');
    ensureColumn($pdo, 'songs', 'cover_task_id', 'TEXT');
    ensureColumn($pdo, 'songs', 'cover_status', 'TEXT');
    ensureColumn($pdo, 'songs', 'remix_task_id', 'TEXT');
    ensureColumn($pdo, 'songs', 'remix_status', 'TEXT');
    ensureColumn($pdo, 'songs', 'stem_task_id', 'TEXT');
    ensureColumn($pdo, 'songs', 'stem_status', 'TEXT');
    ensureColumn($pdo, 'songs', 'stem_type', 'TEXT');
    ensureColumn($pdo, 'songs', 'extend_task_id', 'TEXT');
    ensureColumn($pdo, 'songs', 'extend_status', 'TEXT');
    ensureColumn($pdo, 'songs', 'mastering_job_id', 'INTEGER');
    ensureColumn($pdo, 'songs', 'mastered_file_path', 'TEXT');
    ensureColumn($pdo, 'songs', 'mastered_preview_path', 'TEXT');
    ensureColumn($pdo, 'songs', 'mastered_archive_path', 'TEXT');
    ensureColumn($pdo, 'songs', 'mastered_preview_archive_path', 'TEXT');
    ensureColumn($pdo, 'songs', 'mastering_status', "TEXT NOT NULL DEFAULT 'none'");
    ensureColumn($pdo, 'songs', 'mastered_at', 'DATETIME');
    ensureColumn($pdo, 'songs', 'netdisk_cached_dlink', 'TEXT');
    ensureColumn($pdo, 'songs', 'netdisk_cached_dlink_expires_at', 'DATETIME');
    ensureColumn($pdo, 'songs', 'mastered_cached_dlink', 'TEXT');
    ensureColumn($pdo, 'songs', 'mastered_cached_dlink_expires_at', 'DATETIME');
    ensureColumn($pdo, 'songs', 'mastered_preview_cached_dlink', 'TEXT');
    ensureColumn($pdo, 'songs', 'mastered_preview_cached_dlink_expires_at', 'DATETIME');
    ensureColumn($pdo, 'mastering_jobs', 'analysis_before_json', 'TEXT');
    ensureColumn($pdo, 'mastering_jobs', 'analysis_target_json', 'TEXT');
    ensureColumn($pdo, 'mastering_jobs', 'analysis_after_json', 'TEXT');
    ensureColumn($pdo, 'payment_requests', 'request_token', 'TEXT');
    ensureColumn($pdo, 'payment_requests', 'note', 'TEXT');
    ensureColumn($pdo, 'payment_requests', 'status', "TEXT NOT NULL DEFAULT 'pending'");
    ensureColumn($pdo, 'payment_requests', 'reviewed_at', 'DATETIME');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_favorites_user_song ON favorites(user_id, song_id)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, request_token TEXT NOT NULL UNIQUE, amount INTEGER NOT NULL, credits INTEGER NOT NULL, payment_method TEXT NOT NULL, note TEXT, status TEXT NOT NULL DEFAULT 'pending', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, reviewed_at DATETIME, FOREIGN KEY (user_id) REFERENCES users(id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS mix_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, mix_mode TEXT NOT NULL DEFAULT 'software', project_name TEXT, artist_name TEXT, song_style TEXT, notes TEXT, track_count INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'queued', charged_credits INTEGER NOT NULL DEFAULT 0, preview_url TEXT, mix_file_url TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id))");
    ensureColumn($pdo, 'mix_jobs', 'project_name', 'TEXT');
    ensureColumn($pdo, 'mix_jobs', 'artist_name', 'TEXT');
    ensureColumn($pdo, 'mix_jobs', 'song_style', 'TEXT');
    ensureColumn($pdo, 'mix_jobs', 'notes', 'TEXT');
    ensureColumn($pdo, 'mix_jobs', 'track_count', 'INTEGER NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'mix_jobs', 'status', "TEXT NOT NULL DEFAULT 'queued'");
    ensureColumn($pdo, 'mix_jobs', 'charged_credits', 'INTEGER NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'mix_jobs', 'preview_url', 'TEXT');
    ensureColumn($pdo, 'mix_jobs', 'mix_file_url', 'TEXT');
    ensureColumn($pdo, 'mix_jobs', 'preview_archive_path', 'TEXT');
    ensureColumn($pdo, 'mix_jobs', 'mix_file_archive_path', 'TEXT');
    ensureColumn($pdo, 'mix_jobs', 'updated_at', 'DATETIME DEFAULT CURRENT_TIMESTAMP');
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void {
    $stmt = $pdo->query("PRAGMA table_info($table)");
    $columns = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($columns as $info) {
        if (($info['name'] ?? '') === $column) {
            return;
        }
    }
    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
}
?>
