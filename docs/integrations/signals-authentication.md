---
title: Signals authentication assessment
weight: 40
description: Evaluate historical risk before ID issues a session or user token.
---

# Signals authentication assessment

This integration branch uses the optional assessment contract from `laravel-id` and the public `laravel-signals-client`. It is not enabled by default. No production credentials or deployment have been activated.

Set `SIGNALS_AUTH_ENABLED=true`, the exact `SIGNALS_AUTH_ID_ENVIRONMENT`, an HTTPS Signals API origin and a project/environment key with only the `evaluate` scope. The entity namespace defaults to `cbox-id` and must equal the namespace in the customer's mapping. The entity type is `user`; its identifier is ID's verified subject ID. Credentials for importing the public ID feed are separate and read-only.

Every session creation and every user-token issuance passes the package assessment hook. Machine credentials have no user to assess. The HTTP deadline is 80 ms including connection, without retries or redirects. Requests use server-owned subject, organization and authentication facts; no password, OTP or bearer token is sent as context. The device identifier is Radar's environment-scoped keyed pseudonym of the first-party device cookie. Another ID environment is never exported under the configured Signals key.

Only unexpired high/critical identity or access signals with ready coverage and usable data timestamps trigger a challenge. Unknown types, incomplete or degraded evidence do not manufacture a low-risk decision: ordinary ID policy continues. Transport, quota and service failures are audited as unavailable and also continue ordinary ID policy. Existing subject status, MFA mandates, authorization and independent sensitive-action freshness checks remain enforced.

Fresh strong authentication (ID's existing AAL2 definition, at most five minutes old) handles the signal without another challenge. Audit entries retain the assessment, handled signal revisions, decision and achieved assurance. Old, unknown or future authentication timestamps are not fresh proof. Hosted and embedded password flows reuse the existing MFA/passkey door. With no usable enrolled factor, ID does not issue a session; existing recovery remains necessary. Non-interactive session creation receives a refusal, and OAuth user-token grants receive `invalid_grant`.

The existing `user.session_started` event remains the final outcome, separate from assessment. It includes `user_id`, `session_id`, verified `amr`, `outcome=success` and the device pseudonym for the configured environment. Each actual login has its own durable event ID. Map `success` to the generic `identity.login` outcome `succeeded`; map `device_id` to `device`. A refused or pending assessment is never a successful login observation. The public event feed retains its environment/organization access controls.

Already issued JWT access tokens are not retroactively invalidated by this assessment. Offline validation continues until expiry. Use the existing revocation/introspection controls where required, or reevaluate at a later protected action. Refresh/user-token issuance invokes the hook again and does not treat unknown historical AMR as a fresh factor. This integration does not add a webhook-driven JWT revocation mechanism.

## Local verification and image preparation

Run the ordinary feature suite and `tests/Integration/signals-auth-roundtrip.py` from the Signals app checkout. The latter provisions a new isolated engine scope, imports customer-defined events via the public SDK, verifies actual high-risk password refusal and actual TOTP completion, and revokes its test API key. It requires the real local PostgreSQL/NATS/ClickHouse stack; it refuses a non-loopback engine. Add `--browser` to verify the hosted password/no-factor and actual TOTP flow, then read the final outcome from the public scoped event API and import it through the public Signals SDK. The proof waits for durable history before asserting ready coverage.

Before a reviewed image build, export source-only contexts from clean reviewed commits. The exporter refuses dirty checkouts and private/development paths; it records exact commit IDs:

```sh
python3 bin/prepare-signals-contexts.py --signals-client ../../laravel-signals-client --output /tmp/id-signals-contexts
docker buildx build --build-context signals-client=/tmp/id-signals-contexts/signals-client \
  -t cbox-id:signals-qualified .
```

The identity engine is installed from the published `laravel-id ^1.27.3` release. Only the Signals client still uses a reviewed development branch and compatibility alias. The image copies that client context at the path resolved by Composer. Its public release and production activation require their own authorization and verification.


### Reproducible integration CI

`build/dependencies/signals-packages.json` records the exact unpublished MIT source
commit and SHA-256 archive for the Signals client.
`php bin/unpack-signals-packages.php ../../laravel-signals-client` prepares its new Composer destination in a clean CI job;
existing destinations are refused. Image jobs unpack the same archives to named
BuildKit contexts. No sibling checkout, developer environment or credential enters CI.
The clean-checkout exporter remains available when reviewing a new source snapshot.

Host validation: 5,936 PHP tests / 61,129 assertions with one existing skip; 200
frontend tests, TypeScript and Vite production build. Wayfinder 0.1.23 preserves
method-qualified exports for multiple API verbs sharing a path and handles QUERY.
Vitest 5.1.0 removes the vulnerable development mock server; custom accessibility
matcher declarations follow its typed contract. No checks or assertions disabled.
Production dependency and npm audits report no known advisories.

Final frontend verification also includes the complete lint and format gates.
Existing portal rows now use their field identity instead of array position;
account-link forms retain the normal reading/focus order. Twenty-five existing
format deviations were normalized with the repository's formatter. All 200
frontend tests, TypeScript, lint and formatting pass. The reviewed image boots
configuration and route caches with networking disabled; enabling Signals remains
an explicit environment configuration, disabled by default.
