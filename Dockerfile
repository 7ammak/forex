# syntax=docker/dockerfile:1.6
FROM php:8.2-fpm

ENV DEBIAN_FRONTEND=noninteractive

# OS-level deps + sqlite/curl + nginx + supervisor for one-container runtime.
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        sqlite3 \
        libsqlite3-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
        zip \
        unzip \
        curl \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions Laravel + Sanctum need.
RUN docker-php-ext-install \
        pdo \
        pdo_sqlite \
        mbstring \
        bcmath \
        zip

# Composer (copy from the official image — keeps the layer small).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ---- install backend deps first for layer caching ----
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# ---- copy the rest of the backend, then finalize the autoloader ----
COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev \
 && php artisan package:discover --ansi

# ---- copy the prebuilt SPA on top of Laravel's public dir ----
# index.html lands next to index.php; nginx routes /api to PHP-FPM and
# everything else falls back to index.html.
COPY frontend/dist/ ./public/

# Nginx config (replaces the default vhost).
COPY nginx.conf /etc/nginx/sites-available/default

# Entrypoint: runs migrations then boots php-fpm + nginx.
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Storage/cache/database must be writable by the runtime user.
RUN mkdir -p storage/framework/{cache,sessions,views,testing} \
             storage/framework/cache/data \
             storage/logs \
             bootstrap/cache \
             database \
 && touch database/database.sqlite \
 && chown -R www-data:www-data storage bootstrap/cache database \
 && chmod -R ug+rwX storage bootstrap/cache database

EXPOSE 8080

CMD ["/usr/local/bin/entrypoint.sh"]
