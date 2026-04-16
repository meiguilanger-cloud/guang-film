-- SQLite schema for new_music_project

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    email TEXT NOT NULL UNIQUE,
    mobile TEXT,
    full_name TEXT,
    bio TEXT,
    avatar_path TEXT,
    credits INTEGER NOT NULL DEFAULT 0,
    remember_token TEXT,
    remember_expires DATETIME,
    password TEXT,
    password_hash TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS songs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    description TEXT NOT NULL,
    lyrics TEXT,
    lrc_path TEXT,
    lyrics_status TEXT NOT NULL DEFAULT 'none',
    lyrics_note TEXT,
    lrc_generated_at DATETIME,
    storage_type TEXT NOT NULL DEFAULT 'local',
    archive_path TEXT,
    archived_at DATETIME,
    netdisk_cached_dlink TEXT,
    netdisk_cached_dlink_expires_at DATETIME,
    mastered_cached_dlink TEXT,
    mastered_cached_dlink_expires_at DATETIME,
    mastered_preview_cached_dlink TEXT,
    mastered_preview_cached_dlink_expires_at DATETIME,
    file_path TEXT NOT NULL,
    user_id INTEGER NOT NULL,
    play_count INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS password_resets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token TEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS favorites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    song_id INTEGER NOT NULL,
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (song_id) REFERENCES songs(id)
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_favorites_user_song ON favorites(user_id, song_id);

CREATE TABLE IF NOT EXISTS payment_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    request_token TEXT NOT NULL UNIQUE,
    amount INTEGER NOT NULL,
    credits INTEGER NOT NULL,
    payment_method TEXT NOT NULL,
    note TEXT,
    status TEXT NOT NULL DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
