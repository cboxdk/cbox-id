---
title: Planes and hosts
weight: 10
description: Which host serves what — the platform root versus an environment's own host — and the four planes of the management API that live on them.
---

# Planes and hosts

Cbox ID answers on two kinds of host, and which one you call decides what you can reach.
Most "it returns 404" questions are a request sent to the wrong one.

## Two kinds of host

| | The platform root | An environment's own host |
|---|---|---|
| Hosted address | `cboxid.com` | `<environment>.cboxid.com`, or a [custom domain](../guides/custom-domains.md) |
| Self-hosted | the host you installed on | a host per environment, under your own domain |
| Who signs in | your workspace's team, and the operators who run the install | the environment's own users: your customers' people |
| Is it an identity provider? | **No** on a multi-tenant install: discovery, JWKS and the token endpoint answer 404 | **Yes**: it is the `issuer` your apps use |
| Consoles | the workspace console, and **Platform admin** for operators | the environment console (`/admin`) and your customers' organization console |
| Management API | `/api/v1/workspace`, `/api/v1/platform`, `/api/v1/me` | `/api/v1`, `/api/v1/me` |
| MCP | `/mcp`: the workspace, every environment you administer, your account | `/mcp`: that environment |

**An environment is its own identity provider.** Its host serves the OpenID Connect
discovery document, the authorize and token endpoints, SAML and SCIM, the hosted sign-in
pages, and the Admin Portal your customers' IT admins open. Every app you build signs
people in against that host, and the `issuer` in
`https://<environment>.cboxid.com/.well-known/openid-configuration` is the value you pass
to an SDK.

**The root is not.** On a multi-tenant install the root refuses the protocol surface on
purpose, so nobody configures an app against an issuer that belongs to no environment. See
[the IdP-surface gate](../operations/deployment.md#the-idp-surface-gate-the-apex-host-404s-the-protocol-surface).
On a single-tenant install the root environment *is* the product and the gate is inert.

## Four planes

The management API is split into **planes**: who you are acting as, and over what. Each
[action](actions.md) belongs to exactly one plane, which decides its URL, the credential
that reaches it and where its MCP tool is listed.

| Plane | Path | Host | Credential | What it covers |
|---|---|---|---|---|
| **Environment** | `/api/v1/…` | the environment's | a secret key (`cbid_env_…`) or a token a person delegated | organizations, users, applications, APIs, keys, sign-in, governance — inside one environment |
| **Workspace** | `/api/v1/workspace/…` | the root | a workspace key (`cbid_ws_…`) or a team member's token | projects, environments, the team, keys |
| **Account** | `/api/v1/me/…` | wherever the person signs in | only a token the person delegated | one person's own profile, sessions, app grants, personal keys, devices |
| **Platform** | `/api/v1/platform/…` | the root | only a platform operator's token | the deployment: workspaces, environments, operators |

Two rules follow from the table:

- **Credentials never cross planes.** A secret key is bound to the host it was minted for
  and cannot call another environment, nor the workspace API. A workspace key cannot call
  an environment's API.
- **No key acts as a person.** The account and platform planes take only a token a person
  delegated, so a leaked key can never change somebody's password or mint an operator.

Every plane publishes its OpenAPI document without a key:
`/api/v1/environment/openapi.yaml`, `/api/v1/workspace/openapi.yaml`,
`/api/v1/me/openapi.yaml` and `/api/v1/platform/openapi.yaml`. The
[actions reference](../reference/_index.md) is generated from the same registry.

## One sign-in at the root, many environments

Running a workspace, you are a person of the platform root: you have no account in your
environments, you administer them. So you sign in **once at the root** (the console, `cbox
login`, or an MCP client) and name the environment each time you act inside one:

- **REST:** send the environment-plane request to the root with a `Cbox-Environment`
  header holding the environment's id or slug.
- **MCP:** at the root's `/mcp`, every environment tool takes a required `environment`
  argument. `whoami` lists the values you may use.
- **CLI:** `cbox id use <environment>` picks one for this host; `--env` overrides it for
  one command.

An environment you cannot reach, or another workspace's, answers `not_found`.

## Related

- [Workspaces & organizations](workspaces-and-organizations.md): the hierarchy these hosts serve.
- [Keys and tokens](keys-and-tokens.md): which credential to use where.
- [Agents and MCP](../guides/agents-and-mcp.md): connecting an agent to either host.
- [Custom domains](../guides/custom-domains.md): serving an environment on your own domain.
