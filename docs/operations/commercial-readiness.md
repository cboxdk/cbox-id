---
title: Commercial launch checks
weight: 7
description: Configure and verify hosted billing, operator access and operational readiness before admitting paying customers.
---

# Commercial launch checks

Use the candidate commit in staging first. A passing test suite proves code paths; it does not prove your production mail, payment account, backup or capacity.

## Billing configuration

Cbox Billing owns payment accounts, prices, tax, invoices and subscriptions. Hosted ID checks current product admission and manual feature grants for the exact organization. Publishing definitions never grants a customer access. See [Central product Billing](../core-concepts/central-product-billing.md) and [Native product authorization](../native-product-authorization.md).

Configure separate scoped credentials through the deployment secret store:

```dotenv
BILLING_CLIENT_BASE_URL=https://your-billing-service.example
BILLING_CLIENT_API_TOKEN=<feature-read and financial-management credential>
BILLING_CLIENT_DEFINITION_TOKEN=<definition-publication credential>
CBOX_ID_PRODUCT_BILLING_TOKEN=<product-admission credential>
```

Run migrations, `php artisan id:native-product:sync`, then `php artisan billing:sync-definitions`. A failure stops release. These commands synchronize definitions only. Grant early access, app roles and product features manually in their authoritative services. The current Owner is resolved from live ID ownership; a shared organization Admin does not acquire native product Admin.

Run `php artisan cbox-id:doctor` under the actual production configuration. Its Billing check verifies the required URL and credential settings, not remote grants or payment functionality. Verify those separately with disposable accounts. Missing or unavailable central admission/feature decisions fail closed; no cached local plan or subscription projection authorizes a new project or environment. Identity authentication and personal account recovery remain separate from paid product access.

Environment creation checks the current central allowance inside the project transaction. A project can impose a lower limit, but cannot raise the central ceiling. The app does not run `billing:sync-catalog` or apply subscription webhooks to local access grants. Definition sync, financial changes and access assignment remain distinct operations.

## Payment and access acceptance in staging

Use Billing's sandbox/test gateway and disposable accounts. Never put real card details into fixtures.

1. Sign up and verify email. Confirm waitlisted users cannot enter the native product. Manually admit the exact organization, then explicitly assign the required product roles and feature grants.
2. Verify a Billing-only member can read financial information without receiving operational project data. Reader must not edit invoices or cards; Editor must not acquire Billing or member-management rights.
3. Save invoice details, including country and VAT number. Reload and verify persistence in central Billing. Test English, Danish and phone width.
4. Add a test card through the provider element; exercise 3-D Secure and its return path. Set another card as default, then remove the old card. Card setup performs a full document navigation so the billing-specific CSP is installed. The app never receives raw card data.
5. Revoke admission, app roles or feature grants, then repeat a request and queued human action. Confirm refusal without changing shared membership or another app's assignments. Repeat with a second organization and foreign row IDs.
6. Stop Billing and verify protected product operations fail closed with a useful error. Personal sign-in and account recovery must remain available. Restore service and verify the next decision uses current authoritative state.
7. If changing a subscription through the separate central Billing portal, verify its real preview, consent, idempotency and invoice outcomes there. Native ID does not expose an unimplemented plan purchase, cancellation or subscription reconciliation workflow.

## Operator access

All active platform operators must enroll TOTP or a passkey and complete strong authentication. This applies independently of the tenant's optional MFA policy. Enrollment remains available at Account; do not weaken the policy to recover an operator. Keep a second enrolled operator and your documented out-of-band recovery procedure.

Legacy delegated operator tokens without AAL2 are refused: sign the CLI/MCP client in again with MFA. Customer tokens retain their normal policy.

Operator organization URLs include `/admin/environments/{environment}/organizations`. A browser tab carries its own target. Old GET bookmarks redirect to the root view; old unscoped writes are refused. During rollout, reload old forms. Verify two tabs can edit different environments without retargeting one another.

## Operational evidence before opening sales

Record the commit, configuration revision, operator, date and result of each check:

| Check | Evidence to retain |
|---|---|
| Runtime | `cbox-id:doctor`, authenticated health status, scheduler/queue health, successful mail delivery and certificate checks |
| Recovery | Restore a recent production backup into an isolated database with the correct encryption keys; record recovery time, row counts and audit-chain verification. Follow [Upgrade rehearsal](upgrade-rehearsal.md). |
| Capacity | Exercise the actual production-shaped stack with representative tenant sizes and authentication traffic; record throughput, p95/p99, error rate, DB CPU/locks and queue lag. The read probe below is only a baseline. |
| Own services | Register an ordinary client through public management APIs; test authorization-code + PKCE, callback, refresh, logout, membership changes and key rotation from each service. Follow [Live smoke test](live-smoke-test.md). |
| Payment | Complete the staging acceptance cases above, including access revocation and failure/recovery. Then verify the live seller/account configuration before enabling real purchases. |

The backend and browser suites provide repeatable regression coverage. They are not a substitute for an actual restore or a real service/payment acceptance run.

## Read latency baseline

The bounded probe uses GET only, follows no redirects, prints no credentials or response bodies, and exits nonzero if a request fails or the supplied p95 budget is exceeded:

```bash
node scripts/read-latency.mjs --url https://staging-environment.example/.well-known/openid-configuration --requests 200 --concurrency 10 --p95-ms 500
```

For an authenticated read, set `CBOX_READ_PROBE_TOKEN` through the process environment and point at a read endpoint. Use a staging-scoped key. Run each relevant route separately, retain the JSON results and correlate them with telemetry. Choose your latency budget and target concurrency before acceptance; a fast health endpoint does not prove sign-in capacity.
