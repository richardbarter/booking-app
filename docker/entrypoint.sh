#!/bin/sh
set -e

APP_ENV="${APP_ENV:-production}"

php artisan package:discover --ansi

# Cache at runtime rather than build time, so Fly secrets are available.
# optimize caches config, events, routes and views, as Laravel's deployment
# docs recommend, plus anything a package registers.
if [ "$APP_ENV" != "local" ]; then
    php artisan optimize
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
