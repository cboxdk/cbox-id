---
title: Reference
weight: 60
description: Every action on every plane with its REST route, scope, danger, MCP tool and CLI command, and the OpenAPI documents they are generated from.
---

# Reference

## Actions

Every change and every read the platform offers is an [action](../core-concepts/actions.md),
reached the same way from the console, REST, MCP and the `cbox` CLI. One page per
[plane](../core-concepts/planes-and-hosts.md#four-planes) lists each action with its REST
method and path, the scope it needs, its danger, its MCP tool name, its CLI command, a
summary and its input fields:

| Page | Covers | Reached with |
|---|---|---|
| [Environment actions](actions-environment.md) | everything inside one environment: organizations, users, applications, APIs, keys, sign-in, governance, App audit logs, webhooks, log streams | a secret key (`cbid_env_…`) on the environment's host, or a delegated token |
| [Workspace actions](actions-workspace.md) | projects, environments and their domains, the team, keys | a workspace key (`cbid_ws_…`) or a team member's token, at the root |
| [Account actions](actions-account.md) | one person's own sessions, app grants, personal keys and devices | only a token that person delegated |
| [Platform actions](actions-platform.md) | the deployment: workspaces, environments, operators | only a platform operator's token |

These pages are **generated** from the action registry by `php artisan docs:actions`, and
the build fails when they fall behind (`php artisan docs:actions --check`). If one is
wrong, the action is wrong.

## OpenAPI

Each plane publishes an OpenAPI 3.1 document, public and without a key, on the host that
serves the plane. Every operation carries `x-action` (the action's name), `x-scope` and
`x-danger`, which is what the `cbox` CLI builds its commands from.

| Plane | Served at | Source |
|---|---|---|
| Environment | `https://<environment-host>/api/v1/environment/openapi.yaml` | [environment.yaml](https://github.com/cboxdk/cbox-id/blob/main/resources/openapi/environment.yaml) |
| Workspace | `https://<root-host>/api/v1/workspace/openapi.yaml` | [workspace.yaml](https://github.com/cboxdk/cbox-id/blob/main/resources/openapi/workspace.yaml) |
| Account | `https://<host>/api/v1/me/openapi.yaml` | [account.yaml](https://github.com/cboxdk/cbox-id/blob/main/resources/openapi/account.yaml) |
| Platform | `https://<root-host>/api/v1/platform/openapi.yaml` | [platform.yaml](https://github.com/cboxdk/cbox-id/blob/main/resources/openapi/platform.yaml) |

They are generated too (`php artisan openapi:build`), from a hand-written base per plane and
the same registry.

## Protocols

The identity protocols an environment serves (OpenID Connect, OAuth 2.1, SAML, SCIM, CIBA,
the device grant) are the framework's, documented with
[`cboxdk/laravel-id`](https://github.com/cboxdk/laravel-id/blob/main/docs/index.md). Every
environment's endpoints are listed in its discovery document,
`https://<environment-host>/.well-known/openid-configuration`.

## Configuration

- [Environment variables](../configuration/environment-variables.md) — every setting a
  deployment reads.
