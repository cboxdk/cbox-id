---
title: Operations
weight: 30
description: Deploying and running a live Cbox ID instance — deployment on Kubernetes or any other platform, key custody, rotation, upgrades, and break-glass.
---

# Operations

Deploying and running a live identity provider. `cboxid.com` runs on Cbox's own
Kubernetes cluster and is released automatically from `main`; every page also covers
running it yourself on a VM, a PaaS or any Kubernetes cluster.

- [Deployment](deployment.md) — how production runs and how a merge to `main` reaches it,
  what the application expects from its environment, and, for self-hosters, from a fresh
  server or cluster to a running, hardened instance.
- [Day-2 operations](operations.md) — **backing up the crypto key**, signing-key
  rotation, health checks, audit/monitoring, upgrades, and the break-glass runbook.
- [Live updates](realtime.md) — pages that update while open: Reverb, the three
  channels and who may listen, what is sent (ids, never data), running and switching it off.
- [Queue workers](queue-workers.md) — running the queue manager on Kubernetes, a VM or a
  PaaS, the operator-only job monitor, and the health signal that says it is running.
- [Administering the platform's own environment](platform-environment.md) — the root
  environment's console at `/admin` on the root host, who administers it, the guards that
  keep operators able to sign in, and `cbox-id:operator:grant`.
- [Passkeys for platform operators](operator-plane.md) — make `/admin` require a passkey or
  security key, the settings, how to turn it on, and the one-time enrolment link for an
  operator who is locked out.
- [Live smoke test](live-smoke-test.md) — a reproducible end-to-end run with the real
  clients (cbox CLI, an MCP client, both SDKs) against a live deployment.
- [Rehearsing an upgrade](upgrade-rehearsal.md) — prove a release migrates your
  database: the seeded check on PostgreSQL, and a rehearsal on a copy of your
  own data.
- [The realm flip](realm-flip.md) — the one-time migration that makes the `cbox`
  environment the platform root: what it does per table, the rehearsal, the cutover and
  the rollback.
- [Rotating app client secrets to `cbid_cs_`](secret-rotation-v3.md) — the v3 runbook:
  mint a second secret per app, confirm the app uses it, then revoke the `csec_` one.
- [Analytics storage](analytics.md) — where authentication analytics are stored
  (nothing, the app's own database, or ClickHouse) and the retention each needs.

The single most important thing on this page: **back up `CBOX_ID_CRYPTO_KEY`
separately from the database.** Losing it makes sealed secrets unrecoverable. See
[Day-2 operations](operations.md).
