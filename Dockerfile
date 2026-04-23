# ═══════════════════════════════════════════════════════════════════════════════
# Stage 1: Build Vue/Vite frontend
# PHP is needed here because the wayfinder plugin calls php artisan at build time
# ═══════════════════════════════════════════════════════════════════════════════
FROM php:8.4-cli-bookworm AS frontend

# Install Node.js 22
RUN apt-get update && apt-get install -y --no-install-recommends \
        curl ca-certificates git unzip libzip-dev \
    && docker-php-ext-install zip \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Install Composer and PHP dependencies first (wayfinder needs artisan)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Copy application code so artisan + routes are available for wayfinder
COPY . .
RUN composer run-script post-autoload-dump

# Create a minimal .env so artisan doesn't complain
RUN echo "APP_KEY=base64:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa=" > .env \
    && echo "APP_ENV=local" >> .env \
    && echo "DB_CONNECTION=sqlite" >> .env \
    && echo "DB_DATABASE=/tmp/build.sqlite" >> .env \
    && touch /tmp/build.sqlite \
    && php artisan migrate --force 2>/dev/null || true

# Install Node dependencies and build
COPY package.json package-lock.json ./
RUN npm install --include=optional
RUN npm run build

# ═══════════════════════════════════════════════════════════════════════════════
# Stage 2: PHP application (production image)
# ═══════════════════════════════════════════════════════════════════════════════
FROM php:8.4-fpm-bookworm

# Install system packages
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        sqlite3 \
        libsqlite3-dev \
        libzip-dev \
        libicu-dev \
        libxml2-dev \
        libonig-dev \
        curl \
        unzip \
        poppler-utils \
        tesseract-ocr \
        tesseract-ocr-est \
    && docker-php-ext-install \
        pdo_sqlite \
        zip \
        intl \
        mbstring \
        xml \
        bcmath \
        opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Copy Composer from official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP dependencies (cached layer — runs only if composer.json changes)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Copy full application code
COPY . .

# Copy built frontend assets from Stage 1
COPY --from=frontend /app/public/build ./public/build

# Run post-install scripts (package discovery etc.)
RUN composer run-script post-autoload-dump

# Create all necessary directories
RUN mkdir -p \
        database \
        storage/app/public/audio \
        storage/app/public/voice-samples \
        storage/framework/sessions \
        storage/framework/views \
        storage/framework/cache/data \
        storage/logs \
        bootstrap/cache \
        /var/log/supervisor

# Set correct ownership and permissions
RUN chown -R www-data:www-data /app \
    && chmod -R 755 storage bootstrap/cache

# Copy PHP configuration
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini

# Copy nginx config
COPY docker/nginx.conf /etc/nginx/conf.d/app.conf
RUN rm -f /etc/nginx/sites-enabled/default

# Copy supervisor config
COPY docker/supervisord.conf /etc/supervisor/conf.d/app.conf

# Copy and prepare the startup script
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
