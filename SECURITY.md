# Security Policy

Cbox ID is an identity platform, so we take security reports seriously. This policy
covers the `cboxdk/cbox-id` application. Vulnerabilities in the underlying framework
belong in the `cboxdk/laravel-id` repository instead.

## Reporting a vulnerability

Please report suspected vulnerabilities through **GitHub's Private Vulnerability
Reporting**:

1. Go to the repository's **Security** tab.
2. Choose **Report a vulnerability** to open a private advisory.

This keeps the report confidential between you and the maintainers until a fix is
available. Please do **not** open a public issue for a security problem.

When you report, include what you'd want if you were fixing it: affected
version/commit, a description of the impact, and the steps or a proof-of-concept to
reproduce.

## What to expect

This is a small, actively developed project. We handle reports on a **best-effort**
basis — we don't publish a guaranteed response-time or remediation SLA, and we'd
rather set no promise than one we can't keep. We'll acknowledge valid reports,
work with you on a fix, and credit you if you'd like once any fix is released.

We don't currently operate a bug-bounty program.

## Supported versions

The project is **pre-1.0** and pinned to a pre-1.0 framework (`cboxdk/laravel-id`).
Fixes land on the latest `main`; we don't backport to older tags. Run a current
checkout.

## Framework-level security posture

Much of the identity engine's security surface lives in the framework package. For
the invariants this app inherits — tenant isolation, the crypto kernel, the
tamper-evident audit log, and the STRIDE threat model — see the `cboxdk/laravel-id`
package docs:

- Security model:
  <https://github.com/cboxdk/laravel-id/blob/main/docs/security/_index.md>
- Threat model:
  <https://github.com/cboxdk/laravel-id/blob/main/docs/security/threat-model.md>
- Compliance mapping:
  <https://github.com/cboxdk/laravel-id/blob/main/docs/security/compliance.md>

## Operating securely

If you self-host, `php artisan cbox-id:doctor` checks the production hardening
posture (debug off, secure + encrypted sessions, extensions, keys). See the
operator [security notes](docs/security/_index.md) and
[configuration reference](docs/configuration/environment-variables.md).
