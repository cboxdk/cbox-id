# syntax=docker/dockerfile:1
# Image for cboxdk/cbox-id — the Laravel 13 + Inertia/React/Tailwind v4 identity app on
# PHP 8.5, built FROM the public cbox php-fpm-nginx base image (8.5-bookworm-v1, the same
# tag the local cbox.yaml runs and CI tests in). Built and pushed to ghcr.io/cboxdk/cbox-id
# by .github/workflows/build-image.yml on GitHub-hosted runners (linux/amd64 and
# linux/arm64, each native), for pushes to main and `v*` tags. Production (cboxid.com, on
# arm64) runs this image: each main commit whose checks pass is released there.
#
# The reviewed Signals client is bundled with its commit and archive checksum.
# Composer mirrors this verified source; builds never resolve a sibling checkout.

# ---- build stage: composer + frontend (vite) ----
# Runs on the build host's native arch; vendor/ + public/build are arch-neutral,
# so the runtime image just COPYs them.
FROM --platform=$BUILDPLATFORM ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.5-bookworm-v1 AS build
WORKDIR /var/www/html

# PHP deps first for layer caching. --no-scripts: no app env at build time; the
# base-image entrypoint runs package:discover + config/route/event caching at
# container start.
COPY packages/signals-client ./packages/signals-client
COPY build/dependencies/laravel-signals-client.tar build/dependencies/laravel-signals-client.json ./build/dependencies/
COPY bin/verify-signals-sdk.php ./bin/verify-signals-sdk.php
COPY composer.json composer.lock ./
RUN php bin/verify-signals-sdk.php && composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress --optimize-autoloader

# Frontend build (Node 22 ships in the standard tier). Tailwind v4 via the vite
# plugin — a plain `vite build`.
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build && rm -rf node_modules

# ---- runtime image ----
# Pin and verify the released process supervisor and metrics exporter.
FROM build AS runtime-tools
ARG TARGETARCH
COPY docker-runtime-tools.sh /tmp/download-runtime-tools.sh
RUN sh /tmp/download-runtime-tools.sh "$TARGETARCH" /tmp/cbox-runtime-tools

FROM ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.5-bookworm-v1@sha256:0a34ef64c5f1c48cd0ca8999c0c395b683f9d4c9b1835b35ad1b712ef142a3b8
COPY --from=runtime-tools --chmod=0755 /tmp/cbox-runtime-tools/cbox-init /usr/local/bin/cbox-init
COPY --from=runtime-tools --chmod=0755 /tmp/cbox-runtime-tools/fpm-exporter /usr/local/bin/fpm-exporter
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

# The release this image is (config `app.version`): passed by build-image.yml from
# `git describe --tags`. Last, so a new version re-uses every layer above it.
ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION}
