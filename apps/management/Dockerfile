# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Laravel + Filament + Inertia/React management app (php-fpm runtime image).
# Multi-stage: build frontend assets, install PHP deps, assemble slim runtime.
# Serve HTTP by pairing this image with an nginx container (official image)
# that proxies to php-fpm on port 9000. Do NOT expose php-fpm publicly.
# ---------------------------------------------------------------------------

# Stage 1 — build Vite/React assets into public/build
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci
COPY . .
RUN npm run build

# Stage 2 — install PHP dependencies (production only, no dev packages)
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# Platform checks are skipped here because this stage only downloads packages:
# the composer image ships PHP 8.5 without ext-gd, while the actual runtime
# stage below is PHP 8.4 with ext-gd installed — which satisfies the lock file.
RUN composer install \
    --no-dev \
    --no-scripts \
    --prefer-dist \
    --no-interaction \
    --optimize-autoloader \
    --ignore-platform-req=php \
    --ignore-platform-req=ext-gd

# Stage 3 — runtime
# PHP 8.4 exactly: symfony v8 in composer.lock needs >=8.4.1, phpspreadsheet needs <8.5.
FROM php:8.4-fpm-bookworm AS runtime
WORKDIR /var/www/html

# System libraries needed by the PHP extensions below.
# zip + gd + xml/mbstring are required by maatwebsite/excel (PhpSpreadsheet).
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libxml2-dev \
        libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        bcmath \
        zip \
        gd \
        exif \
        pcntl \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

# Recommended production php.ini defaults.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Application source, vendor (stage 2), built assets (stage 1).
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# Source copied from removable macOS volumes can retain owner-only mode bits.
# PHP-FPM must traverse the application tree and Nginx must read public assets;
# writable runtime directories are assigned explicitly below.
RUN chmod -R a+rX .

# php-fpm runs as www-data; it must own writable dirs. `storage/` is normally
# ignored by source control and mounted from a named volume at runtime, so make
# the empty tree before assigning ownership for reproducible clean builds.
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/laravel-entrypoint.sh /usr/local/bin/laravel-entrypoint
RUN chmod +x /usr/local/bin/laravel-entrypoint

EXPOSE 9000
ENTRYPOINT ["laravel-entrypoint"]
CMD ["php-fpm"]
