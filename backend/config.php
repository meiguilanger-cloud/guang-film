<?php
// Simplified config for starwaves_project using SQLite + API settings.
require_once __DIR__ . '/db.php';

function starAiApiConfig(): array {
    return [
        'suno_api_key' => '5d1f6845cebdce33a9637e553c35a793',
        'suno_api_base' => 'https://api.sunoapi.org',
        'suno_model' => 'V4_5',
    ];
}

function absoluteUrl(string $path): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'starwaves.com.cn';
    $path = '/' . ltrim($path, '/');
    return $scheme . '://' . $host . $path;
}
