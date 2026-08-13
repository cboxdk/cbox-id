---
title: Console modules
weight: 3
description: The seven in-tree console modules — analytics, billing, compliance, connectors, devices, risk-plus and whitelabel — what each does, and why they are vendored rather than installed.
---

# Console modules

Seven capability areas live under `modules/` rather than in `app/`:

| Module | Console area | What it adds |
| --- | --- | --- |
| `analytics` | Analytics | Authentication activity dashboards over a pluggable event store. See [Analytics storage](../operations/analytics.md). |
| `billing` | Identity platform → Billing | The organization's usage rollup and its per-project plan allowances. Off switches the page and its route off entirely — a deployment that does not bill carries no billing surface. Set `CBOX_BILLING_ENABLED=false`. |
| `compliance` | Compliance | Audit-trail export to a JSONL bundle or a SIEM endpoint, chain verification, retention, and a data-subject export. |
| `connectors` | Connectors | One catalog and connections view over outbound SCIM provisioning, webhooks, inbound directory sync and upstream IdP federation. |
| `devices` | Sign-in → Devices | A phone as an authenticator: approval pushes on the CIBA path, security alerts, and the REST surface the app talks to. Off by default. See [Trusted devices](../guides/trusted-devices.md). |
| `risk-plus` | Security | Adaptive-risk signals (impossible travel, new device) plugged into the risk engine, and a console to review elevated events. |
| `whitelabel` | Settings → Branding | Per-tenant branding: palette, logo, favicon, app name, custom domain and email sender. |

**All seven are always present.** There is nothing to license, unlock or install — but
several can be switched off, and off means *absent*: no route, no nav entry, no page. A
member who may not read billing is redirected to Projects by the page itself; a
deployment with `billing` switched off has no `/billing` to redirect from. Those are
different questions and the module asks both.

## Why they are modules and not just `app/` code

Each one registers itself the way a third-party plugin would — its own service
provider, its own nav entries, routes, views, migrations and feature gate, all through
the same console-kit sockets any external package would use. Nothing in `app/` knows
they exist.

That boundary is load-bearing rather than decorative: it is the proof that the
extension points actually work. If a first-party module needed a special hook that an
external one could not reach, the socket would be a fiction. Keeping these seven on the
public seam keeps it honest.

They were separate Composer packages until they were folded in. The move was about
release overhead, not architecture — seven repositories, seven changelogs and seven
version bumps to ship one coherent change, protecting about eight thousand lines that
were never the moat. The sockets survived the move intact; only the distribution
changed.

## What that means in practice

- **Providers are named explicitly** in `bootstrap/app.php`. Laravel's package
  auto-discovery only reads installed packages, so vendored code has to be registered
  by hand — that list is the one thing that would silently un-load a module.
- **Migrations stay with their module**, loaded by its provider from
  `modules/<name>/database/migrations`. A module owns its own schema.
- **Config lives in the app's `config/`**, not inside the module. An in-repo module is
  not a package: its config belongs where a Laravel application keeps config, and
  there is no host to publish it to.
- **Tests live in `tests/Feature/<Module>/`** and run against the application's own
  `TestCase`, not a package test harness.

## Writing a new one

Follow any of the seven. A module is a directory with `src/`, optionally
`database/migrations/`, `resources/views/`, and `routes/`; a PSR-4 root in
`composer.json`; and its provider added to `bootstrap/app.php`. Register nav and
feature gates through the `Console` facade, and bind capabilities behind contracts so
the module stays inert until its backing service is wired.
