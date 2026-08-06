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

# The app owns its security response headers (App\Http\Middleware\SecurityHeaders).
# The base image's nginx adds its own X-Frame-Options / X-Content-Type-Options /
# Referrer-Policy / Permissions-Policy on top, so production returned each one twice
# with CONFLICTING values — and since a user agent takes the LAST Referrer-Policy, the
# app's deliberate `same-origin` was being downgraded to nginx's looser
# `strict-origin-when-cross-origin` on an identity provider.
#
# Blanking these is the documented way to hand ownership to the app. Today it is not
# yet sufficient on its own: the base entrypoint re-fills empty values via
# `${VAR:=default}`, which POSIX also applies to a set-but-empty variable — so the init
# script below is what actually enforces single ownership. Both are here on purpose:
# the env vars declare the intent (and become the whole fix once the base image
# switches to `${VAR=default}`), the script makes it true now.
COPY docker/entrypoint-init/10-app-owns-security-headers.sh /docker-entrypoint-init.d/10-app-owns-security-headers.sh
RUN chmod +x /docker-entrypoint-init.d/10-app-owns-security-headers.sh

ENV APP_ENV=production \
    PHP_OPCACHE_ENABLE=1 \
    NGINX_HEADER_X_FRAME_OPTIONS="" \
    NGINX_HEADER_X_CONTENT_TYPE_OPTIONS="" \
    NGINX_HEADER_REFERRER_POLICY="" \
    NGINX_HEADER_PERMISSIONS_POLICY=""
