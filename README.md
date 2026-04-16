# Starwaves

Starwaves is a music website project with a PHP frontend/backend, song upload flows, Baidu Netdisk archive support, playback proxy endpoints, mix/master workflows, and supporting static assets.

## Repository Scope

This repository stores the website source code and deployment-related files.

It intentionally does not store runtime-only data such as:
- uploaded audio files
- local databases
- logs
- cache/session files
- generated media and temporary exports

Those paths are excluded in `.gitignore` and should stay on the server or in external storage.

## Main Structure

- `backend/` - PHP backend, auth, upload, playback, mix/master, admin
- `css/`, `js/`, `images/`, `fonts/` - static assets
- `src/` - source page templates/assets used during site work
- `docs/` - project notes and design/implementation records
- `tests/` - lightweight project tests
- `nginx.conf` - production-oriented Nginx template for `www` / `media` / `static`
- `.env.example` - deployment variable checklist
- `DEPLOY.md` - deployment and server layout notes
- `docker-compose.yml` / `Dockerfile` - container-related setup

## Current Storage Architecture

Recommended production direction for this project:

- website code: GitHub
- static assets: CDN
- dynamic pages/API: PHP + Nginx on server
- song storage: Baidu Netdisk or object storage
- playback/download: website proxy endpoints

Do not use GitHub as the formal music file storage for production playback.

## Suggested Domains

- `www.your-domain.com` - main website and backend pages
- `media.your-domain.com` - audio proxy/stream/download endpoints
- `static.your-domain.com` - CDN domain for CSS/JS/images

## Deployment Notes

Basic deployment flow:

1. clone the repository onto the server
2. configure PHP, Nginx, and writable runtime directories
3. keep runtime data outside Git-tracked content
4. configure Baidu Netdisk credentials / tokens on the server
5. serve static assets through CDN where appropriate
6. route playback through project proxy endpoints instead of exposing origin links

## GitHub Workflow

Common update flow:

```bash
git pull origin main
```

For first deployment:

```bash
git clone git@github.com:meiguilanger-cloud/starwaves.git
```

## Notes

This repository was split out from a larger workspace so the website can be versioned independently and pushed cleanly to GitHub.
