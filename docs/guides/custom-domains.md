---
title: Custom domains
weight: 45
description: Serve an environment's sign-in and identity endpoints on a domain you own, such as login.example.com — the DNS TXT proof, what moves to the new host, TLS, and how this differs from an organization's email Domains.
---

# Custom domains

**Console page:** Workspace › Environment domains

By default an environment is served on the platform's host for it, such as
`acme.<platform domain>`. A custom domain serves it on an address you own, such as
`login.example.com`, so the people signing in only ever see your address.

## Custom domains or Domains?

Two features have "domain" in the name.

| | Custom domain (this page) | [Domains](single-sign-on.md#verify-your-domains) |
|---|---|---|
| What it is | The **host** an environment is served on | An **email domain** an organization owns, such as `acme.com` |
| Belongs to | One environment | One organization in an environment |
| What it changes | The issuer, discovery and sign-in URLs | Which people are sent to the organization's Enterprise SSO connection |
| TXT record value | `cbox-id-domain-verification=<token>` | The bare token |

Both prove ownership with a TXT record at `_cbox-id-challenge.<domain>`, but the values
differ, so copy the one the page shows you.

## Set one up

1. In the workspace console, open **Environment domains** and pick the **Environment**.
2. Under **Add a domain**, enter the **Custom domain**: a host you control, usually a
   subdomain such as `id.yourcompany.com`. Choose **Add domain**.
3. Publish the TXT record shown under **Prove you control the domain**:

   | Type | Name | Value |
   |---|---|---|
   | TXT | `_cbox-id-challenge.id.yourcompany.com` | `cbox-id-domain-verification=…` |

4. Choose **Verify**. Cbox ID asks your domain's own nameservers, so a record that has
   just been published is seen without waiting for caches. If it is not visible yet, you
   are told so; try again in a few minutes. There is no automatic re-check.
5. Point the host at the deployment's ingress and make sure TLS covers it (below).

A domain is refused if it is malformed, an IP address, the platform's own domain or a
subdomain of it, or already used by another environment.

## What moves once it is verified

- **The issuer** becomes `https://<your domain>`. That is the `iss` in every token, the
  OpenID Connect discovery document, the JWKS URL and the SAML entity ID.
- **Discovery and metadata** requested on the old host are redirected (`302`) to the new
  one.
- **Everything else keeps working on the old host too**: the token, introspection,
  revocation and UserInfo endpoints, SCIM, and browser sign-in. Nothing in flight breaks.
- **Passkeys** are scoped to the custom domain from then on.

**Plan the move.** Every app that pins the old issuer, discovery URL or JWKS URL rejects
tokens with the new `iss` until it is repointed. Update each app's issuer setting at the
same time.

## TLS

Cbox ID proves you control the domain and records it. It does **not** issue the
certificate: that is the deployment's ingress, for example cert-manager or a proxy with
on-demand TLS. Whoever runs the deployment does that part; on your own deployment, see [deployment](../operations/deployment.md) and
[environments in the framework docs](https://github.com/cboxdk/laravel-id/blob/main/docs/core-concepts/environments.md).

## Change or remove it

- **Remove domain** (confirm with **Stop serving**) sends the environment back to its
  default host. Every client pinned to the custom issuer breaks until it is repointed.
- Cancelling a pending domain invalidates its TXT record; adding the domain again issues
  a new one.
- An environment has one live custom domain. The API can hold a pending replacement next
  to it, and the live one keeps serving until the new one is verified; the console does
  not offer that yet.

## From code

Two sets of [actions](../core-concepts/actions.md) do the same thing from different
places:

| From | Actions | REST | Scope |
|---|---|---|---|
| The workspace, for any of its environments (a workspace key) | `environments.domain.request`, `environments.domain.verify`, `environments.domain.remove` | `POST /api/v1/workspace/environments/{environment_id}/domain`, `…/domain/verify`, `DELETE …/domain` | `environments:write` |
| The environment itself (a secret key) | `domains.add`, `domains.get`, `domains.verify`, `domains.remove` | `POST /api/v1/domains`, `GET /api/v1/domains`, `POST /api/v1/domains/verify`, `DELETE /api/v1/domains` | `domains:read`, `domains:write` |

The answer is the environment's `domain` (live, or null), `verified_at`, and `pending`
with the `record_name` and `record_value` to publish. Verifying and removing are recorded
on the workspace's Audit log as `organization.custom_domain_verified` and
`organization.custom_domain_removed`.

## Related

- [Enterprise SSO](single-sign-on.md) — the email Domains that route people to a company's identity provider.
- [Planes and hosts](../core-concepts/planes-and-hosts.md) — which host serves what.
- [Languages](languages.md) — the other half of making sign-in look like yours.
