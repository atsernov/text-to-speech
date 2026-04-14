# Text-to-Speech App

A web application for converting text to speech using the [TartuNLP](https://tartunlp.ai/) API.  
Built with **Laravel 13**, **Vue 3**, **Inertia.js**, and **Tailwind CSS**.

---

## Features

- Text-to-speech synthesis with selectable voice and playback speed
- Long text support — automatically split into chunks and merged back
- File upload (`.txt`, `.docx`) and URL-to-text extraction
- Session-based audio history (no registration required)
- Voice preview samples generated daily
- Admin panel for monitoring jobs and managing audio files

---

## Requirements

| Tool | Version | Notes |
|------|---------|-------|
| PHP | 8.3+ | With extensions: `sqlite3`, `pdo_sqlite`, `zip`, `mbstring`, `curl`, `fileinfo` |
| Composer | 2.x | [getcomposer.org](https://getcomposer.org) |
| Node.js | 20+ | [nodejs.org](https://nodejs.org) |
| npm | 10+ | Comes with Node.js |
| SQLite | 3.x | Usually pre-installed; on Linux: `apt install sqlite3` |

> **No MySQL, Redis, or ffmpeg required.** Everything runs on SQLite and PHP.

---

## Installation

### 1. Clone the repository

```bash
git clone <repository-url>
cd text-to-speech
```

### 2. Install PHP dependencies

```bash
composer install
```

### 3. Create the environment file

```bash
# Linux / macOS
cp .env.example .env

# Windows (Command Prompt)
copy .env.example .env

# Windows (PowerShell)
Copy-Item .env.example .env
```

### 4. Generate the application key

```bash
php artisan key:generate
```

### 5. Run database migrations

```bash
php artisan migrate
```

> Laravel automatically creates the `database/database.sqlite` file if it does not exist yet.

### 7. Install Node.js dependencies

```bash
npm install
```

### 8. Create the public storage symlink

```bash
php artisan storage:link
```

### 9. Generate voice samples

This command fetches all available voices from the TartuNLP API, generates a short audio sample for each, and removes any non-functional voices from the list.  
**Requires an internet connection. Takes ~1–2 minutes.**

```bash
php artisan voices:refresh
```

### 10. Create an admin user

```bash
php artisan admin:create
```

Enter the email, name, and password when prompted.  
If the user already exists, they will be promoted to admin without changing their password.

---

## Running locally

You need **three processes** running simultaneously. Open three separate terminal windows:

### Terminal 1 — Laravel development server
```bash
php artisan serve
```

### Terminal 2 — Queue worker (background job processing)
```bash
php artisan queue:work --tries=1
```

### Terminal 3 — Vite (frontend asset compiler)
```bash
npm run dev
```

The app will be available at **http://localhost:8000**.  
The admin panel is at **http://localhost:8000/admin**.

> **Tip:** You can run all three in a single terminal using the built-in shortcut:
> ```bash
> composer run dev
> ```

---

## Scheduler (voice list auto-update)

The voice list is refreshed every day at 3:00 AM via the Laravel Scheduler.

### Linux / macOS — add one cron entry

```bash
crontab -e
```

Add this line (adjust the path to your project):

```
* * * * * cd /path/to/text-to-speech && php artisan schedule:run >> /dev/null 2>&1
```

### Windows

On Windows the simplest approach for development is to run the refresh manually when needed:

```bash
php artisan voices:refresh
```

For a production Windows server, use Windows Task Scheduler to run `php artisan schedule:run` every minute.

---

## Environment variables

The most important variables in `.env`:

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_URL` | `http://localhost` | Base URL — affects generated audio links |
| `APP_ENV` | `local` | Set to `production` on a live server |
| `APP_DEBUG` | `true` | Set to `false` in production |
| `DB_CONNECTION` | `sqlite` | Database driver |
| `QUEUE_CONNECTION` | `database` | Queue driver (uses the SQLite DB) |
| `CACHE_STORE` | `database` | Cache driver (uses the SQLite DB) |
| `SESSION_DRIVER` | `database` | Session driver |

No changes to `.env` are required to run the project locally with the default settings.

---

## Project structure (key files)

```
app/
├── Console/Commands/
│   ├── CreateAdminUser.php       # php artisan admin:create
│   └── RefreshVoiceSamples.php   # php artisan voices:refresh
├── Http/Controllers/
│   ├── Admin/AdminController.php
│   ├── Api/AudioController.php
│   ├── Api/TextExtractorController.php
│   ├── Api/UrlExtractorController.php
│   └── Index.php
├── Jobs/
│   └── SynthesizeLongTextJob.php
├── Models/
│   ├── AudioFile.php
│   ├── User.php
│   └── VoiceSample.php
└── Services/
    ├── TextExtractorService.php
    ├── TextSplitterService.php
    ├── UrlTextExtractorService.php
    └── WavMergerService.php

resources/js/pages/
├── Index.vue           # Main TTS page
└── admin/
    ├── Dashboard.vue   # Admin — stats overview
    ├── Files.vue       # Admin — all audio files
    └── Jobs.vue        # Admin — active queue jobs

storage/app/public/
├── audio/              # Generated user audio files
└── voice-samples/      # Voice preview samples
```

---

## Useful Artisan commands

```bash
# Create or promote an admin user
php artisan admin:create

# Refresh the voice list and regenerate preview samples
php artisan voices:refresh

# Process queued jobs (runs until stopped with Ctrl+C)
php artisan queue:work --tries=1

# Restart queue workers after code changes
php artisan queue:restart

# Clear all caches
php artisan optimize:clear
```

---

## Troubleshooting

**Audio files are not accessible**  
Make sure the storage symlink exists:
```bash
php artisan storage:link
```

**Queue jobs are stuck as "pending"**  
The queue worker must be running:
```bash
php artisan queue:work --tries=1
```
After changing job code, always restart the worker:
```bash
php artisan queue:restart
```

**"Unable to locate file in Vite manifest"**  
The Vite dev server is not running:
```bash
npm run dev
```

**SSL certificate errors on Windows**  
PHP on Windows sometimes cannot verify SSL certificates. This is already handled in the codebase with `->withoutVerifying()` on all external HTTP calls.

**Voice list is empty on the main page**  
Run the voice refresh command:
```bash
php artisan voices:refresh
```
