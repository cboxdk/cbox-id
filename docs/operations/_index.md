---
title: Operations
weight: 30
description: Deploying and running a live Cbox ID instance — deployment, key custody, rotation, upgrades, and break-glass.
---

# Operations

Deploying and running a live identity provider.

- [Deployment](deployment.md) — from a fresh server to a running, hardened instance.
- [Day-2 operations](operations.md) — **backing up the crypto key**, signing-key
  rotation, health checks, audit/monitoring, upgrades, and the break-glass runbook.
- [Analytics storage](analytics.md) — where authentication analytics are stored
  (nothing, the app's own database, or ClickHouse) and the retention each needs.

The single most important thing on this page: **back up `CBOX_ID_CRYPTO_KEY`
separately from the database.** Losing it makes sealed secrets unrecoverable. See
[Day-2 operations](operations.md).
