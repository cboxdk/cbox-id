---
title: Cbox ID documentation
weight: 1
description: Sign people in to your app, run your customers' organizations, wire agents to the management plane, and run Cbox ID yourself.
---

# Cbox ID documentation

Cbox ID is an identity platform: sign-in, organizations, Enterprise SSO, Directory Sync,
API keys, App audit logs and an Admin Portal for your customers' IT admins, with every
change reachable from the console, a REST API, an MCP server and the `cbox` CLI.

## Start here

**Sign in to your first app in under ten minutes.** Pick your framework:
[Next.js](quickstarts/nextjs.md) · [React](quickstarts/react.md) ·
[Laravel](quickstarts/laravel.md) · [Nuxt](quickstarts/nuxt.md) · [Go](quickstarts/go.md)
· [Python](quickstarts/python.md). Each one creates the app, installs the SDK, and walks
through sign-in, the callback, a protected route and sign-out.

Then read [Workspaces & organizations](core-concepts/workspaces-and-organizations.md):
five minutes on how your workspace, projects, environments and your customers'
organizations fit together saves an afternoon later.

## By what you are doing

| You are… | Go to |
|---|---|
| **building an app** that signs people in | [Quickstarts](quickstarts/_index.md), then [Getting started](getting-started/_index.md) |
| **selling to enterprises**: SSO, SCIM, audit logs | [Enterprise SSO](guides/single-sign-on.md), [Directory Sync](guides/sync-users-in.md), [App audit logs](guides/audit-logs.md), [Admin Portal](guides/admin-portal.md) |
| **wiring an AI agent** to Cbox ID | [Agents and MCP](guides/agents-and-mcp.md), [Actions](core-concepts/actions.md), [Step-up approvals](guides/step-up-approvals.md), [Actions reference](reference/_index.md) |
| **automating** from a backend or CI | [Keys and tokens](core-concepts/keys-and-tokens.md), [Run your tenancy from your backend](getting-started/management-api.md), [Actions reference](reference/_index.md) |
| **administering** an environment in the console | [Admin guides](guides/_index.md) |
| **a customer's IT admin** holding an Admin Portal link | [For IT admins](for-it-admins/_index.md) |
| **running Cbox ID yourself** | [Self-hosting](self-hosting/_index.md) |

## Sections

- [Quickstarts](quickstarts/_index.md) — zero to first sign-in, per framework.
- [Concepts](core-concepts/_index.md) — the hierarchy, planes and hosts, keys versus
  delegated tokens, actions ("one action, four doors"), approvals, and the two audit
  records.
- [Getting started](getting-started/_index.md) — the next layer after the first sign-in:
  registering apps, organizations in your app, CLIs, customer API keys, the management API.
- [Admin guides](guides/_index.md) — one per console page, linked from the "?" beside each
  page title.
- [For IT admins](for-it-admins/_index.md) — for your customers' IT administrators: what
  an Admin Portal link lets them do, with setup guides for Okta, Microsoft Entra ID, Google
  Workspace and generic SAML, OIDC and SCIM.
- [Reference](reference/_index.md) — every action per plane, and the OpenAPI documents.
- [Self-hosting](self-hosting/_index.md) — install, configure, deploy and operate it.
  [Configuration](configuration/_index.md), [Operations](operations/_index.md) and
  [Security](security/_index.md) are the operator manual.

## The framework underneath

Cbox ID is the deployable app built on the `cboxdk/laravel-id` framework, which provides the
identity engine: crypto, tenancy, OAuth and OpenID Connect, SAML, SCIM, audit. The protocol
level is documented with the framework, at
[github.com/cboxdk/laravel-id](https://github.com/cboxdk/laravel-id/blob/main/docs/index.md).
References into it are canonical URLs, never relative paths: the two are separate
repositories.

The console is Inertia and React over server-rendered props, with session-cookie auth and
no tokens in the browser, because it *is* the sign-in surface.
