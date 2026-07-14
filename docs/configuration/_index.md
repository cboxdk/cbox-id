---
title: Configuration
weight: 20
description: Environment variables and the secure defaults the Cbox ID app ships with.
---

# Configuration

How to configure a Cbox ID deployment, and the secure defaults it ships with. Run
`php artisan cbox-id:doctor` any time to have the important settings checked for
you.

- [Environment variables](environment-variables.md) — the complete reference: every
  `CBOX_ID_*` variable, the security-posture Laravel keys, and the optional
  subsystems, with defaults and when to change them.

Signing keys are **not** environment variables — they live in the database, are
minted on install/first use, and are rotated with `cbox-id:keys:rotate` (see
[Operations](../operations/operations.md)). The public half is published at
`/.well-known/jwks.json`.
