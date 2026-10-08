---
title: Security
weight: 40
description: Operator-facing security surfaces of the Cbox ID app — step-up, org switching, signup lockdown — plus the system-level compliance view.
---

# Security

This section covers the security surfaces the Cbox ID **app** adds on top of the
identity engine, and the system-level compliance view.

- [Adaptive risk](adaptive-risk.md) — risk-based authentication: every sign-in is
  scored and, under enforcement, adapts (allow / step-up / deny).
- [Compliance](compliance.md) — the system-level control mapping (framework controls
  + what this app adds + what remains yours).

The framework-level security posture — tenant isolation, the crypto kernel, the
tamper-evident audit log, and the STRIDE threat model — lives in the
`cboxdk/laravel-id` package docs:
[Security](https://github.com/cboxdk/laravel-id/blob/main/docs/security/_index.md)
and
[Threat model](https://github.com/cboxdk/laravel-id/blob/main/docs/security/threat-model.md).
See also this repository's [`SECURITY.md`](https://github.com/cboxdk/cbox-id/blob/main/SECURITY.md)
for the vulnerability-reporting policy.

## Operator security surfaces

These are behaviours the app ships that an operator should understand.

### Step-up authentication (`/sudo`)

Sensitive actions (managing operators, rotating credentials, changing security
settings) require **re-authentication into a short-lived elevated "sudo" session**
even when the user is already signed in. The user is sent to `/sudo` to confirm a
credential; the elevation is time-boxed and does not persist for the whole session.
This limits the blast radius of a hijacked, already-authenticated session.

### Organization switcher

A user who belongs to several organizations switches the active organization from the
sidebar. The switch is **server-verified against membership on every request** — the
active org is resolved from the authenticated user's memberships, not from a
client-supplied value, so a user can only ever act within an org they actually
belong to. The role in effect updates with the switch, and switching is audited.

### Self-service signup modes

`CBOX_ID_SIGNUP_MODE` gates the public `/signup` surface (see
[Configuration](../configuration/environment-variables.md#self-service-signup)):

- **`open`** — anyone may create an account + organization (the default).
- **`invite_only`** — public signup is closed; new accounts arrive only through
  admin invitations, which keep working.
- **`closed`** — no self-service signup at all.

Admin- and operator-initiated provisioning (invitations, the `/platform` section) is
**never** gated by this — it is not self-service. Set this to `invite_only` or
`closed` for a private or internal deployment so the internet-facing signup form
cannot be used to create organizations.

### Signup abuse controls

An `open` signup is an internet-facing "create infrastructure" button, so it carries
three layers beyond the mode gate — described in full under
[Adaptive risk](adaptive-risk.md#signup-specifically):

1. **A per-IP rate limit and a honeypot + submit-timing pair**, both fed to the risk
   scorer.
2. **A risk-triggered CAPTCHA** (Cloudflare Turnstile) on a *challenged* signup only —
   never on every signup, and entirely inert unless
   [`CBOX_ID_TURNSTILE_*`](../configuration/environment-variables.md#bot-protection-captcha)
   is configured.
3. **Deferred environment provisioning.** A self-serve signup creates the workspace, its
   owner and its first project immediately, but the **environment** — the routable IdP
   with its own signing key — is stood up only when the owner opens the verification
   link in their inbox. An unverified signup therefore costs nothing worth farming.

Because (3) puts a real owner's whole workspace behind one email, the workspace launchpad
carries a **resend** control while the environment is held back. It re-sends only to the
signed-in member's own address (the action accepts a member, never an address), retires
every link issued before it so exactly one is ever live, answers identically whether or
not the address is already confirmed — so it cannot be used to test verification state —
and is throttled to **3 sends per 10 minutes per member**, because outbound mail is the
resource worth abusing here.

### MCP sign-in at the platform root

On a multi-tenant deployment the platform root is not an identity provider for anybody's
app: it serves no OpenID Connect discovery, UserInfo, SAML or SCIM, and an organization
admin who creates an OAuth client there (Developers › Apps) cannot use it to sign anyone
in. It does sign in two kinds of client, both for its own `/mcp`:

- the platform's own first-party clients, such as the `cbox` CLI (device grant);
- since `CBOX_ID_ROOT_MCP_OAUTH` (on by default), any MCP client, so
  `claude mcp add --transport http cbox-id https://<platform-root>/mcp` gives a person one
  connection for their whole workspace.

What the second one exposes on the root, and what bounds it:

| Surface | Bound |
|---|---|
| `/.well-known/oauth-protected-resource/mcp` | The `/mcp` resource only; any other path is `404`. |
| `/.well-known/oauth-authorization-server` | Written for the root: code flow with S256 PKCE, public clients, the root `/mcp` scopes and `offline_access`. No OpenID Connect fields. |
| `POST /oauth/register` | The `mcp` profile only (public client, PKCE, https or loopback redirects, the authorization-code and refresh grants). In any other `CBOX_ID_DCR_MODE` the root registers nothing. No RFC 7592 management. Per-address ceiling (`CBOX_ID_DCR_MAX_PER_IP_PER_HOUR`), and unused clients are pruned (`CBOX_ID_PRUNE_UNUSED_DYNAMIC_CLIENTS`). |
| `/oauth/authorize`, consent, `/oauth/token`, `/oauth/revoke` | Only for a client that registered itself at the root or a client ID metadata document, besides the first-party clients. `/oauth/authorize` is metered per address at the root (60/min); the token endpoint keeps its 30/min. |
| Who can finish signing in | Only a member of a workspace's team or an operator. Anyone else is refused on the page. |
| What the token is for | The root's `/mcp` only. A missing `resource` defaults to it; any other (the root issuer, a registered API, another host) is `invalid_target`, at `/authorize` and at the token endpoint, refresh included. `openid`, `profile` and `email` are refused, so no ID token is issued. |
| What the token can do | What the person can do, within the scopes they allowed. The consent screen is never skipped for these clients, and every critical action waits for the person's approval. |
| Record | Each registration as `mcp.client_registered` with the address it came from; each consent as `mcp.client_authorized` in the person's workspace trail. |

Why this is acceptable: the risk the root's wall exists for is a customer turning the one
host every customer's owner trusts into a sign-in page for their own app. These clients
cannot do that. They can only ever be issued a token for the root's `/mcp`, which acts as
the person who signed in and nothing more, and the person sees the consent screen every
time, with the client marked as one that registered itself. `CBOX_ID_ROOT_MCP_OAUTH=false`
returns every surface above to `404`.

## End-user consent surfaces

- **OAuth consent (`/oauth/authorize`)** — registered clients requesting access are
  presented to the signed-in user, who reviews the requested scopes and grants or
  denies them.
- **Device approval (`/device`)** — the Device Authorization Grant confirmation
  page, where a user approves a device (by user code) before it receives tokens.

See [Installation & first run](../getting-started/installation.md#key-surfaces-this-app-ships)
for more on these flows.
