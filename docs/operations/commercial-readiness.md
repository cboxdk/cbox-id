---
title: Commercial launch checks
weight: 7
description: Configure and verify hosted billing, operator access and operational readiness before admitting paying customers.
---

# Commercial launch checks

Use the candidate commit in staging first. A passing test suite proves code paths; it does not prove your production mail, payment account, backup or capacity.

## Billing configuration

Cbox Billing owns prices, tax and subscription state. Publish a **Cbox ID** catalog in Billing with a product key matching `CBOX_BILLING_PRODUCT` and an integer `cbox-id.environments` feature on every plan. Prices, free allowances and usage rates must be decided in that catalog. There is no application fallback price.

Set these through the deployment secret store:

```dotenv
CBOX_ID_ENTITLEMENTS=inclusive
CBOX_ID_REQUIRE_SUBSCRIPTION=true
CBOX_BILLING_ENABLED=true
CBOX_BILLING_PRODUCT=cbox-id
CBOX_BILLING_CURRENCY=DKK
CBOX_BILLING_ENVIRONMENT_FEATURE=cbox-id.environments
BILLING_CLIENT_BASE_URL=https://your-billing-service.example
BILLING_CLIENT_API_TOKEN=<product-bound management token>
CBOX_BILLING_WEBHOOK_SECRET=<shared webhook signing secret>
```

Keep `CBOX_ID_REQUIRE_SUBSCRIPTION` disabled during a waitlist-only pilot until approved customers have a usable private plan and the payment acceptance below has passed. Enabling it against an inactive or empty public catalog blocks project creation. `inclusive` grants the known ID security features independently of a paid tier; it does not grant arbitrary application features or bypass service admission.

The Billing deployment must support `customer_account` upserts that ignore the caller’s `name` when reading an existing account and apply explicit `invoice_name` edits. Verify saving an invoice name and then reloading preserves it; the SDK fake does not implement this server rule.

Use a token restricted to the Cbox ID product. Subscriptions use the workspace organization as payment account and the project ID as `reference`. Cards and invoice details belong to the payment account and are shared across projects. Buyer-specific prices use that account’s locked currency; `CBOX_BILLING_CURRENCY` selects the catalog synchronization currency, not a forced currency for existing buyers.

Run migrations, then `php artisan billing:sync-catalog --subscriptions`. Run `php artisan cbox-id:doctor` under the **actual production configuration**. The subscription-admission check fails on missing secrets, an empty/invalid catalog or a subscription reconciliation older than 15 minutes. The scheduler reconciles every five minutes. The signed webhook at `POST /api/billing/webhook` queues the same reconciliation. Configure Billing to deliver it with `X-Cbox-Signature: t=<unix>,v1=<HMAC-SHA256(timestamp.raw_body)>`.

New projects requiring a subscription start with **zero** environment allowance. A synchronized active or trialing subscription applies the catalog allowance. The workspace API cannot choose the allowance. Missing subscriptions, unknown plans and transport failures preserve existing grants. An explicit ended subscription sets the new-environment allowance to zero; existing environments and identity data remain intact. Payment failure does not delete customer identity infrastructure. Decide the support/suspension policy separately before selling a contractual service.

`CBOX_ID_ENTITLEMENTS=open` is the self-hosted mode. It does not enforce commercial feature entitlements. Do not sell usage limits or feature tiers until those exact meters/feature projections have been configured and verified end to end. The project allowance integration alone does not enforce a users/logins quota.

The catalog retains retired plans for existing subscriptions. An empty refresh fails without erasing it. A subscription refresh watermark advances only after every page succeeds. After a service outage, rerun synchronization and check the doctor; do not reset stored grants to recover.

## Payment acceptance in staging

Use Billing's sandbox/test gateway and test customer accounts. Never put real card details into fixtures.

1. Sign up, verify email, open Billing, and save invoice details, including country and VAT number. Confirm the validation result. Check English, Danish and phone width.
2. Compare plans. Verify the shown currency, interval, tax-inclusive total and environment allowance against Billing. Test both a free plan and first paid checkout with no card.
3. Add a test card through the provider element. Test 3-D Secure and its return path. Set another card as default, then remove the old card. The application never receives raw card data. Card setup performs a full document navigation so the billing-specific CSP is installed even when arriving through Inertia.
4. Change plans with an existing card. Check the preview, prorated charge, recurring amount and bundle consequences. Submit twice and retry after a simulated lost response: there must be one remote change/charge. A changed quote must require a new confirmation.
5. Cancel at period end, check the end date, then resume renewal. Only active, unpaused subscriptions with a period-end cancellation can resume here; paused or ended contracts require a separately priced offer. Verify no existing environments disappear. Check invoices against Billing.
6. Stop Billing, load the console, and verify existing login/service access continues. Send duplicate, stale and invalid-signature notifications. Restore Billing, run reconciliation and verify the correct project changes.
7. Use a second workspace and a read-only member to verify that project, card and confirmation endpoints cannot cross accounts or bypass role/email checks.

## Operator access

All active platform operators must enroll TOTP or a passkey and complete strong authentication. This applies independently of the tenant's optional MFA policy. Enrollment remains available at Account; do not weaken the policy to recover an operator. Keep a second enrolled operator and your documented out-of-band recovery procedure.

Legacy delegated operator tokens without AAL2 are refused: sign the CLI/MCP client in again with MFA. Customer tokens retain their normal policy.

Operator organization URLs include `/admin/platform/environments/{environment}/organizations`. A browser tab carries its own target. Old GET bookmarks redirect to the root view; old unscoped writes are refused. During rollout, reload old forms. Verify two tabs can edit different environments without retargeting one another.

## Operational evidence before opening sales

Record the commit, configuration revision, operator, date and result of each check:

| Check | Evidence to retain |
|---|---|
| Runtime | `cbox-id:doctor`, authenticated health status, scheduler/queue health, successful mail delivery and certificate checks |
| Recovery | Restore a recent production backup into an isolated database with the correct encryption keys; record recovery time, row counts and audit-chain verification. Follow [Upgrade rehearsal](upgrade-rehearsal.md). |
| Capacity | Exercise the actual production-shaped stack with representative tenant sizes and authentication traffic; record throughput, p95/p99, error rate, DB CPU/locks and queue lag. The read probe below is only a baseline. |
| Own services | Register an ordinary client through public management APIs; test authorization-code + PKCE, callback, refresh, logout, membership changes and key rotation from each service. Follow [Live smoke test](live-smoke-test.md). |
| Payment | Complete the staging acceptance cases above, including failure/recovery and webhook delivery. Then verify the live seller/account configuration before enabling real purchases. |

The backend and browser suites provide repeatable regression coverage. They are not a substitute for an actual restore or a real service/payment acceptance run.

## Read latency baseline

The bounded probe uses GET only, follows no redirects, prints no credentials or response bodies, and exits nonzero if a request fails or the supplied p95 budget is exceeded:

```bash
node scripts/read-latency.mjs --url https://staging-environment.example/.well-known/openid-configuration --requests 200 --concurrency 10 --p95-ms 500
```

For an authenticated read, set `CBOX_READ_PROBE_TOKEN` through the process environment and point at a read endpoint. Use a staging-scoped key. Run each relevant route separately, retain the JSON results and correlate them with telemetry. Choose your latency budget and target concurrency before acceptance; a fast health endpoint does not prove sign-in capacity.
