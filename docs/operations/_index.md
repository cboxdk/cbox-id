---
title: Operations
weight: 30
description: Deploying and running a live Cbox ID instance — deployment on Kubernetes or any other platform, key custody, rotation, upgrades, and break-glass.
---

# Operations

Deploying and running a live identity provider. Production runs on Kubernetes, deployed
from `cbox.yaml` through the Cbox platform; every page also covers a VM or a PaaS.

- [Deployment](deployment.md) — from a fresh server or cluster to a running, hardened
  instance, and how a release rolls out.
- [Day-2 operations](operations.md) — **backing up the crypto key**, signing-key
  rotation, health checks, audit/monitoring, upgrades, and the break-glass runbook.
- [Queue workers](queue-workers.md) — running the queue manager on Kubernetes, a VM or a
  PaaS, the operator-only job monitor, and the health signal that says it is running.
- [Analytics storage](analytics.md) — where authentication analytics are stored
  (nothing, the app's own database, or ClickHouse) and the retention each needs.

The single most important thing on this page: **back up `CBOX_ID_CRYPTO_KEY`
separately from the database.** Losing it makes sealed secrets unrecoverable. See
[Day-2 operations](operations.md).
