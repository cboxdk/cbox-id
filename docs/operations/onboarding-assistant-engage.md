# ID onboarding: Assistant and Engage

## Implemented in this change

- `IntegrationProgress` reads the existing environment checklist. The console's
  `integrationProgress`, signed Assistant tool `id_onboarding_status` and authenticated
  `GET /api/v1/onboarding` consume that same evidence. Management API scope: `signin:read`.
- The Assistant tool rechecks organization membership, product admission and accessible
  environments on every call. It returns no credentials or customer profiles.
- A curated onboarding guide is generated from maintained docs into the Assistant
  manifest. It explains Free/no-card start, scope, existing users, SSO, SCIM and detection.
- `onboarding:sync` reconciles active project environments independently of page visits,
  records organization/company snapshots through `cboxdk/laravel-engage-client`, and never
  sends email. Tests and local defaults have collection disabled and no ingest credentials.
- Events include project/environment identity, goal booleans and the platform-root
  namespace. No email, password, person name or end-user identity is included.

`first_sign_in` means a person token/session was observed. `go_live` is the existing
HTTPS-redirect configuration check. Neither proves successful application account
linking, logout, tenant isolation, SCIM deprovisioning or production readiness.
The new snapshot explicitly reports `production_ready: null`.

## Configuration and identity contract

Before enabling `ID_ONBOARDING_ENGAGE_ENABLED=true`, configure `ENGAGE_INGEST_URL`,
`ENGAGE_INGEST_KEY` and `ENGAGE_PRODUCT=cbox-id` in the deployment secret/config store.
The ingest source must map **platform-root** Cbox ID organizations, not a consumer
product issuer or the environments' customer organizations. Verify the source mapping
with an authorized test organization. Do not merge namespaces by email or organization name.

The scheduler runs reconciliation every minute. Events use stable IDs for the same
scope, observation minute and goal values. A change within the same minute receives a
new ID. Queue retries reuse the envelope; observe failed/refused ingest and stale
snapshots. A new heartbeat is evidence, not a new campaign enrollment. Never use the
heartbeat event directly as an unbounded automation trigger.

The command reports queued observations, not receipt by Engage. SDK delivery retries
and current-state reconciliation are separate from the live API's authoritative read.
The API response must not be cached as the final send decision.

## Required Engage work before customer mail

Engage now implements source/project/environment correlation for reentry, goals, exits
and event waits. It checks persisted run state again at send time, including normally
completed runs with queued messages, changed recipients and achieved goals. Its editor
preserves correlation and eligibility settings.

For first-login reminders set `eligibility_check: cbox_id_first_sign_in_pending`,
`correlation_keys: [project_id, environment_id]` and `reentry: never`. The completed-goal
event is `onboarding.first_sign_in_observed`. ID emits it during reconciliation once a
person sign-in is observed; it is evidence, not authorization for a congratulations mail.

The dedicated `POST /api/v1/onboarding/eligibility` requires a service bearer key of at
least 32 characters (`ID_ONBOARDING_ELIGIBILITY_KEY`). It checks current membership and
write access, product admission, ownership/status, dismissal and incomplete first login.
It returns only eligibility and observation time, with `Cache-Control: no-store`.
Engage must configure its organization, event source, platform-root identity source, URL
and matching key; see Engage's `docs/onboarding-eligibility.md`. A failed check refuses
that message rather than sending or indefinitely retrying it.

Remaining activation acceptance:

1. Verify the platform-root source namespace and configure an events-only ingest key.
2. Verify the Assistant support-activity endpoint in production. ID checks it on every
   eligible reminder and refuses on outage, missing configuration or open/snoozed cases.
   The pause is conservative: any active case for the same product, identity namespace
   and organization pauses reminders for the whole team until the case is closed.
3. Configure brand/domain, consent, caps, quiet hours, templates and scoped next links.
4. Run a controlled-recipient live test, including completion after enqueue and removed
   access. The local tests cover these guards but do not prove production wiring.

`resources/onboarding/engage-journeys.json` remains a draft specification, not an
importable or published campaign. No unbounded enrollment from heartbeat events.

No campaigns have been published and no outbound customer mail has been enabled by
this change. No production credentials have been created or modified.

## Free account setup

Hosted rollout should keep `CBOX_ID_REQUIRE_SUBSCRIPTION=false`: product admission and
organization verification remain enforced, but project/environment creation must not
require checkout. The Free allowance is currently two environments; identity-security
features use inclusive entitlements. Signals limits remain separately metered. Preserve
explicit operational suspensions and feature denials. A paid upgrade requires an
intentional purchase; no card, VAT address or payment method belongs in first-run setup.

Do not silently disable an explicitly selected subscription-admission policy on an
existing deployment. Verify the candidate's configuration and complete the no-card
signup-to-first-login path. Early-access invitation policy is separate from payment.

## Live acceptance still required

On the candidate revision, confirm signup/admission and a Free project without payment
details; synchronize the new Assistant manifest/knowledge; ask for the real onboarding
status and follow its citation; verify a human reply. Observe the test organization's
Engage event with the correct realm/project/environment. Publish only after the above
Engage guards and a controlled authorized-recipient delivery test pass. Local feature
tests do not establish live readiness and these additions do not implement the full
clickable onboarding prototype.
