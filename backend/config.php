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

function isHttpsRequestConfig(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function currentRequestScheme(): string {
    return isHttpsRequestConfig() ? 'https' : 'http';
}

function currentHostName(): string {
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'starwaves.com.cn');
    return preg_replace('/:\d+$/', '', $host) ?: 'starwaves.com.cn';
}

function envUrlOrDefault(string $key, string $default): string {
    $value = trim((string) getenv($key));
    if ($value === '') {
        return rtrim($default, '/');
    }
    return rtrim($value, '/');
}

function siteBaseUrl(): string {
    return envUrlOrDefault('SITE_BASE_URL', currentRequestScheme() . '://' . currentHostName());
}

function staticBaseUrl(): string {
    return envUrlOrDefault('STATIC_BASE_URL', siteBaseUrl());
}

function mediaBaseUrl(): string {
    return envUrlOrDefault('MEDIA_BASE_URL', siteBaseUrl());
}

function appUrl(string $baseUrl, string $path = ''): string {
    $path = trim($path);
    if ($path === '') {
        return $baseUrl;
    }
    return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
}

function siteUrl(string $path = ''): string {
    return appUrl(siteBaseUrl(), $path);
}

function staticUrl(string $path = ''): string {
    return appUrl(staticBaseUrl(), $path);
}

function mediaUrl(string $path = ''): string {
    return appUrl(mediaBaseUrl(), $path);
}

function absoluteUrl(string $path): string {
    return siteUrl($path);
}
