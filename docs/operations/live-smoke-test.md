---
title: Live smoke test
weight: 6
description: A reproducible end-to-end run against a live deployment with the real clients — the cbox CLI, an MCP client and both SDKs — from sign-in to an app, an enterprise organization with SSO, a domain, an Admin Portal link and a webhook.
---

# Live smoke test

This is the release check for the management plane: a person signs a real client in,
and an app with redirects, an enterprise organization with SSO, a claimed domain, an
Admin Portal link and a webhook get set up without anybody touching the console except
to approve. It uses the released clients, not test doubles:

| Client | Version used | What it proves |
|---|---|---|
| `cbox` CLI (PHAR) | 0.1.0 | Device sign-in at the platform root, `cbox id use`, the action catalogue as commands, approvals |
| MCP Inspector CLI | `@modelcontextprotocol/inspector@2.8.0` | `/mcp` on the root (a person's token) and on an environment host (a management key), a tool held for approval and finished with `approval_id` |
| JS SDK | `@cboxdk/id-js@0.18.0` | `EnvironmentClient` with a key: list, create with `Idempotency-Key`, replay |
| Python SDK | `cbox-id-client==0.10.0` | The same, plus one root token driving an environment with `environment=` |

It was first run on 2026-10-08 against v2.0.0 on a laptop (section 1). Every step below
shows the command and what came back. Against staging, skip section 1 and set the
variables in section 2 to staging's hosts.

## 1. A production-shaped deployment on one machine

What runs, and why each piece:

| Piece | How | Why |
|---|---|---|
| App | `php artisan serve` on `:8000`, `PHP_CLI_SERVER_WORKERS=8` | The CLI polls while the browser approves, so it needs more than one worker |
| TLS | Caddy (`tls internal`) on `:443` for `id.localhost` and `*.id.localhost` | Environment issuers are always `https://{slug}.{base}` with no port, and the CLI refuses `http` for anything but loopback. Real TLS is the only way the issuers match |
| Database | Postgres 17 (Docker) | What production runs (`cbox.yaml`) |
| Cache, sessions, queue | Valkey 8 with `noeviction` (Docker) | As production |
| Mail | Mailpit (Docker) | Real SMTP; invitations and portal links are readable at `http://127.0.0.1:8025` |
| Queue | `php artisan queue:autoscale` | Without it webhooks and mail never leave |
| Scheduler | `php artisan schedule:work` | Without it the event relay never runs |

`*.localhost` resolves to the loopback address on macOS and most Linux resolvers, so no
`/etc/hosts` edits are needed.

`compose.yml` (in a scratch directory, next to the Caddyfile):

```yaml
name: cboxid-live
services:
  postgres:
    image: postgres:17-alpine
    environment: { POSTGRES_DB: cbox_id, POSTGRES_USER: cbox_id, POSTGRES_PASSWORD: secret }
    ports: ["127.0.0.1:55433:5432"]
  valkey:
    image: valkey/valkey:8-alpine
    command: ["valkey-server", "--requirepass", "secret", "--maxmemory-policy", "noeviction"]
    ports: ["127.0.0.1:56380:6379"]
  mailpit:
    image: axllent/mailpit:latest
    ports: ["127.0.0.1:1025:1025", "127.0.0.1:8025:8025"]
  caddy:
    image: caddy:2-alpine
    ports: ["443:443"]
    volumes: ["./Caddyfile:/etc/caddy/Caddyfile:ro", "caddydata:/data"]
    extra_hosts: ["host.docker.internal:host-gateway"]
volumes:
  caddydata:
```

`Caddyfile`:

```
{
	local_certs
	skip_install_trust
}

https://id.localhost, https://*.id.localhost {
	tls internal
	reverse_proxy host.docker.internal:8000
}
```

```bash
docker compose up -d
docker cp cboxid-live-caddy-1:/data/caddy/pki/authorities/local/root.crt ./caddy-root.crt
```

The development CA is handed to each client explicitly (`CBOX_CA_BUNDLE`,
`NODE_EXTRA_CA_CERTS`, `httpx.Client(verify=…)`, `curl --cacert`). It is never added to the
system trust store.

`.env` (the parts that differ from `.env.example`):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://id.localhost
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=55433
DB_DATABASE=cbox_id
DB_USERNAME=cbox_id
DB_PASSWORD=secret
REDIS_HOST=127.0.0.1
REDIS_PORT=56380
REDIS_PASSWORD=secret
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_SECURE_COOKIE=true
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
TRUSTED_PROXIES=*
CBOX_ID_ENVIRONMENT_BASE_DOMAINS=id.localhost
HEALTH_TOKEN=<openssl rand -hex 16>
```

`TRUSTED_PROXIES=*` is safe here only because the app listens on a port nothing but Caddy
talks to. Install, then start the three processes:

```bash
php artisan key:generate
php artisan cbox-id:install --no-interaction \
    --email=operator@id.localhost --name="Live Operator" \
    --multi-tenant --console-host=id.localhost \
    --environment=Acme --organization="Acme Workspace" \
    --issuer=https://id.localhost

PHP_CLI_SERVER_WORKERS=8 php artisan serve --host=0.0.0.0 --port=8000
php artisan queue:autoscale
php artisan schedule:work
php artisan cbox-id:doctor
```

Result:

- The install migrated, minted the crypto key, created the platform root `platform`, the
  operator, the workspace **Acme Workspace** with its environment (name `Acme`, slug
  `acme-workspace`: the first environment's slug comes from the workspace name), both CLI
  clients and printed `cbox login --issuer https://acme-workspace.id.localhost`. The
  generated operator password is printed once.
- `cbox-id:doctor` once the processes run: **Ready, with 2 warnings.** One is
  `LOG_CHANNEL` writing to disk (expected locally). The other, *Signing keys: no active
  signing key yet*, is wrong: both environments have an active key. The framework's
  doctor counts keys outside any environment, where the tenancy scope answers
  `where 1 = 0`. Harmless; reported against `cboxdk/laravel-id`.
- Probes: `https://id.localhost/up` 200; `/.well-known/openid-configuration` 404 on the
  root (the root is not an IdP) and 200 on `acme-workspace.id.localhost`;
  `/.well-known/cbox-cli` 200 on both, naming the root's and the environment's CLI client.

## 2. Variables

```bash
export ROOT=https://id.localhost                  # the platform root
export CA=$PWD/caddy-root.crt                      # omit on staging (public certificates)
alias cbox='CBOX_CA_BUNDLE=$CA php ./cbox'         # the released PHAR
```

`gh release download v0.1.0 -R cboxdk/cbox-cli -p cbox -p cbox.sha256` and compare the
checksum before running it. To keep the run away from your own CLI profile, run it with
`HOME` pointed at a scratch directory.

## 3. The cbox CLI, signed in at the root

| # | Command | Result |
|---|---|---|
| 3.1 | `cbox login --issuer $ROOT --no-browser` | Prints a user code and `$ROOT/device?user_code=…`. Approved by the operator, signed in at `$ROOT/login`, on `/device`. The CLI then reports the root's scopes, `resource: $ROOT/mcp` and a refresh token, and fetches the catalogue: **249 actions** |
| 3.2 | `cbox id whoami` | Person *Live Operator*, workspace *Acme Workspace* (owner), operator yes, environments `acme-workspace` |
| 3.3 | `cbox id environments create --name=acme --type=sandbox --yes` | **Held**: "Check the code there matches: 8D16". The operator approved the request with that code on `$ROOT/approvals`; the CLI finished by itself: environment `acme`, slug `acme-workspace-acme`, issuer `https://acme-workspace-acme.id.localhost` |
| 3.4 | `cbox id use acme-workspace-acme` | "Commands on id.localhost now act in acme-workspace-acme" |
| 3.5 | `cbox id apps create --name="Acme Web" --type=web --redirect-uris=https://app.acme.test/callback --yes` | Held, approved on `/approvals`, then the app with `client_id` and its `client_secret` (shown once) |
| 3.6 | `cbox id apps update <app> --redirect-uris=https://app.acme.test/callback --redirect-uris=https://app.acme.test/auth/callback --post-logout-redirect-uris=https://app.acme.test/` | Not held (danger: write). Both redirect URIs and the sign-out URI |
| 3.7 | `cbox id apps secrets rotate <app> --grace-seconds=3600 --yes` | Held, approved; the new secret once, `previous_expire_at` an hour out |
| 3.8 | `cbox id organizations create --name="Globex Corporation" --slug=globex` | Organization `globex` |
| 3.9 | `cbox id sso connections create --organization-id=<org> --name="Globex Okta" --type=saml --pending-idp` | A draft and its `service_provider` block (entity ID, ACS URL, metadata URL) for the IT admin to paste into their IdP, on `https://acme-workspace-acme.id.localhost/sso/saml/{id}/…`. **The first run answered them on the root**, which 404s there ([found](#6-what-this-run-found)) |
| 3.10 | `cbox id sso domains create --organization-id=<org> --domain=globex.example` | The TXT record `_cbox-id-challenge.globex.example` to publish |
| 3.11 | `cbox id sso domains verify <domain>` | `verified: false`. Expected: nobody published the record. On staging, use a domain you can publish TXT records for |
| 3.12 | `cbox id organizations portal_links create <org> --intents=sso --intents=domain_verification --email=it@globex.example --yes` | Held, approved; a one-time link on `https://acme-workspace-acme.id.localhost/setup/…` (on the root in the first run, where it said "expired"). Opening it and pressing **Open setup** lands on the portal for *Globex Corporation*. The mail was not sent: a sandbox environment suppresses outbound mail (logged as `Suppressed outbound email from sandbox environment`), although the answer still says `emailed_to` |
| 3.13 | `cbox id webhooks create --url=https://example.com/cbox-webhook --event-types=organization.updated --organization-id=<org> --yes` | Held, approved; the endpoint and its signing secret (once) |
| 3.14 | `cbox id webhooks pause <webhook>` | `active: false`. Paused so nothing is delivered to a host we do not own |
| 3.15 | `cbox id invitations send <org> --env=acme-workspace --email=new.admin@initech.example --role=admin` | In the production environment, so the mail is sent: Mailpit shows "Live Operator invited you to join Initech" with an accept link on `https://acme-workspace.id.localhost/invitations/…` (on the root in the first run, which redirected the invitee to the root's sign-in) |

Every held step printed the binding code, and the request on `/approvals` ended with the
same code. That match is what the person checks; nothing else is approved.

## 4. MCP, with the MCP Inspector CLI

```bash
export NODE_EXTRA_CA_CERTS=$CA
inspect() { npx -y @modelcontextprotocol/inspector@2.8.0 --cli "$1" --transport http \
    --header "Authorization: Bearer $2" "${@:3}"; }
TOKEN=<the access token `cbox login` stored>    # profiles.default.oidc.access_token
```

| # | Call | Result |
|---|---|---|
| 4.1 | `inspect $ROOT/mcp $TOKEN --method tools/list` | **252 tools**: `whoami`, `list_actions`, `approval_status`, the workspace's, every environment tool with a required `environment` argument, the account's and, for an operator, the platform's. The Inspector also prints "Schema portability: 185 warnings across 89 tools": nullable fields are written `"type": ["string", "null"]`, which is valid JSON Schema but which single-`type` dialects (Gemini function declarations) do not accept |
| 4.2 | `… --method tools/call --tool-name keys_create --tool-arg environment=acme-workspace-acme --tool-arg "name=MCP agent key" --tool-arg 'scopes=["apps:read","apps:write","webhooks:read","webhooks:write","organizations:read","organizations:write","sso:read","sso:write","keys:read"]' --tool-arg 'require_approval={"min_danger":"critical"}'` | `approval_pending`, code 5717, and the next step in words |
| 4.3 | Approve on `$ROOT/approvals`, then `--tool-name approval_status --tool-arg approval_id=<id>` | `approved` |
| 4.4 | 4.2 again plus `--tool-arg approval_id=<id>` | The key, its `token` once, `require_approval: {min_danger: critical}` |
| 4.5 | `inspect https://acme-workspace-acme.id.localhost/mcp $KEY --method tools/list` | **60 tools**: exactly what the key's scopes allow |
| 4.6 | `… --tool-name whoami` | `environment_key`, the key's id, environment, issuer and scopes |
| 4.7 | `… --tool-name apps_list` | *Acme Web* |
| 4.8 | `… --tool-name sso_connections_create --tool-arg organization_id=<org> --tool-arg "name=Globex Entra" --tool-arg type=oidc --tool-arg pending_idp=true` | An OIDC draft with its `redirect_uri` on the environment's host. Not held: write is below the key's `critical` |
| 4.9 | `… --tool-name apps_create --tool-arg "name=Agent-made SPA" --tool-arg type=spa --tool-arg 'redirect_uris=["https://spa.acme.test/callback"]' --tool-arg idempotency_key=smoke-spa-1` | `approval_pending` (code 9AEC), shown on the key owner's `$ROOT/approvals` as *Key "MCP agent key" wants to run apps.create* |
| 4.10 | Approve, `approval_status` → `approved`, then 4.9 again with `approval_id` | The public SPA client |

To connect Claude Code itself: `claude mcp add --transport http cbox-id $ROOT/mcp` and sign
in when it asks (the root serves MCP OAuth with dynamic client registration), or
`--header "Authorization: Bearer $KEY"` against an environment host.

## 5. The SDKs

Both scripts take the environment host, the key from 4.4 and the organization. The key
holds critical actions, so the first create waits for the approval on `$ROOT/approvals`.

`smoke.mjs` (`npm i @cboxdk/id-js@0.18.0`; run with `NODE_EXTRA_CA_CERTS=$CA`):

```js
import { readFileSync } from 'node:fs';
import { EnvironmentClient } from '@cboxdk/id-js/management';

const env = new EnvironmentClient({
  baseUrl: process.env.CBOX_ID_BASE_URL,
  apiKey: readFileSync(process.env.CBOX_ID_ENV_KEY_FILE, 'utf8').trim(),
  approvalPollIntervalMs: 1000,
  onApprovalRequired: (approval, { action }) =>
    console.log(`  ${action} needs approval. Check the code there matches: ${approval.binding_code}`),
});

const apps = [];
for await (const app of env.apps.listAll()) apps.push(app);
console.log(`apps.list: ${apps.length} app(s): ${apps.map((a) => a.name).join(', ')}`);

const body = { url: 'https://example.com/js-sdk-webhook', event_types: ['organization.updated'],
  organization_id: process.env.CBOX_ID_ORG_ID };
const idempotencyKey = `js-sdk-smoke-${Date.now()}`;
const first = await env.webhooks.create(body, { idempotencyKey });
const second = await env.webhooks.create(body, { idempotencyKey });
console.log(first.status, first.data.id, first.replayed, Boolean(first.data.secret));
console.log(second.status, second.data.id, second.replayed, Boolean(second.data.secret));
if (first.data.id !== second.data.id || second.replayed !== true) process.exit(1);
await env.webhooks.pause(first.data.id);
```

Result:

```
apps.list: 2 app(s): Acme Web, Agent-made SPA
  webhooks.create needs approval. Check the code there matches: A1D1
webhooks.create #1: status 201, id 01M4EEQB7AJKNPBJXG49NSZYGD, replayed false, secret returned once
webhooks.create #2 (same Idempotency-Key): status 201, id 01M4EEQB7AJKNPBJXG49NSZYGD, replayed true, secret null
webhooks.pause: active false
```

`smoke.py` (`pip install cbox-id-client==0.10.0`) is the same calls through
`cbox_id.management.EnvironmentClient(base_url=…, api_key=…, http_client=httpx.Client(verify=CA))`
with `idempotency_key=`, and gave the same result: one approval, `201` twice, the same id,
`replayed` `False` then `True`, the secret only the first time.

`root_token.py` drives the environment with the CLI's **root** token instead of a key:

```python
env = EnvironmentClient(base_url=ROOT, access_token=token, environment="acme-workspace-acme",
                        http_client=httpx.Client(verify=CA))
connection = env.sso.connections.create({"organization_id": org, "name": "Root-token SAML draft",
                                         "type": "saml", "pending_idp": True}).data
link = env.organizations.portal_links.create(org, {"intents": ["sso"]}).data
# every URL in connection["service_provider"] and link["url"] must be on the environment's host
env.sso.connections.delete(connection["id"])
```

Result: the service-provider URLs and the portal link on
`https://acme-workspace-acme.id.localhost`, two approvals (the portal link and the delete:
a person's token waits for every critical action), and the SDK's approval poll answered
on the root. Before the fix below, all four URLs were on the root.

## 6. What this run found

Fixed in this application:

- **URLs minted for an environment from the platform root pointed at the root.** A SAML
  connection created with `cbox id …` (or the root `/mcp`, or an SDK with `environment=`)
  answered an ACS URL, entity ID and metadata URL on the root, which serves no SAML; the
  Admin Portal link opened "This link has expired" on the root while the same token opened
  on the environment's host; and an invitation mailed from the root had its accept link on
  the root. The action now runs with URLs pinned to the environment's issuer, mailed links
  included. The approval `poll_url` stays on the host the request came to, where the token
  that polls it is valid.
- **MCP tool descriptions called every critical action destructive.** `apps_create` and
  `keys_create` told the agent they "remove or revoke something". A critical tool now says
  what critical means.

Reported elsewhere, not fixed here:

- `cbox-id:doctor` warns *no active signing key* on every multi-environment install
  (framework: the count runs outside any environment).
- `cbox id environments` (CLI 0.1.0) prints an empty `host` column: the API answers
  `issuer` and `domain`.
- Validation that needs the outside world (a webhook URL that does not resolve publicly) is
  checked after the approval, so the person approves a request that then fails with
  `unsafe_url`.

Not possible locally, and replaced:

- **Push approvals on a phone.** Nothing here can reach Cbox Authenticator; every approval
  was given on the console's `/approvals` page, which is the same request and the same
  binding code.
- **The in-app browser.** It does not trust the development CA, so the operator's console
  steps (signing in, the device page, `/approvals`) were made with the console's own HTTP
  requests and a real session cookie. On staging use a browser.
- **Webhook delivery.** The SSRF guard (`CBOX_ID_WEBHOOKS_VERIFY_URL`) refuses a receiver
  on this machine, and turning it off is not part of a production-shaped run. Endpoints were
  created against `https://example.com/…` and paused. On staging, point one at a receiver
  you own and check the `X-Cbox-Signature` on a delivery.
- **Domain verification.** Needs a TXT record in public DNS (step 3.11).
