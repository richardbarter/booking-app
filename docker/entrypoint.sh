#!/bin/sh
set -e

APP_ENV="${APP_ENV:-production}"

php artisan package:discover --ansi

# Cache at runtime rather than build time, so Fly secrets are available.
if [ "$APP_ENV" != "local" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

# A command was passed (e.g. Fly's release_command, or the vite service):
# run it instead of the web server.
if [ "$#" -gt 0 ]; then
    exec "$@"
fi

if [ ! -e public/storage ]; then
    php artisan storage:link
fi

if [ "$APP_ENV" = "local" ]; then
    php artisan migrate --force
fi

exec supervisord -c /etc/supervisor/supervisord.conf
