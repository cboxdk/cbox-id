---
title: Compliance
weight: 3
description: The system-level compliance view — framework controls, what this app adds, and what remains yours.
---

# Compliance

Compliance is a property of the **running system** — this deployable app — not of a
library in isolation. An auditor certifies *your Cbox ID deployment*, and that
deployment is `cbox-id`: the framework's controls **plus** what this app adds on top
**plus** the organizational controls only you can supply.

This page is the system-level view. It composes:

1. **Framework-provided controls** — the identity engine's crypto, tenancy, audit,
   OAuth/OIDC/SCIM/SAML and RFC conformance. These are mapped in detail in the
   framework docs — see the `cboxdk/laravel-id` package's
   [Compliance mapping](https://github.com/cboxdk/laravel-id/blob/main/docs/security/compliance.md)
   and its
   [threat model](https://github.com/cboxdk/laravel-id/blob/main/docs/security/threat-model.md)
   and
   [security model](https://github.com/cboxdk/laravel-id/blob/main/docs/security/_index.md).
   **Don't duplicate that table — link to it.**
2. **App-layer controls this deployment adds** (below).
3. **Organizational controls that remain yours** (below).

## Framework controls (inherited — see laravel-id)

The framework mapping covers SOC 2, ISO 27001, NIS2, GDPR, HIPAA and PCI-DSS against
what the identity engine provides: AEAD-at-rest, alg-pinned tokens, key rotation, the
hash-chained tamper-evident audit log, deny-by-default tenancy, MFA/passkeys, SSRF-
guarded webhooks, and the standards conformance matrix.

→ **[Framework compliance mapping](https://github.com/cboxdk/laravel-id/blob/main/docs/security/compliance.md)** —
the authoritative control-by-control table. Everything there applies to this
deployment because this app composes that package — with one **exception you must read
before citing that table**: [erasure (GDPR Art. 17) is not
implemented](#not-implemented-erasure-gdpr-art-17), here or in the framework.

## App-layer controls this deployment adds

These are provided by `cbox-id` (the host), not the framework — so they belong in
*this* mapping, not the framework's:

| Area | What this app adds | Relevant to |
|---|---|---|
| **Anomaly / abuse detection** | Request **risk-scoring** on signup/login via `cboxdk/laravel-risk` — weighted, explainable, monitor-mode by default → CAPTCHA / step-up / reject. | SOC 2 CC7.1–7.2, ISO A.8.16, GDPR Art. 22 (explainable — see the [`cboxdk/laravel-risk` package](https://github.com/cboxdk/laravel-risk)) |
| **Password hashing** | **Argon2id** (memory-hard, side-channel-resistant) as the app default, overriding the framework's bcrypt default. | ISO A.8.5, PCI-DSS 8.3, HIPAA §164.312(d) |
| **Session hardening** | Secure + encrypted cookies, central revocable sessions, idle timeout, sign-out-everywhere, step-up "sudo". | SOC 2 CC6.1/6.6, ISO A.8.2 |
| **Secure-by-default posture** | `cbox-id:doctor` enforces `APP_DEBUG` off and secure/encrypted sessions in production; fails the check otherwise. | SOC 2 CC6.1, NIS2 Art. 21(g) |
| **Key custody & recovery** | Documented crypto-key backup, signing-key rotation, and break-glass runbook. | ISO A.8.24, NIS2 Art. 21(h), GDPR Art. 32 |
| **Deployment evidence** | Reproducible install (`cbox-id:install`), health gate (`cbox-id:doctor`), dependency/CVE gate (`composer audit`). | SOC 2 CC7.1, ISO A.8.8 |

See [Configuration](../configuration/environment-variables.md) for the settings
behind these and [Operations](../operations/operations.md) for key custody,
rotation, and break-glass.

## Not implemented: erasure (GDPR Art. 17)

**This deployment cannot erase a person's data, and nothing in it should be presented
to an assessor as a right-to-erasure control.** State this plainly in your own
records; a control claimed but absent is worse than a control acknowledged as missing,
because it stops anyone from building it.

What actually exists today, and what it does:

| Capability | What it does | What it does **not** do |
|---|---|---|
| **Deactivate a user** (environment console › Users) | Sets the subject to `disabled`. Sign-in is refused and existing sessions stop working on their next request. Reversible. | Removes nothing. Sessions, passkeys, MFA factors and TOTP seeds, password history, identity-provider profiles (`identities.raw`), magic links, verification tokens, OAuth access/refresh tokens, directory/SCIM records (`directory_users.resource`) and role assignments are all retained. |
| **Revoke sessions and tokens** | Terminates sessions and revokes issued credentials. | Removes no stored personal data. |
| **Delete an organization** (environment console › Organizations) | Soft status change to `deleted`: the tenant leaves every list and its members are refused at sign-in, device authorization and consent. | Erases nothing — the tenant's rows, and its members', are kept for audit. |
| **Risk-decision retention** (`risk_decisions`, default 90 days) | Bounds how long pseudonymised signup/login scoring data is held; rows matching a subject's pseudonym can be deleted on request (see [Adaptive risk](./adaptive-risk.md)). | Is a retention window on one table, not subject erasure. |

There is no erasure service, command, endpoint or console action anywhere in this
codebase. Satisfying an Art. 17 request against this deployment is a **manual,
out-of-band** exercise today, and the DPIA and records of processing you maintain
under [*What remains yours*](#what-remains-yours-organizational-controls) must say so.

Erasure is **planned** — a designed programme with an erasure ledger, a grace window,
downstream deprovisioning and crypto-shredded audit, rather than a row delete. It is
not built, so nothing above is written in the present tense. This section changes only
when the code does.

## What remains yours (organizational controls)

No software supplies these — they're process, not code. They're listed in full in the
framework mapping's
[*What remains yours*](https://github.com/cboxdk/laravel-id/blob/main/docs/security/compliance.md)
section and apply identically here: infosec policy and access-review cadence; data
retention + DPIA (including risk-scoring data if you enforce it); incident response
and NIS2/GDPR reporting timelines; independent assurance (the SOC 2 / ISO / HIPAA /
PCI assessment itself); penetration testing; and physical/network controls (hosting,
egress allow-list, backups, custody of the crypto master key).

## Evidence this deployment hands your auditor

- The [framework compliance mapping](https://github.com/cboxdk/laravel-id/blob/main/docs/security/compliance.md)
  and
  [standards conformance matrix](https://github.com/cboxdk/laravel-id/blob/main/docs/security/standards.md).
- The
  [framework security](https://github.com/cboxdk/laravel-id/blob/main/docs/security/_index.md)
  and
  [threat model](https://github.com/cboxdk/laravel-id/blob/main/docs/security/threat-model.md)
  documents.
- A machine-readable **CycloneDX SBOM** and a passing dependency/license/vuln gate.
- A **tamper-evident audit trail** exportable as forensic evidence.
- This deployment's **secure-by-default config**, verifiable at any time with
  `php artisan cbox-id:doctor`.

## Where to go next

- [Configuration](../configuration/environment-variables.md) — the secure defaults
  referenced above.
- [Operations](../operations/operations.md) — key custody, rotation, audit/SIEM,
  break-glass.
- Framework:
  [Compliance mapping](https://github.com/cboxdk/laravel-id/blob/main/docs/security/compliance.md),
  [Security](https://github.com/cboxdk/laravel-id/blob/main/docs/security/_index.md).
