# syntax=docker/dockerfile:1

# Node for the stages that run Vite. Debian (glibc) images only: the lockfile
# pins linux-x64-gnu native binaries, which don't run on Alpine (musl).
FROM node:26-trixie-slim AS node

# PHP-FPM with the app's extensions and PHP settings. Shared by every PHP stage,
# so the assets build runs on exactly the same PHP as production.
FROM php:8.5-fpm-trixie AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql opcache pcntl bcmath intl gd exif zip

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/app.ini"

WORKDIR /var/www/html

# Production Composer dependencies, installed on the production PHP so platform
# requirements (PHP version, extensions) are checked for real. The autoloader is
# generated later, once the app source is in place.
FROM base AS composer-deps

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# Front-end build. Wayfinder's Vite plugin runs `php artisan wayfinder:generate`,
# so this stage needs PHP and vendor as well as Node.
FROM base AS assets

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

COPY package.json package-lock.json .npmrc ./
RUN npm ci

COPY . .
COPY --from=composer-deps /var/www/html/vendor vendor

# Vite compiles VITE_* variables into the bundle, and the image has no .env.
ARG VITE_APP_NAME
RUN composer dump-autoload --no-dev --no-scripts \
    && npm run build

# Production image: Nginx and PHP-FPM run by Supervisor, listening on 8080.
FROM base AS php

# Nginx comes from nginx.org's stable repository: Debian's 1.26 lacks
# $request_port, which nginx.conf needs to pass the Host header's port to PHP.
RUN curl -fsSL https://nginx.org/keys/nginx_signing.key -o /etc/apt/keyrings/nginx.asc \
    && echo "deb [signed-by=/etc/apt/keyrings/nginx.asc] https://nginx.org/packages/debian trixie nginx" \
        > /etc/apt/sources.list.d/nginx.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends nginx supervisor \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-app.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/nightwatch.conf /etc/supervisor/conf.d/nightwatch.conf
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint.sh

COPY . .
COPY --from=composer-deps /var/www/html/vendor vendor
COPY --from=assets /var/www/html/public/build public/build

# The code stays owned by root; only storage and bootstrap/cache are writable.
RUN composer dump-autoload --optimize --no-dev --no-scripts \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && ln -s ../storage/app/public public/storage

USER www-data

EXPOSE 8080

ENTRYPOINT ["entrypoint.sh"]

# Local development: adds Node. Used by the app and vite services, which mount
# the code over /var/www/html, vendor and node_modules included.
FROM php AS dev

USER root

# Nightwatch runs in production only, so there's no agent to send events to.
RUN rm /etc/supervisor/conf.d/nightwatch.conf

# www-data takes the host user's IDs (as in Laravel Sail), so the containers
# can write to the mounted code and the files they create belong to you.
ARG WWWUSER=1000
ARG WWWGROUP=1000
RUN groupmod -g "$WWWGROUP" www-data \
    && usermod -u "$WWWUSER" -g "$WWWGROUP" www-data

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

ENV COMPOSER_HOME=/tmp/composer \
    NPM_CONFIG_CACHE=/tmp/npm

USER www-data
