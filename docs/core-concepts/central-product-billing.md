# Identity product access and billing

Customer ID product features come from Cbox Billing for the exact workspace organization. The published catalog contains `cbox-id.sso`, `cbox-id.scim`, `cbox-id.audit_logs` and the integer `cbox-id.environments` allowance. Publication creates definitions only; operators assign grants separately. Missing, disabled, suspended or malformed grants deny access. An unavailable service cannot authorize a new operation.

`cbox-id.environments` is the maximum **per project**, not an aggregate workspace allowance. A project's local allowance may be lower, but cannot raise the central limit. Customer project creation and environment creation check the current grant under their database locks. Operator infrastructure provisioning remains separate.

A directory organization consumes the grant of its owning project workspace. Its own organization identifier cannot select another billing account. The shared identity entitlement reader and OAuth claim projection remain independent: they hold customer applications' definitions and are not replaced by the ID product's feature authority.

The financial page reads central account, subscription, card and invoice data. Every operation requires the current native ID billing capability and an explicit matching workspace identifier. Invoice downloads pass through the app's current authorization check; signed storage URLs are not exposed. A first card becomes default only after the payment provider confirms it and the central account lists it.

## Publication and credentials

Release migration jobs run `migrate --force`, `id:native-product:sync`, then `billing:sync-definitions`. The latter publishes `billing-definitions.json`; failure stops the job. `BILLING_CLIENT_DEFINITION_TOKEN` must be bound to product `cbox-id` with `definitions:write`. It is separate from hosted admission credentials and from `BILLING_CLIENT_API_TOKEN`, which has the scoped customer financial, feature-read and usage-ingestion permissions. `BILLING_CLIENT_BASE_URL` is the central service URL. No command assigns customer grants, roles or prices.

### Kubernetes secret references

The checked-in `cbox-infra/infra/kubernetes/cbox-id/app.yaml` loads ConfigMap `cbox-id-env` and Secret `cbox-id-env` in namespace `cbox-id` for web, worker and the migration job. The release job uses that same environment. Provision these exact keys through the infrastructure secret workflow; never commit token values to this repository, a ConfigMap, an image, or frontend variables:

| Environment key | Required value and authority |
| --- | --- |
| `BILLING_CLIENT_BASE_URL` | Central Billing HTTPS origin, without credentials or query parameters. A ConfigMap value is appropriate. |
| `BILLING_CLIENT_DEFINITION_TOKEN` | Secret key with this exact name in `cbox-id/cbox-id-env`; Billing token bound to product `cbox-id`, with only `definitions:write`. |
| `BILLING_CLIENT_API_TOKEN` | Separate secret key; scopes `enforce`, `organizations:write`, `subscriptions:read`, `payments:read`, `payments:write`, `invoices:read`. Bind its Billing environment and permitted customer organizations to this deployment. `enforce` covers live features and raw usage event ingestion. Do not grant definition publication or entitlement assignment. |
| `CBOX_ID_PRODUCT_BILLING_TOKEN` | Separate product admission secret bound to `cbox-id`; `access:enroll` for policy/check/waitlist and `access:admit` for verified onboarding. It does not publish definitions. |
| `CBOX_WAITLISTS_BILLING_TOKEN` | Separate root identity directory enrollment secret (`access:enroll`), used only with the configured root identity environment. It is not the native product publisher or financial credential. |

The application configuration reads each credential independently; there is no fallback from the definition token to an admission or customer financial token. Auto-discovery of `Cbox\Billing\Client\ClientServiceProvider` registers `billing:sync-definitions`. The default file is the application root's `billing-definitions.json`; product ownership comes from the publisher credential, and all 4 feature keys and 31 meter keys begin `cbox-id.`. `php artisan billing:sync-definitions --validate-only --no-interaction` validates the file without any HTTP request or customer mutation.

The current network policy permits public HTTPS egress but does not allow a private Billing cluster service. Use the reviewed public Billing HTTPS origin; an internal service URL requires an explicit reviewed network-policy change. This audit verifies references and local validation only: it does not inspect or provision live Kubernetes Secret values. Missing or incorrectly scoped publication credentials make the migration job fail before rollout.

## Measured usage

Everything Cbox ID knows about a customer's use is measured, whether or not a plan prices it. Plans decide what is included, unlimited or billed; measuring does not. `App\Platform\Billing\UsageMeters` is the single list, and `billing-definitions.json` must equal it (a test holds them together). Every meter is published with `"source": "events"`: central Billing refuses raw events on a totals-fed meter, and a published meter's measurement can never change, so a different measure is a new key.

| Kind | Meters | Aggregation |
| --- | --- | --- |
| Activity, one event each | `sign_ins` (dimension `method`: password, passkey, sso, social, magic_link, otp, handoff, …), `sso_sign_ins`, `users_created`, `identity_links`, `organizations_created`, `members_added`, `members_removed`, `invitations_sent`, `invitations_accepted`, `role_assignments`, `service_accounts_created`, `ciba_requests`, `domains_verified`, `governance_campaigns`, `directory_provisions` | `sum` |
| People | `monthly_active_users`: the distinct user of every started session | `unique_count` |
| Activity, counted hourly from rows | `access_tokens_issued`, `refresh_tokens_issued`, `otp_codes_sent`, `mfa_enrollments`, `passkeys_registered` | `sum` |
| Stock, hourly snapshot | `users` (active), `organizations`, `memberships`, `environments`, `sso_connections`, `social_connections`, `directories`, `verified_domains` (verified), `apps`, `api_keys` (unrevoked, unexpired) | `max`: the period's peak |

All keys are prefixed `cbox-id.`. Activity events also carry the `environment` dimension. A session minted by a handoff (console to tenant admin, the app switcher) is counted as `method=handoff`, not as somebody signing in again. Root identity and operator infrastructure traffic has no customer project and is never measured.

Delivered domain events are copied to `product_billing_usage_outbox`, attributed through their environment's project owner. `billing:snapshot-product-usage` runs hourly at :05: stock at the top of the hour, and row-based activity for the hour that just closed, dated at its last second. Billing accepts an event at most its usage grace window back (`CBOX_BILLING_USAGE_GRACE`, one hour by default), so older hours cannot be caught up. Every row has a stable id per workspace, meter and hour, so reruns and catch-up never count twice. `billing:report-product-usage` runs every minute and sends one batch per workspace (up to 1,000 events). Central ingestion deduplicates by id. A rejected row stays pending with Billing's reason (`archived_meter`, `unknown_meter`, …) in `last_error`; a `too_old` row can never be accepted and is closed with that reason kept. Rows that have never failed go first, so a stuck row cannot hold the others back. No billing network call runs in identity requests.

The first meters (`cbox-id.auth.*`) were published without a source and became totals-fed, so Billing refused every event. They are retired by omission from the file; pending rows moved to their successors in migration `2026_11_08_010000`.

Local validation currently uses the shared unreleased Billing SDK source overlay for definition publication, feature snapshots and raw usage events. The installed released SDK must be updated to the corresponding release before deployment; no SDK or application has been released by this work.
