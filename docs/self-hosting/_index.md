---
title: Self-hosting
weight: 70
description: Run Cbox ID on your own infrastructure — install, configure, deploy and operate it — with links to the operator manual.
---

# Self-hosting

Cbox ID is source-available (Elastic License 2.0): you can run the same app that serves
`cboxid.com` on your own infrastructure. Everything else in these docs applies unchanged;
where a page writes `cboxid.com` or `<environment>.cboxid.com`, substitute your own hosts.

## Get it running

1. [Requirements](../requirements.md) — exactly what the app needs to run, as
   `composer.json` enforces it.
2. [Self-hosting quickstart](quickstart.md) — from a checkout to a signed-in platform
   console in a few commands, or by claiming an empty deployment from the browser.
3. [Installation & first run](../getting-started/installation.md) — the same, step by step:
   the first operator, the deployment shape, the first environment and organization.

## Run it in production

- [Deployment](../operations/deployment.md) — a fresh server to a hardened instance: the
  workers, the reverse proxy, security headers, the health probe, and the IdP-surface gate
  that keeps the root host from acting as an identity provider.
- [Configuration](../configuration/_index.md) — every environment variable that matters,
  and the secure defaults.
- [Day-2 operations](../operations/operations.md) — **backing up the crypto key**, key
  rotation, upgrades and break-glass.
- [Queue workers](../operations/queue-workers.md) — what runs in the background and how to
  size it.
- [Security](../security/_index.md) — the operator-facing security surfaces and the
  compliance view.

## Then

Create an environment, then follow a [quickstart](../quickstarts/_index.md) to sign in to
your first app against it. [Planes and hosts](../core-concepts/planes-and-hosts.md)
explains which of your hosts serves what.
