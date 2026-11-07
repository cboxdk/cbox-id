# ID onboarding: Assistant and Engage

## Implemented in this change

- `IntegrationProgress` reads the existing environment checklist. The console's
  `integrationProgress`, signed Assistant tool `id_onboarding_status` and authenticated
  `GET /api/v1/onboarding` consume that same evidence. Management API scope: `signin:read`.
- The Assistant tool rechecks workspace membership, product admission and accessible
  environments on every call. It returns no credentials or customer profiles.
- A curated onboarding guide is generated from maintained docs into the Assistant
  manifest. It explains Free/no-card start, scope, existing users, SSO, SCIM and detection.
- `onboarding:sync` reconciles active project environments independently of page visits,
  records workspace/company snapshots through `cboxdk/laravel-engage-client`, and never
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
with an authorized test workspace. Do not merge namespaces by email or organization name.

The scheduler runs reconciliation every minute. Events use stable IDs for the same
scope, observation minute and goal values. A change within the same minute receives a
new ID. Queue retries reuse the envelope; observe failed/refused ingest and stale
snapshots. A new heartbeat is evidence, not a new campaign enrollment. Never use the
heartbeat event directly as an unbounded automation trigger.

The command reports queued observations, not receipt by Engage. SDK delivery retries
and current-state reconciliation are separate from the live API's authoritative read.
The API response must not be cached as the final send decision.

## Required Engage work before customer mail

The inspected automation contract supports subject-level goal events and exits, but
not project/environment correlation on those exits or a final service-state guard.
An organization can have several projects: completing one must not stop the wrong
journey, and opening another must not restart onboarding for every member.

`resources/onboarding/engage-journeys.json` is an explicit **draft specification**, not
an importable automation or a claim that those capabilities are installed. It includes
a finite reminder sequence, once-only milestone message and relevant SSO discovery tip.

Implement/verify the following in Engage before publishing:

1. Correlation on workspace, project and environment for enrollment, goals and exits.
2. Current-state check immediately before provider dispatch; defer if unavailable or
   stale. Recheck goal, applicable resource, responsible member, consent/preferences,
   support pause, caps and quiet hours. Deduplicate the actual send atomically.
3. Completion by another member cancels shared-goal reminders; completion in another
   project does not. Do not replay old milestones as new congratulations.
4. Resolve the intended source, brand, templates, purpose and authorized recipients.
   Do not route educational messages through transactional mail fallback.
5. Test completion between enqueue and dispatch, access removal, opt-out, delayed/out-of-
   order events and two-project isolation. Events alone cannot prove these stop rules.

No campaigns have been published and no outbound customer mail has been enabled by
this change. No production credentials have been created or modified.

## Free account setup

Hosted rollout should keep `CBOX_ID_REQUIRE_SUBSCRIPTION=false`: product admission and
workspace verification remain enforced, but project/environment creation must not
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
status and follow its citation; verify a human reply. Observe the test workspace's
Engage event with the correct realm/project/environment. Publish only after the above
Engage guards and a controlled authorized-recipient delivery test pass. Local feature
tests do not establish live readiness and these additions do not implement the full
clickable onboarding prototype.
