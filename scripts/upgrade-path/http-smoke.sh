#!/usr/bin/env bash
#
# HTTP smoke test of an upgraded deployment, against a running server.
#
#     scripts/upgrade-path/http-smoke.sh <base-url> <smoke.json>
#
# <base-url> is where the server listens (http://127.0.0.1:8111); hosts are sent as the
# Host header, so nothing needs DNS. <smoke.json> is what verify.php wrote: the keys it
# minted with the new code, the 1.x keys, and a person to sign in as with their 1.x
# password. Assumes the rehearsal shape: console on `id.localhost`, the customer
# environment on `<slug>.id.localhost`.
#
# Exits non-zero on the first failed expectation.

set -euo pipefail

base="${1:?base url}"
smoke="${2:?smoke.json}"
port="${base##*:}"

field() { php -r '$s = json_decode(file_get_contents($argv[1]), true); echo $s[$argv[2]];' "$smoke" "$1"; }

console="id.localhost:${port}"
env_host="$(field environment_slug).id.localhost:${port}"
jar="$(mktemp)"
trap 'rm -f "$jar"' EXIT

failures=0
expect() { # name, expected status, actual status
    if [[ "$2" == "$3" ]]; then
        printf '  ok    %s (%s)\n' "$1" "$3"
    else
        printf '  FAIL  %s: expected %s, got %s\n' "$1" "$2" "$3"
        failures=$((failures + 1))
    fi
}
status() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

expect "console /login renders" 200 "$(status -H "Host: ${console}" "${base}/login")"
expect "environment /login renders" 200 "$(status -H "Host: ${env_host}" "${base}/login")"

discovery="$(curl -s -H "Host: ${env_host}" "${base}/.well-known/openid-configuration")"
issuer="$(php -r '$d = json_decode($argv[1], true); echo is_array($d) ? ($d["issuer"] ?? "") : "";' "$discovery")"
if [[ "$issuer" == *"$(field environment_slug)"* ]]; then
    printf '  ok    OIDC discovery on the environment host (issuer %s)\n' "$issuer"
else
    printf '  FAIL  OIDC discovery on the environment host: issuer "%s"\n' "$issuer"
    failures=$((failures + 1))
fi
jwks_uri="$(php -r '$d = json_decode($argv[1], true); echo $d["jwks_uri"] ?? "";' "$discovery")"
jwks_path="/${jwks_uri#*://*/}"
jwks="$(curl -s -H "Host: ${env_host}" "${base}${jwks_path}")"
if [[ "$jwks" == *"\"$(field signing_kid)\""* ]]; then
    printf '  ok    JWKS on the environment host still publishes the 1.x signing key\n'
else
    printf '  FAIL  JWKS on the environment host does not publish the 1.x signing key\n'
    failures=$((failures + 1))
fi

# Management API: the 1.x environment key and one minted by the new code both work; the
# environment API did not change. The 1.x workspace key (cbid_org_) is refused and a
# new cbid_ws_ one is accepted — the documented clean break.
expect "env API with a new cbid_env_ key" 200 "$(status -H "Host: ${env_host}" -H "Authorization: Bearer $(field environment_key)" -H 'Accept: application/json' "${base}/api/v1/users")"
expect "env API with the 1.x cbid_env_ key" 200 "$(status -H "Host: ${env_host}" -H "Authorization: Bearer $(field old_environment_key)" -H 'Accept: application/json' "${base}/api/v1/users")"
expect "workspace API with a new cbid_ws_ key" 200 "$(status -H "Host: ${console}" -H "Authorization: Bearer $(field workspace_key)" -H 'Accept: application/json' "${base}/api/v1/workspace")"
expect "workspace API refuses the 1.x cbid_org_ key" 401 "$(status -H "Host: ${console}" -H "Authorization: Bearer $(field old_workspace_key)" -H 'Accept: application/json' "${base}/api/v1/workspace")"

# Sign in on the environment host with a 1.x password, the way a browser does: the
# session and XSRF cookies from the form, then the POST.
curl -s -o /dev/null -c "$jar" -b "$jar" -H "Host: ${env_host}" "${base}/login"
xsrf="$(php -r '
    foreach (file($argv[1]) as $line) {
        $cols = explode("\t", trim($line));
        if (count($cols) >= 7 && $cols[5] === "XSRF-TOKEN") { echo urldecode($cols[6]); }
    }' "$jar")"
location="$(curl -s -o /dev/null -w '%{redirect_url}' -c "$jar" -b "$jar" -H "Host: ${env_host}" \
    -H "X-XSRF-TOKEN: ${xsrf}" -H 'Accept: text/html' \
    --data-urlencode "email=$(field login_email)" --data-urlencode "password=$(field password)" \
    "${base}/login")"
if [[ -n "$location" && "$location" != *"/login"* ]]; then
    printf '  ok    sign in with a 1.x password (redirected to %s)\n' "${location#*://*/}"
else
    printf '  FAIL  sign in with a 1.x password: redirected to "%s"\n' "$location"
    failures=$((failures + 1))
fi
expect "signed-in page loads with that session" 200 "$(status -b "$jar" -H "Host: ${env_host}" "${base}/${location#*://*/}")"

if [[ $failures -gt 0 ]]; then
    echo "http smoke: ${failures} failed"
    exit 1
fi
echo "http smoke: OK"
