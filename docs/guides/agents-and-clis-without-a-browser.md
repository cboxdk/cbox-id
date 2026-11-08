---
title: Agents and CLIs without a browser
weight: 122
description: How an agent or CLI that registered itself gets a person's approval and a token for one MCP server or API, with CIBA or the device grant, and what an operator turns on to allow it.
---

# Agents and CLIs without a browser

An agent running in a terminal, a CI job or a chat often has no browser to send a person
through. It can still act for someone with their approval:

- **CIBA** ([OpenID CIBA Core 1.0](https://openid.net/specs/openid-client-initiated-backchannel-authentication-core-1_0.html)):
  the agent names the person and Cbox ID asks them. They approve on their phone or on
  their Approvals page.
- **The device grant** ([RFC 8628](https://www.rfc-editor.org/rfc/rfc8628)): the agent
  shows a short code and a URL. The person opens the URL anywhere, signs in and enters the
  code.

Both work for a client that [registered itself](agents-and-mcp.md#registration)
([RFC 7591](https://www.rfc-editor.org/rfc/rfc7591)), on two conditions. The token must be
for one **protected resource**, such as an MCP server. And that resource must have opened
these grants to self-registered clients.

## For operators: open a resource

Self-registered clients get nothing until a resource is declared in
`CBOX_ID_PROTECTED_RESOURCES`. One entry per MCP server or API:

```json
[{
  "identifier": "https://app.example.com/mcp",
  "name": "Example",
  "scopes": ["mcp:read", "mcp:write"],
  "dynamic_clients": true,
  "dynamic_client_grants": ["ciba", "device_code"],
  "dynamic_public_backchannel": false
}]
```

| Key | Effect |
|---|---|
| `dynamic_clients` | Self-registered clients may get tokens for this resource. Required for the rest. |
| `dynamic_client_grants` | Which decoupled grants they may use for it: `ciba`, `device_code`, both or neither. |
| `dynamic_public_backchannel` | Whether a client **without a key** may use CIBA here. Off by default. [Why it is safe to turn on](#public-clients-and-ciba). |

The deployment's registration mode must be `mcp`. Each entry is a decision for one
resource. Nothing else in the environment changes.

## For client authors

### 1. Register

Generate a key pair per install, keep the private key, and register the public key:

```bash
curl -s -X POST https://auth.example.com/oauth/register \
  -H 'Content-Type: application/json' \
  -d '{
    "client_name": "Example CLI",
    "grant_types": ["urn:openid:params:grant-type:ciba", "urn:ietf:params:oauth:grant-type:device_code"],
    "scope": "openid mcp:read mcp:write",
    "token_endpoint_auth_method": "private_key_jwt",
    "jwks": {"keys": [{"kty": "EC", "crv": "P-256", "x": "…", "y": "…", "kid": "…", "use": "sig", "alg": "ES256"}]}
  }'
```

No secret comes back. Authenticate every call with a `client_assertion`
([RFC 7523](https://www.rfc-editor.org/rfc/rfc7523)) signed by that key, with `aud` set to
the token endpoint.

A client without a key registers with `"token_endpoint_auth_method": "none"`. It can always
use the device grant. It can use CIBA only where the resource sets
`dynamic_public_backchannel`.

You get `invalid_client_metadata` when no resource opened the grant you asked for.

### 2a. Ask with CIBA

```bash
curl -s -X POST https://auth.example.com/oauth/backchannel_authentication \
  -d client_id="$CLIENT_ID" \
  -d client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer \
  -d client_assertion="$ASSERTION" \
  -d login_hint="usr_01h…" \
  -d scope="openid mcp:read" \
  -d resource="https://app.example.com/mcp" \
  -d binding_message="Example CLI on laptop · 4F2K"
# → {"auth_req_id":"…","expires_in":300,"interval":5}
```

- **`resource`** is required. It is the identifier of the resource you want a token for.
- **`binding_message`** is required. Show the same text in your own window. The person
  sees it next to Approve and checks that the two match. End it with a short random code.
- **`login_hint`** is the person's user id, or an email address they have confirmed.
  Anything else returns `unknown_user_id`, with an `error_description` that says so.

Then tell the person where to approve, as in
[Approvals without the mobile app](approvals-without-the-app.md#what-your-agent-must-do),
and poll:

```bash
curl -s -X POST https://auth.example.com/oauth/token \
  -d grant_type=urn:openid:params:grant-type:ciba \
  -d auth_req_id="$AUTH_REQ_ID" \
  -d resource="https://app.example.com/mcp" \
  -d client_id="$CLIENT_ID" \
  -d client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer \
  -d client_assertion="$ASSERTION"
```

### 2b. Or ask with the device grant

```bash
curl -s -X POST https://auth.example.com/oauth/device_authorization \
  -d client_id="$CLIENT_ID" \
  -d scope="openid mcp:read" \
  -d resource="https://app.example.com/mcp"
# → {"device_code":"…","user_code":"WDJB-MJHT","verification_uri":"…","interval":5,…}
```

A client that registered a key adds its `client_assertion` here as well. Show the
`user_code` and the `verification_uri`. Then poll `/oauth/token` with
`grant_type=urn:ietf:params:oauth:grant-type:device_code`, the `device_code` and the same
`resource`.

### 3. Use the token

The access token's `aud` contains the resource, so the MCP server or API accepts it.
A token for one resource is not accepted by another.

### Errors

| Error | Meaning |
|---|---|
| `invalid_target` | `resource` is missing, is not declared, or has not opened this grant to self-registered clients. On a poll, it means the `resource` differs from the one the request was approved for. |
| `invalid_binding_message` | A self-registered client sent no `binding_message`. |
| `unknown_user_id` | `login_hint` is neither a user id nor a confirmed email address in this environment. |
| `slow_down` (HTTP 429, `Retry-After`) | Too many requests for this person, either from your client or from all clients together. Wait for `Retry-After`. |
| `authorization_pending`, `access_denied`, `expired_token` | As in any CIBA or device flow. |

## Why this is safe

A self-registered client is whoever reached the registration endpoint. The protections
therefore rest on what the person sees and on limits. Who the client claims to be is not
part of them.

- **The person sees what they approve.** The Approvals page and the app show the client's
  name, a notice that the client named itself, the resource with its identifier, the
  scopes and the binding message. A prompt the person did not start does not match
  anything in front of them.
- **The token is for one resource.** Through CIBA or the device grant, a self-registered
  client cannot get a token for Cbox ID itself or for a resource that did not opt in.
- **Hammering is capped.** Five requests per client and `login_hint` per minute, and ten
  per person across all clients per ten minutes (`CBOX_ID_CIBA_PER_CLIENT_HINT`,
  `CBOX_ID_CIBA_PER_PERSON`). Approval mails have their own limit.
- **Everything is recorded.** Every request, approval and denial is in the audit log as
  `oauth.ciba.requested`, `oauth.ciba.approved` and `oauth.ciba.denied`.

### Public clients and CIBA

CIBA normally requires an authenticated client: a `client_id` is not a secret, and without
client authentication anyone could send prompts. With `dynamic_public_backchannel` a
resource accepts public clients anyway, for a CLI that cannot keep a key.

None of the protections above depend on client authentication, so turning it on adds no
new way to get a token without the person's approval. What it gives up is attribution:
two installs of the same CLI look alike in the audit log, and the per-client limit is
shared between them. Prefer `private_key_jwt` wherever a key can be kept.

### What is not supported

- **`backchannel_user_code_parameter`** is advertised as `false`. In CIBA, the `user_code`
  is a secret the person agrees with Cbox ID in advance. It is not a code the client
  shows. A matching code goes in `binding_message`.
- **Ping and push delivery modes.** Only poll is offered.

## Related

- [Agents and MCP](agents-and-mcp.md): registration and discovery for MCP clients.
- [Approvals without the mobile app](approvals-without-the-app.md): what your agent must tell the person.
- [Approvals](agent-approvals.md): the approver's side.
- [Environment variables](../configuration/environment-variables.md): `CBOX_ID_PROTECTED_RESOURCES` and the CIBA limits.
