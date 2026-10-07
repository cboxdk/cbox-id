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
deployment because this app composes that package. Read [erasure (GDPR Art.
17)](#erasure-gdpr-art-17) before citing the erasure row: it says exactly what is erased
and what is kept.

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

## Erasure (GDPR Art. 17)

**Erase user** (environment console › Users › a person › Danger zone), the management
API's `POST /v1/users/{id}/erase` and the MCP tool `users_erase` are one action,
`users.erase`. It runs the framework's `SubjectEraser` — see the framework's
[erasure](https://github.com/cboxdk/laravel-id/blob/main/docs/security/erasure.md) page
for every store it covers — in **one database transaction**, with this app's own stores
registered as steps of the same pipeline. It is `critical`: the console demands a fresh
credential and the person's address typed out; a management key needs the `users:erase`
scope, which `users:write` does not include.

What it does:

- Revokes the person's sessions (relying parties receive back-channel logout) and OAuth
  grants; deletes passkeys, second factors, recovery codes, password history, linked
  identity-provider profiles, memberships, role grants, API tokens, vault secrets and the
  stored copies of their details in the event outbox, webhook deliveries and the SCIM
  queue.
- Deletes what this app adds: enrolled handsets, their push history and enrolment codes
  (devices module); embedded sign-in tickets; setup-checklist dismissals; the flagged
  sign-ins on the risk review trail and the adaptive signals' memory of the address
  (risk-plus module). The keyed pseudonym of the address on `risk_decisions` is removed;
  the scored decision itself stays, naming nobody.
- Pseudonymises the account row in place — the id is kept, email and name become
  placeholders, the account is disabled — records a `user.erased` audit tombstone and
  emits `user.erased`, which outbound SCIM answers with a `DELETE` on every connection.
- Returns a **receipt** (store by store, in numbers, no personal data) to keep with your
  Art. 30 records.

It refuses to erase the only owner of an organization (transfer ownership first), and a
refusal changes nothing — the whole erasure rolls back.

What it deliberately keeps, and you should document under Art. 17(3)(b)/(e):

| Kept | Why |
|---|---|
| **The audit trail** | Every column of an entry is inside its hash and chained into the next; rewriting one breaks verification for everything after it. Past entries keep the person's **opaque id**, which identifies nobody once the account row is pseudonymised. Some entries also carry values that are personal data in their own right — the IP of sign-in events, an address as the target of an invitation. The chain verifies after an erasure. |
| **Access-review history and support sessions** | Records of decisions taken, keyed by id. |
| **Everything outside this database** | Your SIEM (if audit streaming is on), webhook receivers (they get `user.erased` and should erase their copy), downstream SCIM apps whose `DELETE` failed (it retries and dead-letters visibly), and backups. |

Deactivation (Users › Deactivate) remains the reversible off-switch: it removes nothing.

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
