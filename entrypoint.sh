#!/bin/bash
set -e

cd /app

echo "==> Creating SQLite database if not exists..."
touch /app/database/database.sqlite
chown www-data:www-data /app/database/database.sqlite

echo "==> Running database migrations..."
php artisan migrate --force

echo "==> Creating storage symlink..."
php artisan storage:link 2>/dev/null || true

echo "==> Caching config, routes and views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Checking voice samples..."
VOICE_COUNT=$(sqlite3 /app/database/database.sqlite "SELECT COUNT(*) FROM voice_samples;" 2>/dev/null || echo "0")
if [ "$VOICE_COUNT" = "0" ]; then
    echo "==> No voices found — running voices:refresh..."
    php artisan voices:refresh
else
    echo "==> Voices already loaded ($VOICE_COUNT found), skipping."
fi

echo "==> Starting services (nginx + php-fpm + queue + scheduler)..."
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
