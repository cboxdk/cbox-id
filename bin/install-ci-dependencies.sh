#!/bin/sh
# Keep the partial Composer cache between bounded retries of upstream downloads.
# Every install/audit/test still fails closed if dependencies cannot be installed.
set -u
export COMPOSER_MAX_PARALLEL_HTTP=4
attempt=1
while :; do
    if composer install "$@"; then
        exit 0
    else
        result=$?
    fi
    if [ "$attempt" -ge 3 ]; then
        exit "$result"
    fi
    attempt=$((attempt + 1))
    sleep 5
done
