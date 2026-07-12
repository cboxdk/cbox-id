# Compliance

Compliance is a property of the **running system** — this deployable app — not of a
library in isolation. An auditor certifies *your Cbox ID deployment*, and that
deployment is `cbox-id`: the framework's controls **plus** what this app adds on top
**plus** the organizational controls only you can supply.

This page is the system-level view. It composes:

1. **Framework-provided controls** — the identity engine's crypto, tenancy, audit,
   OAuth/OIDC/SCIM/SAML and RFC conformance. These are mapped in detail in the
   framework docs — see
   [`cboxdk/laravel-id` → Compliance mapping](../../packages/laravel-id/docs/compliance.md)
   and its [threat model](../../packages/laravel-id/docs/threat-model.md) and
   [security model](../../packages/laravel-id/docs/security.md). **Don't duplicate that
   table — link to it.**
2. **App-layer controls this deployment adds** (below).
3. **Organizational controls that remain yours** (below).

## Framework controls (inherited — see laravel-id)

The framework mapping covers SOC 2, ISO 27001, NIS2, GDPR, HIPAA and PCI-DSS against
what the identity engine provides: AEAD-at-rest, alg-pinned tokens, key rotation, the
hash-chained tamper-evident audit log, deny-by-default tenancy, MFA/passkeys, SSRF-
guarded webhooks, and the standards conformance matrix.

→ **[Framework compliance mapping](../../packages/laravel-id/docs/compliance.md)** —
the authoritative control-by-control table. Everything there applies to this
deployment because this app composes that package.

## App-layer controls this deployment adds

These are provided by `cbox-id` (the host), not the framework — so they belong in
*this* mapping, not the framework's:

| Area | What this app adds | Relevant to |
|---|---|---|
| **Anomaly / abuse detection** | Request **risk-scoring** on signup/login via `cboxdk/laravel-risk` — weighted, explainable, monitor-mode by default → CAPTCHA / step-up / reject. | SOC 2 CC7.1–7.2, ISO A.8.16, GDPR Art. 22 (explainable, see [risk docs](../../packages/laravel-risk/docs/index.md)) |
| **Password hashing** | **Argon2id** (memory-hard, side-channel-resistant) as the app default, overriding the framework's bcrypt default. | ISO A.8.5, PCI-DSS 8.3, HIPAA §164.312(d) |
| **Session hardening** | Secure + encrypted cookies, central revocable sessions, idle timeout, sign-out-everywhere, step-up "sudo". | SOC 2 CC6.1/6.6, ISO A.8.2 |
| **Secure-by-default posture** | `cbox-id:doctor` enforces `APP_DEBUG` off and secure/encrypted sessions in production; fails the check otherwise. | SOC 2 CC6.1, NIS2 Art. 21(g) |
| **Key custody & recovery** | Documented crypto-key backup, signing-key rotation, and break-glass runbook. | ISO A.8.24, NIS2 Art. 21(h), GDPR Art. 32 |
| **Deployment evidence** | Reproducible install (`cbox-id:install`), health gate (`cbox-id:doctor`), dependency/CVE gate (`composer audit`). | SOC 2 CC7.1, ISO A.8.8 |

See [Configuration](configuration.md) for the settings behind these and
[Operations](operations.md) for key custody, rotation, and break-glass.

## What remains yours (organizational controls)

No software supplies these — they're process, not code. They're listed in full in the
framework mapping's
[*What remains yours*](../../packages/laravel-id/docs/compliance.md) section and apply
identically here: infosec policy and access-review cadence; data retention + DPIA
(including risk-scoring data if you enforce it); incident response and NIS2/GDPR
reporting timelines; independent assurance (the SOC 2 / ISO / HIPAA / PCI assessment
itself); penetration testing; and physical/network controls (hosting, egress
allow-list, backups, custody of the crypto master key).

## Evidence this deployment hands your auditor

- The [framework compliance mapping](../../packages/laravel-id/docs/compliance.md) and
  [standards conformance matrix](../../packages/laravel-id/docs/standards.md).
- The [framework security](../../packages/laravel-id/docs/security.md) and
  [threat model](../../packages/laravel-id/docs/threat-model.md) documents.
- A machine-readable **CycloneDX SBOM** and a passing dependency/license/vuln gate.
- A **tamper-evident audit trail** exportable as forensic evidence.
- This deployment's **secure-by-default config**, verifiable at any time with
  `php artisan cbox-id:doctor`.

## Where to go next

- [Configuration](configuration.md) — the secure defaults referenced above.
- [Operations](operations.md) — key custody, rotation, audit/SIEM, break-glass.
- Framework: [Compliance mapping](../../packages/laravel-id/docs/compliance.md),
  [Security](../../packages/laravel-id/docs/security.md).
