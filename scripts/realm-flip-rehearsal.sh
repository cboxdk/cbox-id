#!/usr/bin/env bash
#
# Rehearse the realm flip (`php artisan cbox-id:realm:flip`) on a throwaway PostgreSQL 18.
#
#   scripts/realm-flip-rehearsal.sh --dump /path/to/cbox-id.dump     # a copy of production
#   scripts/realm-flip-rehearsal.sh --seed                           # the seeded fixture
#
# What it does, in order:
#
#   1. Starts a `postgres:18` container on a free port (never an existing database).
#   2. --dump: restores the dump (pg_dump custom/directory format through pg_restore, or
#      plain SQL, optionally gzipped, through psql).
#      --seed: migrates an empty database and seeds scripts/realm-flip/seed-fixture.php.
#   3. `php artisan migrate --force` with THIS checkout (adds the realm_flip_* tables).
#   4. The dry run. It must not be blocked, and it must change nothing: row counts of
#      every table are compared before and after.
#   5. `--execute --confirm=<the root id the dry run named>`.
#   6. `--verify`, then `audit-chain:verify` for every chain that is left.
#   7. A second `--execute`, which must say there is nothing to do.
#   8. Prints a summary and removes the container (`--keep` leaves it, with its port).
#
# The dump is production data: keep it on an encrypted disk, and this script never copies
# it anywhere but into the container it removes. Nothing here talks to production.
#
# Options:
#   --dump <file>        a dump of the Cbox ID database
#   --seed               use the seeded fixture instead of a dump
#   --flip "<options>"   extra options for the flip, e.g. "--create-missing --domain=login.cboxid.com"
#   --keep               leave the container running
#   --out <dir>          where the logs go (default: a new temp directory)
#
# Environment: APP_KEY, CBOX_ID_CRYPTO_KEY and CBOX_ID_* are taken from the caller's
# environment when set, and made up when not. The flip itself decrypts nothing, so a
# rehearsal without production's keys is a true rehearsal of the flip. `audit-chain:verify`
# checks hashes everywhere; if it reports a checkpoint signature it cannot check on a
# restored dump, run the rehearsal with production's CBOX_ID_CRYPTO_KEY before reading
# anything into it.
#
# Needs php, docker, and a checkout whose vendor/ is installed. Exits non-zero on the
# first failed stage.

set -euo pipefail

dump=""
seed=0
flip_options=""
keep=0
out=""

while [ $# -gt 0 ]; do
    case "$1" in
        --dump) dump="$2"; shift 2 ;;
        --seed) seed=1; shift ;;
        --flip) flip_options="$2"; shift 2 ;;
        --keep) keep=1; shift ;;
        --out) out="$2"; shift 2 ;;
        -h|--help) sed -n '2,40p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done

if [ "$seed" -eq 0 ] && [ -z "$dump" ]; then
    echo "Pass --dump <file> or --seed." >&2
    exit 2
fi

if [ -n "$dump" ] && [ ! -e "$dump" ]; then
    echo "No such dump: $dump" >&2
    exit 2
fi

root="$(cd "$(dirname "$0")/.." && pwd)"
out="${out:-$(mktemp -d "${TMPDIR:-/tmp}/realm-flip-rehearsal.XXXXXX")}"
mkdir -p "$out"
container="realm-flip-rehearsal-$$"
database="cbox_id_rehearsal"
password="rehearsal"

cleanup() {
    if [ "$keep" -eq 1 ]; then
        echo "Kept: container $container (port ${port:-?}, database $database, password $password). Logs: $out"
    else
        docker rm -f "$container" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

stage() { printf '\n== %s\n' "$1"; }
fail() { echo "FAILED: $1 (logs: $out)" >&2; exit 1; }

stage "PostgreSQL 18"
docker run -d --name "$container" -e POSTGRES_PASSWORD="$password" -e POSTGRES_DB="$database" -p 127.0.0.1::5432 postgres:18 >/dev/null
port="$(docker port "$container" 5432/tcp | head -1 | sed 's/.*://')"

ready=0
for _ in $(seq 1 120); do
    if docker exec "$container" psql -h 127.0.0.1 -U postgres -d "$database" -Atc 'select 1' >/dev/null 2>&1; then ready=1; break; fi
    sleep 1
done
[ "$ready" -eq 1 ] || fail "PostgreSQL did not start"
echo "container $container on 127.0.0.1:$port"

sql() { docker exec -i "$container" psql -h 127.0.0.1 -U postgres -d "$database" -v ON_ERROR_STOP=1 -At "$@"; }

export APP_ENV="${APP_ENV:-local}"
export APP_KEY="${APP_KEY:-base64:$(head -c 32 /dev/urandom | base64)}"
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT="$port" DB_DATABASE="$database" DB_USERNAME=postgres DB_PASSWORD="$password" DB_URL=
export CBOX_ID_CRYPTO_KEY="${CBOX_ID_CRYPTO_KEY:-$(head -c 32 /dev/urandom | base64)}"
export CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array MAIL_MAILER=array LOG_CHANNEL=stderr
export TELEMETRY_ENABLED=false CBOX_ID_MULTI_TENANT="${CBOX_ID_MULTI_TENANT:-true}"

artisan() { (cd "$root" && php artisan "$@"); }

# Row counts of every table, to prove the dry run changed nothing.
counts() {
    sql -F ' ' -c "select table_name, (xpath('/row/c/text()', query_to_xml(format('select count(*) as c from %I', table_name), false, true, '')))[1]::text from information_schema.tables where table_schema = current_schema() and table_type = 'BASE TABLE' order by table_name collate \"C\""
}

if [ "$seed" -eq 1 ]; then
    stage "Migrate and seed the fixture"
    artisan migrate --force >"$out/migrate.log" 2>&1 || fail "migrate"
    (cd "$root" && php scripts/realm-flip/seed-fixture.php) >"$out/seed.json" 2>"$out/seed.log" || fail "seed"
    [ "$(sql -c 'select count(*) from organizations')" -gt 0 ] || fail "seed (nothing was written; see seed.log)"
else
    stage "Restore $dump"
    case "$dump" in
        *.sql) sql <"$dump" >"$out/restore.log" 2>&1 || fail "restore" ;;
        *.sql.gz) gunzip -c "$dump" | sql >"$out/restore.log" 2>&1 || fail "restore" ;;
        *)
            if [ -d "$dump" ]; then
                docker cp "$dump" "$container:/tmp/dump" >/dev/null
                docker exec "$container" pg_restore -h 127.0.0.1 -U postgres -d "$database" --no-owner --no-acl /tmp/dump >"$out/restore.log" 2>&1 || fail "restore"
            else
                docker exec -i "$container" pg_restore -h 127.0.0.1 -U postgres -d "$database" --no-owner --no-acl <"$dump" >"$out/restore.log" 2>&1 || fail "restore"
            fi
            ;;
    esac

    stage "Migrate (this checkout)"
    artisan migrate --force >"$out/migrate.log" 2>&1 || fail "migrate"
fi

root_id="$(sql -c "select id from environments where is_default = true")"
realm_slug="${CBOX_ID_CBOX_ENVIRONMENT:-cbox}"
echo "platform root: $root_id; realm: $(sql -c "select id from environments where slug = '$realm_slug'") ($realm_slug)"
echo "environments: $(sql -c 'select count(*) from environments'), organizations: $(sql -c 'select count(*) from organizations'), users: $(sql -c 'select count(*) from users'), projects: $(sql -c 'select count(*) from projects')"

stage "Dry run"
counts >"$out/counts-before.txt"
# shellcheck disable=SC2086
artisan cbox-id:realm:flip --dry-run --tables $flip_options >"$out/dry-run.log" 2>&1 || { cat "$out/dry-run.log"; fail "the dry run is blocked"; }
counts >"$out/counts-after-dry-run.txt"
diff "$out/counts-before.txt" "$out/counts-after-dry-run.txt" >"$out/dry-run.diff" || fail "the dry run changed row counts (see dry-run.diff)"
cat "$out/dry-run.log"

stage "Execute"
# shellcheck disable=SC2086
artisan cbox-id:realm:flip --execute --confirm="$root_id" $flip_options >"$out/execute.log" 2>&1 || { tail -60 "$out/execute.log"; fail "execute"; }
tail -3 "$out/execute.log"
counts >"$out/counts-after.txt"

stage "Verify"
artisan cbox-id:realm:flip --verify >"$out/verify.log" 2>&1 || { cat "$out/verify.log"; fail "verify"; }
cat "$out/verify.log"

stage "Audit chains"
artisan audit-chain:verify >"$out/audit-chain.log" 2>&1 || { cat "$out/audit-chain.log"; fail "audit-chain:verify"; }
tail -5 "$out/audit-chain.log"

stage "Run it again"
artisan cbox-id:realm:flip --execute --confirm="$root_id" >"$out/again.log" 2>&1 || fail "the second run failed"
grep -q "Nothing to do" "$out/again.log" || fail "the second run did not say there was nothing to do"
counts >"$out/counts-after-again.txt"
diff "$out/counts-after.txt" "$out/counts-after-again.txt" >/dev/null || fail "the second run changed row counts"
echo "nothing to do, nothing changed"

stage "Summary"
echo "old root $root_id retired; platform root is now $(sql -c "select id || ' (' || slug || ', domain ' || coalesce(domain, 'none') || ')' from environments where is_default = true")"
echo "id map:"
sql -c "select '  ' || kind || ' ' || old_id || ' -> ' || coalesce(new_id, '(dropped)') || ' [' || coalesce(note, '') || ']' from realm_flip_ids order by kind, old_id"
echo "tables whose row count changed:"
LC_ALL=C join "$out/counts-before.txt" "$out/counts-after.txt" | awk '$2 != $3 { printf "  %-40s %8s -> %s\n", $1, $2, $3 }'
echo "archived audit rows: $(sql -c 'select count(*) from realm_flip_archive')"
echo "REHEARSAL PASSED. Logs: $out"
