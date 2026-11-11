# Infra change request: the API host `api.cboxid.com` (ID v3, step 5a)

**For:** the operator of `cboxdk/infra` (the only infrastructure writer, from the operator's
workstation). **From:** the Cbox ID v3 track, step 5a. **Status:** requested, not applied.
Nothing in this request has been changed in `cboxdk/infra` by this track.

**TL;DR.** Cbox ID v3 serves its management API at `https://api.cboxid.com/v1`, next to the
old `/api/v1`. The wildcard DNS record and the wildcard tunnel rule already route the name,
so the only change the API needs is one environment variable, `CBOX_ID_API_HOST`. Items 1
and 2 make the routing explicit so a later wildcard change cannot drop the API.

Sylvester approved "the api.cboxid.com infra change" on 2026-11-11 (Cbox Brain
`mem_Q4Po8pwFXC8flrRyIrgblT`). That approves preparing it. Rolling the pods and
publishing the hostname still follow the usual rule: the operator says go.

## What changes for whom

| Who | Before | After |
|---|---|---|
| `api.cboxid.com` | Routed by the wildcard to the `cbox-id` pods, which answer 404 (no environment has the slug `api`) | `/v1/*`, `/openapi.json`, `/docs`, `/problems/*`, `/.well-known/oauth-protected-resource`, and the old `/api/v1/*`. No session, no cookie, no page |
| `app.cboxid.com`, `cbox.cboxid.com`, other tenant hosts | Unchanged | Unchanged. `/api/v1/*` keeps answering, now with `Deprecation` and `Sunset` headers |
| `cbox` CLI, Authenticator | `/.well-known/cbox-cli` and `/.well-known/cbox-authenticator` advertise `{issuer}/api/v1` | They advertise `https://api.cboxid.com/v1`. The Authenticator keeps calling the tenant-host `/api/v1/devices` and `/api/v1/approvals*` until a new app version ships |

If `CBOX_ID_API_HOST` is not set, production advertises nothing new. The CLI and the
Authenticator keep the old base, and `/v1` answers on the existing hosts. So a deploy that
lands before the variable does no harm. The API host just stays dark until the variable
is set and the pods roll.

## The change

### 1. DNS (Cloudflare zone `cboxid.com`)

Add two **proxied** CNAME records pointing at the `cbox-hel1` tunnel, the same target the
`*.cboxid.com` record has today (handoff, the 2026-10-08 cut-over; record ids in
`artifacts/cboxid-dns-before-cutover.json`):

| Name | Type | Target | Proxied |
|---|---|---|---|
| `api.cboxid.com` | CNAME | `<cbox-hel1 tunnel id>.cfargotunnel.com` | yes |
| `app.cboxid.com` | CNAME | `<cbox-hel1 tunnel id>.cfargotunnel.com` | yes |

The wildcard already resolves both names, so this does not change where traffic goes
today. Cloudflare Universal SSL already covers first-level `*.cboxid.com`, so no
certificate work is needed.

### 2. Tunnel ingress (`infra/cloudflare/tunnel-cbox-hel1.json`)

Add explicit rules **before** the `*.cboxid.com` rule:

```json
{ "hostname": "api.cboxid.com", "service": "http://cbox-id.cbox-id.svc.cluster.local:80" },
{ "hostname": "app.cboxid.com", "service": "http://cbox-id.cbox-id.svc.cluster.local:80" },
```

The service is the same one the `cboxid.com` and `*.cboxid.com` rules use. Order matters:
cloudflared uses the first match.

### 3. Environment (`infra/sites/cbox-id.json` → `overrides`)

```json
"CBOX_ID_API_HOST": "api.cboxid.com"
```

Keep `CBOX_ID_CONSOLE_HOST=app.cboxid.com` and every other override as it is. Then:

```bash
python3 scripts/website.py cbox-id install    # applies cbox-id-env and rolls web + worker
```

`env` alone only applies the objects. The pods keep the old environment until they roll.

New optional variables, which need no override because the defaults are right:

| Variable | Default | Meaning |
|---|---|---|
| `CBOX_ID_API_V1_DEPRECATED_SINCE` | `2026-11-11` | The date in the `Deprecation` header on `/api/v1` |
| `CBOX_ID_API_V1_SUNSET` | `2027-02-01` | The `Sunset` header. The earliest removal, not a promise to remove |
| `CBOX_ID_LEGACY_MCP_AUDIENCE` | `true` | Accept tokens audienced to `{issuer}/mcp` on the API host. Set it to `false` only in step 5c |

### 4. Later, before step 5b (not needed for 5a)

- `CBOX_INTERNAL_ROUTES` for the apps that call ID: add
  `api.cboxid.com=http://cbox-id.cbox-id.svc.cluster.local` next to the existing
  `cbox.cboxid.com=…` entry.
- `infra/sites/cbox-engage.json`: `ID_ONBOARDING_ELIGIBILITY_URL` →
  `http://cbox-id.cbox-id.svc.cluster.local/internal/v1/onboarding/eligibility`, sent with
  `Host: app.cboxid.com`, together with Engage's PR.
- Blocking `/internal/` at the tunnel ships with step 5c, not before. Engage must call the
  in-cluster URL first.

## Before applying

1. Read `docs/operations/handoff.md` in `cboxdk/infra`. The cluster changes often, and
   the handoff wins.
2. Check that no environment already uses a slug the platform's hosts need. The 5a
   release has a migration that **fails the release** if one does:

   ```sql
   select id, slug from environments
   where is_default = false
     and slug in ('api','app','www','admin','mail','status','docs','help','support',
                  'cdn','static','assets','auth','id','internal');
   ```

   Expect no rows. If there are rows, stop and tell the ID track. Do not rename by hand.

## Order

1. Items 1–3 above. Items 1 and 2 change nothing that is visible. Item 3 takes effect when
   the pods roll.
2. Merge step 5a to `main`. That deploys production.
3. Verify (below), then record it in the handoff.

Applying items 1–3 after the merge also works (see "What changes for whom"). Until then,
the API host stays dark.

## Verify

```bash
# The API host answers, with no cookie.
curl -sI https://api.cboxid.com/openapi.json | grep -iE '^(HTTP|content-type|set-cookie)'
#   HTTP/2 200, content-type: application/json, no set-cookie

# A failure is an RFC 9457 problem.
curl -s https://api.cboxid.com/v1/organizations -H 'Accept: application/json'
#   401, {"type":"https://api.cboxid.com/problems/unauthorized", ... }

# Nothing else is served there.
curl -sI https://api.cboxid.com/login | head -1            # 404

# The resource is described (RFC 9728).
curl -s https://api.cboxid.com/.well-known/oauth-protected-resource

# The CLI is handed the new base.
curl -s https://app.cboxid.com/.well-known/cbox-cli          # "api_base":"https://api.cboxid.com/v1"

# The old path still answers, marked deprecated.
curl -sI https://cbox.cboxid.com/api/v1/organizations | grep -iE '^(deprecation|sunset)'
```

Watch `cbox_id_legacy_api_requests_total{caller_kind,caller,route}` in Cbox Telemetry. That
counter decides when each old path can go: after seven days with zero traffic, and for the
Authenticator's paths only after a new app version has shipped.

## Rollback

Remove `CBOX_ID_API_HOST` from the overrides and run `install`. The CLI and the
Authenticator go back to the old base, and nothing else depends on the variable. The DNS
records and tunnel rules can stay, because they route the same as the wildcard.
