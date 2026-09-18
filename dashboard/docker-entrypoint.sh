#!/bin/sh
# Dashboard service entrypoint: prepare writable dirs, cache config/views,
# ensure an APP_KEY exists, then exec the runtime command.
set -e

if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force 2>/dev/null || true
fi

chmod -R 775 storage bootstrap/cache 2>/dev/null || true

php artisan package:discover 2>/dev/null || true

php artisan config:cache 2>/dev/null || true
php artisan route:cache 2>/dev/null || true
php artisan view:cache 2>/dev/null || true
php artisan event:cache 2>/dev/null || true

exec "$@"