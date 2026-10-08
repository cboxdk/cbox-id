#!/usr/bin/env bash
#
# Rehearse an upgrade from a previous release to this checkout, on a real database engine.
#
#   scripts/upgrade-path-check.sh --from v1.1.1 --engine mysql
#   scripts/upgrade-path-check.sh --from v1.1.1 --engine pgsql
#
# What it does, in order:
#
#   1. Checks the previous release out of git (`git archive <tag>`) into a scratch
#      directory and runs `composer install` there.
#   2. Starts a throwaway database container (mysql:8.4 or postgres:18), or uses the one
#      DB_* already points at with --external-db (CI service containers).
#   3. With the OLD code: `migrate`, `cbox-id:install --multi-tenant`, then
#      scripts/upgrade-path/seed-v1.php — people with passwords, TOTP and a passkey, apps
#      with secrets, sessions and refresh tokens, webhooks, SSO connections, a SCIM
#      directory, a vault secret, management keys (including the retired `cbid_org_`
#      workspace keys), admin portal links, audit entries. Every write goes through that
#      release's own services, so hashes and sealed columns are what it really writes.
#   4. Row counts and the schema are recorded.
#   5. With THIS checkout's code: `migrate --force`, then
#        - row counts compared (scripts/upgrade-path/compare-counts.php),
#        - scripts/upgrade-path/verify.php: every 1.x password, TOTP secret, recovery
#          code, client secret, refresh token, session, webhook secret, SSO configuration,
#          SCIM token, vault secret, personal token and environment key still works; the
#          `cbid_org_` keys are revoked as UPGRADING.md says; the audit chains verify,
#        - `audit-chain:verify` and `cbox-id:doctor`,
#        - an HTTP smoke test against `php artisan serve` (scripts/upgrade-path/http-smoke.sh):
#          /login, OIDC discovery and JWKS on the environment host, the management API with
#          old and new keys, and a browser-style sign-in with a 1.x password.
#   6. `migrate:rollback --step=<every migration the upgrade ran>`, then the schema is
#      compared with step 4's — it must be identical — and `migrate` runs again.
#
# SEEDED DATA ONLY. This proves the migrations and the credential formats against data the
# old release wrote itself; it is not a copy of anyone's production. Rehearse against a
# restored copy of your own database as well — docs/operations/upgrade-rehearsal.md.
#
# Options:
#   --from <tag>          previous release to upgrade from (default: v1.1.1)
#   --from-dir <path>     an already-installed checkout of it instead (skips git + composer)
#   --engine mysql|pgsql  database engine (default: mysql)
#   --external-db         use the database DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/
#                         DB_PASSWORD point at (must be empty) instead of starting a container
#   --workdir <path>      scratch directory (default: a new temp directory)
#   --keep                leave the container and scratch directory behind
#
# Needs php, composer, git and (without --external-db) docker. Run it from a checkout
# whose vendor/ is installed. Exits non-zero on the first failed stage.

set -euo pipefail

from="v1.1.1"
from_dir=""
engine="mysql"
external_db=0
workdir=""
keep=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --from) from="$2"; shift 2 ;;
        --from-dir) from_dir="$2"; shift 2 ;;
        --engine) engine="$2"; shift 2 ;;
        --external-db) external_db=1; shift ;;
        --workdir) workdir="$2"; shift 2 ;;
        --keep) keep=1; shift ;;
        -h|--help) sed -n '2,52p' "$0"; exit 0 ;;
        *) echo "unknown option: $1" >&2; exit 2 ;;
    esac
done

case "$engine" in mysql|pgsql) ;; *) echo "--engine must be mysql or pgsql" >&2; exit 2 ;; esac

repo="$(cd "$(dirname "$0")/.." && pwd)"
tools="${repo}/scripts/upgrade-path"
workdir="${workdir:-$(mktemp -d "${TMPDIR:-/tmp}/cbox-id-upgrade.XXXXXX")}"
mkdir -p "$workdir"
container=""
server_pid=""

stage() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
fail() { printf '\n\033[31mUPGRADE PATH CHECK FAILED: %s\033[0m\n' "$*" >&2; exit 1; }

cleanup() {
    [[ -n "$server_pid" ]] && kill "$server_pid" 2>/dev/null || true
    if [[ $keep -eq 0 ]]; then
        [[ -n "$container" ]] && docker rm -f "$container" >/dev/null 2>&1 || true
        rm -rf "$workdir"
    else
        echo "kept: workdir ${workdir}${container:+, container ${container}}"
    fi
}
trap cleanup EXIT

free_port() { php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo substr(strrchr(stream_socket_get_name($s, false), ":"), 1);'; }

# --- The previous release ---------------------------------------------------------------

if [[ -z "$from_dir" ]]; then
    stage "Checking out ${from}"
    from_dir="${workdir}/previous"
    mkdir -p "$from_dir"
    git -C "$repo" archive "$from" | tar -x -C "$from_dir"
    composer install --working-dir="$from_dir" --no-interaction --prefer-dist --no-progress --quiet
fi

case "$from" in
    v1.*) seed="${tools}/seed-v1.php" ;;
    *) fail "no seed script for ${from}: add scripts/upgrade-path/seed-<major>.php written against that release" ;;
esac

# --- The database -----------------------------------------------------------------------

if [[ $external_db -eq 0 ]]; then
    stage "Starting ${engine}"
    port="$(free_port)"
    container="cbox-id-upgrade-$$"
    if [[ "$engine" == "mysql" ]]; then
        docker run -d --name "$container" -p "127.0.0.1:${port}:3306" \
            -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=cbox_id -e MYSQL_USER=cbox_id -e MYSQL_PASSWORD=secret \
            mysql:8.4 >/dev/null || fail "could not start mysql:8.4"
        ready() { docker exec "$container" mysql -ucbox_id -psecret -h127.0.0.1 -e 'select 1' cbox_id >/dev/null 2>&1; }
    else
        docker run -d --name "$container" -p "127.0.0.1:${port}:5432" \
            -e POSTGRES_DB=cbox_id -e POSTGRES_USER=cbox_id -e POSTGRES_PASSWORD=secret \
            postgres:18 >/dev/null || fail "could not start postgres:18"
        ready() { docker exec "$container" psql -U cbox_id -h 127.0.0.1 -d cbox_id -c 'select 1' >/dev/null 2>&1; }
    fi
    for _ in $(seq 1 90); do ready && break; sleep 2; done
    ready || fail "${engine} did not come up"
    export DB_HOST=127.0.0.1 DB_PORT="$port" DB_DATABASE=cbox_id DB_USERNAME=cbox_id DB_PASSWORD=secret
fi
export DB_CONNECTION="$engine"
: "${DB_HOST:?}" "${DB_PORT:?}" "${DB_DATABASE:?}" "${DB_USERNAME:?}"

# --- One environment for both releases ----------------------------------------------------
#
# The SAME keys on both sides is the point: an upgrade keeps APP_KEY and the crypto master
# key, and every sealed column is only readable if it does. Variables in the process
# environment win over either checkout's .env (Laravel's dotenv never overwrites them).

http_port="$(free_port)"
export APP_ENV=local APP_DEBUG=true LOG_CHANNEL=single MAIL_MAILER=log
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
export CBOX_ID_CRYPTO_KEY="$(php -r 'echo base64_encode(random_bytes(32));')"
export APP_URL="http://id.localhost:${http_port}" CBOX_ID_ISSUER="http://id.localhost:${http_port}"
export CBOX_ID_MULTI_TENANT=true CBOX_ID_CONSOLE_HOST=id.localhost CBOX_ID_ENVIRONMENT_BASE_DOMAINS=id.localhost
export CBOX_ID_WEBAUTHN_RP_ID=id.localhost CBOX_ID_WEBAUTHN_ORIGIN="http://id.localhost:${http_port}"
export SESSION_DRIVER=database CACHE_STORE=database QUEUE_CONNECTION=database
# The seeded webhook endpoints are at hosts that do not resolve.
export CBOX_ID_WEBHOOKS_VERIFY_URL=false

old() { (cd "$from_dir" && "$@"); }
new() { (cd "$repo" && "$@"); }

# --- Build the old deployment -------------------------------------------------------------

stage "${from}: migrate, install, seed"
old php artisan migrate --force --no-interaction >/dev/null || fail "${from} migrations"
old php artisan cbox-id:install --no-interaction --multi-tenant --console-host=id.localhost \
    --email=operator@upgrade.test --name="Upgrade Operator" --password='Operator-Passw0rd!' \
    --environment=Production --organization="Acme Workspace" --issuer="$APP_URL" >/dev/null || fail "${from} install"
old php "$seed" "${workdir}/manifest.json" || fail "seeding ${from}"

php "${tools}/row-counts.php" > "${workdir}/counts-before.json"
php "${tools}/schema-dump.php" > "${workdir}/schema-before.json"
migrations_before="$(php -r '$c = json_decode(file_get_contents($argv[1]), true); echo $c["migrations"];' "${workdir}/counts-before.json")"

# --- Upgrade ------------------------------------------------------------------------------

stage "Upgrade: migrate --force with this checkout"
new php artisan migrate --force --no-interaction || fail "migrations"

php "${tools}/row-counts.php" > "${workdir}/counts-after.json"
migrations_after="$(php -r '$c = json_decode(file_get_contents($argv[1]), true); echo $c["migrations"];' "${workdir}/counts-after.json")"
added=$((migrations_after - migrations_before))

stage "Row counts (${from} -> this checkout, ${added} migrations)"
php "${tools}/compare-counts.php" "${workdir}/counts-before.json" "${workdir}/counts-after.json" || fail "row counts"

stage "Verify ${from} data with the new code"
new php "${tools}/verify.php" "${workdir}/manifest.json" "${workdir}/smoke.json" || fail "verification"

stage "audit-chain:verify"
new php artisan audit-chain:verify || fail "audit chain"

stage "cbox-id:doctor (informational: no scheduler or queue manager runs here)"
new php artisan cbox-id:doctor || true

stage "HTTP smoke"
(cd "$repo" && exec php artisan serve --host=127.0.0.1 --port="$http_port" --no-reload) > "${workdir}/serve.log" 2>&1 &
server_pid=$!
for _ in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:${http_port}/up" && break; sleep 1; done
bash "${tools}/http-smoke.sh" "http://127.0.0.1:${http_port}" "${workdir}/smoke.json" || { tail -40 "${workdir}/serve.log"; fail "HTTP smoke"; }
kill "$server_pid" 2>/dev/null || true
server_pid=""

# --- Roll back ----------------------------------------------------------------------------

stage "Rollback: migrate:rollback --step=${added}"
new php artisan migrate:rollback --step="$added" --force --no-interaction || fail "rollback"
php "${tools}/schema-dump.php" > "${workdir}/schema-rolled-back.json"
if ! diff -u "${workdir}/schema-before.json" "${workdir}/schema-rolled-back.json"; then
    fail "the rolled-back schema is not ${from}'s"
fi
echo "schema after rollback is identical to ${from}'s"

stage "Re-apply after rollback"
new php artisan migrate --force --no-interaction >/dev/null || fail "migrate after rollback"
echo "migrate after rollback: OK"

printf '\n\033[32mUPGRADE PATH CHECK PASSED: %s -> this checkout on %s\033[0m\n' "$from" "$engine"
