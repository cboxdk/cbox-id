#!/bin/sh
# Make the APPLICATION the single owner of security response headers.
#
# The base image's nginx adds its own X-Frame-Options, X-Content-Type-Options,
# Referrer-Policy and Permissions-Policy, and this app sets all four itself in
# App\Http\Middleware\SecurityHeaders. Both fire, so production returned each header
# TWICE with DIFFERENT values:
#
#   x-frame-options:   DENY (app)         + SAMEORIGIN (nginx)
#   referrer-policy:   same-origin (app)  + strict-origin-when-cross-origin (nginx)
#   permissions-policy: two different lists
#
# Per the Referrer Policy spec a user agent takes the LAST valid value, and nginx's
# looser one is sent last — so this identity provider's deliberate `same-origin` was
# silently downgraded to `strict-origin-when-cross-origin` on every response. The
# X-Frame-Options half is defence in depth rather than an open hole (the app's CSP
# carries `frame-ancestors 'none'`, which modern browsers honour over XFO), but two
# owners disagreeing about a security header is a bug in either direction.
#
# The base image documents `NGINX_HEADER_*=` (empty) as the way to drop a header, and
# those are set empty in this deployment — but its entrypoint re-fills empty values
# from `${VAR:=default}`, which POSIX applies when a variable is unset OR NULL. So an
# empty value cannot currently switch these four off (only headers whose default is
# already empty — CSP, COOP, COEP, CORP — can be). Until the base image uses
# `${VAR=default}` there, this strips the generated directives directly.
#
# Runs from /docker-entrypoint-init.d, which the base entrypoint executes AFTER it
# renders /etc/nginx/conf.d/default.conf and BEFORE it starts nginx.
set -eu

CONF="${NGINX_CONF:-/etc/nginx/conf.d/default.conf}"

[ -f "$CONF" ] || exit 0

TMP="$(mktemp)"

# Drop only the four the app owns. Anything else the base image adds (HSTS, COOP/COEP,
# CORP) is left alone — this is about duplicate ownership, not about disabling headers.
grep -v -E '^[[:space:]]*add_header[[:space:]]+(X-Frame-Options|X-Content-Type-Options|Referrer-Policy|Permissions-Policy)[[:space:]]' \
    "$CONF" > "$TMP" || true

# Never truncate the server config: if the filter somehow matched everything, leave
# the generated file untouched and let nginx start with duplicate headers rather than
# with no configuration at all.
[ -s "$TMP" ] || { rm -f "$TMP"; exit 0; }

cat "$TMP" > "$CONF"
rm -f "$TMP"

echo "[cbox-id] nginx security headers removed; App\\Http\\Middleware\\SecurityHeaders is the single owner"
