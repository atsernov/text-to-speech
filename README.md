# Kõlaro — Text-to-Speech App

A web application for converting text to speech using the [TartuNLP](https://tartunlp.ai/) API.  
Built with **Laravel 13**, **Vue 3**, **Inertia.js**, and **Tailwind CSS**.

🌐 **Live instance:** [http://kolaro.itcollege.ee/](http://kolaro.itcollege.ee/)

---

## Features

- Text-to-speech synthesis with selectable voice and playback speed
- Long text support — automatically split into chunks and merged back
- File upload (`.txt`, `.docx`, `.pdf`) and URL-to-text extraction
- Session-based audio history with progress tracking (no registration required)
- Audio files automatically deleted after **30 days**; days remaining shown in history
- Voice preview samples refreshed daily
- Admin panel for monitoring jobs and managing audio files
- Docker support for easy deployment

---

## Running with Docker

### Requirements

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Windows / macOS)
- Or `docker` + `docker-compose-plugin` (Linux)

> **Windows / macOS (Docker Desktop):** use `docker-compose`.  
> **Linux:** use `docker compose` — the Compose plugin bundled with Docker Engine.

### 1. Clone the repository

```bash
git clone https://github.com/atsernov/text-to-speech.git
cd text-to-speech
```

### 2. Create the environment file

```bash
# Linux / macOS
cp .env.example .env

# Windows (PowerShell)
Copy-Item .env.example .env
```

### 3. Fill in the required values in `.env`

```env
APP_KEY=        # generate with: php artisan key:generate --show
APP_URL=
```

Generate the key (requires PHP locally, or skip to step 4 and generate inside Docker):

```bash
php artisan key:generate --show
# Copy the output (base64:...) into APP_KEY=
```

### 4. Build and start

```bash
# Windows / macOS
docker-compose up --build -d

# Linux
docker compose up --build -d
```

On the **first run** the container automatically:
- Runs database migrations
- Downloads and generates voice preview samples (~1–2 min)

### 6. Create the admin user

```bash
# Windows / macOS
docker-compose exec app php artisan admin:create --email=admin@example.com --name="Admin" --password="your-password"

# Linux
docker compose exec app php artisan admin:create --email=admin@example.com --name="Admin" --password="your-password"
```

The app is available at **http://localhost:8080**.  
The admin panel is at **http://localhost:8080/admin**.

### Common Docker commands

On **Windows / macOS** use `docker-compose`, on **Linux** use `docker compose` (with a space).

```bash
# Start in background
docker-compose up -d          # Windows / macOS
docker compose up -d          # Linux

# Stop
docker-compose down           # Windows / macOS
docker compose down           # Linux

# View live logs
docker-compose logs -f        # Windows / macOS
docker compose logs -f        # Linux

# Rebuild after code changes
docker-compose up --build -d  # Windows / macOS
docker compose up --build -d  # Linux

# Open a shell inside the container
docker-compose exec app bash  # Windows / macOS
docker compose exec app bash  # Linux

# Run artisan commands inside the container
docker-compose exec app php artisan <command>  # Windows / macOS
docker compose exec app php artisan <command>  # Linux
```

---

## Running locally

### Requirements

| Tool | Version | Notes |
|------|---------|-------|
| PHP | 8.4+ | Extensions: `sqlite3`, `pdo_sqlite`, `zip`, `mbstring`, `xml`, `intl`, `curl`, `fileinfo` |
| Composer | 2.x | [getcomposer.org](https://getcomposer.org) |
| Node.js | 20+ | [nodejs.org](https://nodejs.org) |
| npm | 10+ | Comes with Node.js |

> **No MySQL, Redis, or ffmpeg required.** Everything runs on SQLite and PHP.

### 1. Clone the repository

```bash
git clone https://github.com/atsernov/text-to-speech.git
cd text-to-speech
```

### 2. Install dependencies

```bash
composer install
npm install
```

### 3. Create the environment file

```bash
# Linux / macOS
cp .env.example .env

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

> Laravel automatically creates `database/database.sqlite` if it does not exist.

### 6. Create the public storage symlink

```bash
php artisan storage:link
```

### 7. Generate voice samples

Fetches all available voices from the TartuNLP API and generates a short audio preview for each.  
**Requires an internet connection. Takes ~1–2 minutes.**

```bash
php artisan voices:refresh
```

### 8. Create an admin user

```bash
php artisan admin:create --email=admin@example.com --name="Admin" --password="your-password"
```

If the user already exists, they will be promoted to admin without changing their password.

### 9. Start the development servers

You need **three processes** running simultaneously. The easiest way:

```bash
composer run dev
```

Or manually in three separate terminals:

```bash
php artisan serve          # Terminal 1 — Laravel server
php artisan queue:work --tries=1  # Terminal 2 — Queue worker
npm run dev                # Terminal 3 — Vite (frontend)
```

The app will be available at **http://localhost:8000**.  
The admin panel is at **http://localhost:8000/admin**.

---

## Scheduler (automatic daily tasks)

Two tasks run daily via the Laravel Scheduler:

| Time | Command | Description |
|------|---------|-------------|
| 03:00 | `voices:refresh` | Refresh voice list and preview samples |
| 04:00 | `audio:cleanup` | Delete audio files and records older than 30 days |

### Linux / macOS — add one cron entry

```bash
crontab -e
```

Add this line (adjust the path):

```
* * * * * cd /path/to/text-to-speech && php artisan schedule:run >> /dev/null 2>&1
```

> **Docker:** The scheduler runs automatically inside the container — no cron setup needed.

### Windows (development)

Run commands manually when needed:

```bash
php artisan voices:refresh
php artisan audio:cleanup
```

---

## Audio file retention

Generated audio files are automatically deleted **30 days** after creation.

- The days remaining are shown in the user's history sidebar
- The admin panel displays the expiry for each file
- Deletion runs daily at 04:00 via the scheduler (or `php artisan audio:cleanup`)

---

## Environment variables

Key variables in `.env`:

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_KEY` | *(empty)* | Required — generate with `php artisan key:generate` |
| `APP_URL` | `http://localhost` | Base URL — affects generated audio links |
| `APP_ENV` | `local` | Set to `production` on a live server |
| `APP_DEBUG` | `true` | Set to `false` in production |
| `DB_CONNECTION` | `sqlite` | Database driver |
| `QUEUE_CONNECTION` | `database` | Queue driver |
| `CACHE_STORE` | `database` | Cache driver |
| `SESSION_DRIVER` | `database` | Session driver |

---

## Project structure (key files)

```
app/
├── Console/Commands/
│   ├── CleanupAudioFiles.php     # php artisan audio:cleanup
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
│   ├── AudioFile.php             # RETENTION_DAYS = 30
│   ├── User.php
│   └── VoiceSample.php
└── Services/
    ├── TextExtractorService.php
    ├── TextSplitterService.php
    ├── UrlTextExtractorService.php
    └── WavMergerService.php

docker/
├── nginx.conf                    # Nginx web server config
└── supervisord.conf              # Process manager (nginx + php-fpm + queue + scheduler)

resources/js/pages/
├── Index.vue                     # Main TTS page
└── admin/
    ├── Dashboard.vue             # Admin — stats overview
    ├── Files.vue                 # Admin — all audio files (with expiry)
    └── Jobs.vue                  # Admin — active queue jobs

storage/app/public/
├── audio/                        # Generated user audio files (deleted after 30 days)
└── voice-samples/                # Voice preview samples (refreshed daily)
```

---

## Useful Artisan commands

```bash
# Create or promote an admin user
php artisan admin:create --email=admin@example.com --name="Admin" --password="your-password"

# Refresh the voice list and regenerate preview samples
php artisan voices:refresh

# Delete audio files and records older than 30 days
php artisan audio:cleanup

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
# or inside Docker (Windows / macOS):
docker-compose exec app php artisan storage:link
# or inside Docker (Linux):
docker compose exec app php artisan storage:link
```

**Queue jobs are stuck as "pending"**  
The queue worker must be running. In Docker it starts automatically.  
Locally:
```bash
php artisan queue:work --tries=1
```
After changing job code, restart the worker:
```bash
php artisan queue:restart
```

**"Unable to locate file in Vite manifest"**  
The frontend assets have not been compiled:
```bash
npm run dev   # development
npm run build # production
```

**SSL certificate errors on Windows**  
Download the Mozilla CA bundle and place it at `storage/cacert.pem`:
```bash
curl -o storage/cacert.pem https://curl.se/ca/cacert.pem
```
The app automatically uses this file when it exists. On Linux the system certificates are used.

**Voice list is empty on the main page**  
```bash
php artisan voices:refresh
# or inside Docker (Windows / macOS):
docker-compose exec app php artisan voices:refresh
# or inside Docker (Linux):
docker compose exec app php artisan voices:refresh
```

**Container fails to start**  
Check the logs:
```bash
docker-compose logs app  # Windows / macOS
docker compose logs app  # Linux
```
