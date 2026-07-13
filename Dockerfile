# syntax=docker/dockerfile:1
# Image for cboxdk/cbox-id — Laravel 13 + Livewire/Volt/Tailwind v4 identity app,
# built FROM the public cbox php-fpm-nginx base image. Built + pushed by
# .github/workflows/build-image.yml on the self-hosted runners.
#
# No build secrets: every composer dependency is public on Packagist (incl.
# cboxdk/laravel-id, laravel-risk, laravel-ssrf, laravel-queue-autoscale), and
# the base image is a public GHCR package. The local dev workspace wires the
# cboxdk/* packages as ../packages/* path repos, but the committed composer.lock
# resolves them to their published Packagist releases, so a clean checkout builds
# with a plain `composer install`.

# ---- build stage: composer + frontend (vite) ----
# Runs on the build host's native arch; vendor/ + public/build are arch-neutral,
# so the runtime image just COPYs them.
FROM --platform=$BUILDPLATFORM ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.5-bookworm AS build
WORKDIR /var/www/html

# PHP deps first for layer caching. --no-scripts: no app env at build time; the
# base-image entrypoint runs package:discover + config/route/event caching at
# container start.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress --optimize-autoloader

# Frontend build (Node 22 ships in the standard tier). Tailwind v4 via the vite
# plugin — a plain `vite build`.
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build && rm -rf node_modules

# ---- runtime image ----
FROM ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.5-bookworm
WORKDIR /var/www/html
COPY --from=build --chown=www-data:www-data /var/www/html /var/www/html

ENV APP_ENV=production \
    PHP_OPCACHE_ENABLE=1
