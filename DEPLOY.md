# Deploying Starwaves

This project is intended to be deployed with:

- GitHub for source control
- Nginx + PHP-FPM on the server
- Cloudflare for DNS/CDN/HTTPS
- Baidu Netdisk or object storage for music assets
- the site itself as the playback/download proxy

## 1. Recommended domain layout

- `www.starwaves.com.cn` - main site and backend pages
- `media.starwaves.com.cn` - playback/download proxy endpoints
- `static.starwaves.com.cn` - CSS/JS/images via CDN

You can start with only `www` + `media` and add `static` later.

## 2. Server directory layout

Recommended production path:

```text
/var/www/starwaves
```

Keep runtime data writable on the server, but outside Git-managed content where practical.

Typical writable paths used by this project:

- `storage/`
- `logs/`
- `backend/generated-covers/`
- `backend/lyrics/`
- temporary processing directories used by mix/master jobs

Do not expose writable upload/runtime directories directly through Nginx.

## 3. First deploy

Clone the repository:

```bash
git clone git@github.com:meiguilanger-cloud/starwaves.git /var/www/starwaves
cd /var/www/starwaves
```

If the server only has HTTPS Git access configured, clone with:

```bash
git clone https://github.com/meiguilanger-cloud/starwaves.git /var/www/starwaves
```

## 4. PHP/Nginx prerequisites

Install and enable:

- `nginx`
- `php-fpm`
- `php-sqlite3`
- `php-curl`
- `php-mbstring`
- `php-xml`
- `ffmpeg` / `ffprobe`
- `python3` for helper scripts

Adjust for your distro and PHP version.

## 5. Nginx setup

Use the repository `nginx.conf` as the starting template.

Main ideas in that file:

- `www` serves pages and backend UI
- `media` only serves stream/download endpoints
- `static` only serves cacheable CSS/JS/images/fonts
- `backend/uploads/`, `storage/`, and `logs/` are blocked from public access

After copying the config into `/etc/nginx/sites-available/starwaves`, test it:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

## 6. Runtime configuration

Store production values in your environment/process manager instead of Git.

Use `.env.example` as the checklist for:

- `SITE_BASE_URL`
- `STATIC_BASE_URL`
- `MEDIA_BASE_URL`
- cookie domain
- app environment flags
- storage credentials/tokens

## 7. Small-cache policy for this server

This server is resource-constrained and should not be treated as a large media cache node.

Fixed operating rule:

- keep audio cache small and short-lived
- use Baidu Netdisk for formal long-term music storage
- use the server only for lightweight proxying and temporary hot-cache relief

Current implementation direction:

- short-lived local hot cache for proxied audio
- aggressive cleanup of expired cache files
- cap local cache size/file count instead of allowing unbounded growth

## 8. Cloudflare guidance

Recommended caching posture:

- `static.*`:
  - cache CSS/JS/images/fonts aggressively
- `www.*`:
  - do not fully cache HTML or backend pages
- `media.*`:
  - start conservative; avoid aggressive CDN caching on dynamic stream endpoints until playback is fully stable

Cloudflare should front the domains, but the site should keep ownership of playback URLs.
Do not expose raw Baidu Netdisk links to the browser as your formal long-term playback URLs.

## 9. Update flow

On the server:

```bash
cd /var/www/starwaves
git pull origin main
sudo systemctl reload nginx
sudo systemctl reload php8.2-fpm
```

If you later add migrations, cache warmup, or build steps, extend this flow.

## 10. Important repository boundary

This repository intentionally excludes:

- uploaded audio
- local `.db` files
- logs
- runtime cache/session data
- temporary exports
- nested backup project copies

That is expected. Production content storage should remain on the server or external storage, not in GitHub.
