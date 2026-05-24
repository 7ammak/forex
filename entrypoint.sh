#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# Make sure runtime-writable paths exist + are owned by the FPM user.
# /data is the persistent Fly volume mount (see fly.toml [[mounts]]).
mkdir -p storage/framework/cache/data \
         storage/framework/sessions \
         storage/framework/views \
         storage/framework/testing \
         storage/logs \
         bootstrap/cache \
         /data
touch /data/database.sqlite
chown -R www-data:www-data storage bootstrap/cache /data
chmod -R ug+rwX storage bootstrap/cache /data

# Refresh runtime config caches (secrets are injected via Fly secrets at boot,
# so the config cache from build time would be stale).
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Run migrations on every boot. --force skips the "are you sure?" prompt
# that artisan shows when APP_ENV=production.
php artisan migrate --force

# Re-cache after migrations so requests are fast.
php artisan config:cache
php artisan route:cache

# Start PHP-FPM as a background daemon, then nginx in the foreground so
# the container's main process is nginx (PID 1 logs go to fly logs).
php-fpm -D
exec nginx -g 'daemon off;'
