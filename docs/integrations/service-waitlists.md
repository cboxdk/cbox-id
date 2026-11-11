---
title: Service waitlists
weight: 20
description: Let people join published service waitlists through ID or a public email signup page.
---

# Service waitlists

The optional **Services** page under **Account** lets a signed-in person join a service’s early-access waitlist. The person needs a verified email, but does not need an organization. Cbox Billing owns the entries, FIFO order, invitations and resulting service entitlements. ID owns identity and team membership. Joining a waitlist does not buy a plan, grant access or join someone else’s team.

## Configure the connection

1. In Billing, enable its Product access module and grant the owning tenant the `plugin.product-access` entitlement through its plan or an explicit override. Deploy its migrations and keep the scheduler running.
2. Create a programme for each product, configure its questions and acceptance URL, set `waitlist` mode and publish it. Existing programmes stay private until published.
3. Issue a Billing operator API token with **only `access:enroll`**, bound to the environment containing the programmes. For the common directory, leave its product scope unbound; a product-bound token sees only that service. Do not use a full management token.
4. Configure ID:

```dotenv
CBOX_WAITLISTS_ENABLED=true
CBOX_WAITLISTS_ID_ENVIRONMENT=your-cbox-identity-environment
CBOX_WAITLISTS_BILLING_URL=https://app.cboxbilling.com
CBOX_WAITLISTS_BILLING_TOKEN=your-server-side-token
```

Use the Billing HTTPS origin, without a path, credentials or query. Local development also accepts loopback HTTP. Store the token through the deployment’s secret mechanism, never frontend variables. Refresh the application’s configuration cache after a configuration change.

The explicit identity environment prevents hosted customer IdP environments from inheriting Cbox’s directory. With the flag off or a different environment active, the navigation entry disappears and the routes return 404. Billing independently checks the module entitlement on every request. Missing configuration or an unavailable Billing server produces a recoverable unavailable page; a failed signup is never reported as successful.

## Link from a service

Build the primary signup/waitlist experience in the service using Billing’s native service registration API. Authentication always redirects to ID and returns to that native page. Use `/account/services` for the shared fallback directory, or `/account/services?service=telemetry` for one product key. Authentication preserves the intended page. Published programmes are read dynamically, 50 per page, so adding a service in Billing requires no ID release. ID renders the configured questions and displays waiting, invited or accepted status.

Every service card also links to Billing’s public `/billing/waitlists/{public_id}` page. Apps can link there directly for people without an ID account. For guest programmes the public page emails a confirmation link; the person must explicitly confirm before an entry is created. No account is created by this flow. Billing applies request limits, retries delivery and expires confirmation links after 24 hours.

Native pages and both fallback entry points write to the same waitlist. Programmes choose guest versus login-required enrollment, hidden/optional/required country, and string/textarea/select/multiselect questions with validation. Country controls use the complete country/territory list and display searchable names. Duplicate normalized emails retain their FIFO position and original answers. The ID backend always supplies the subject and verified email from its authenticated session, ignoring any browser-supplied identity. The directory API token cannot issue invitations or modify grants. Personal registration lookups are sent in a POST body rather than a URL.

## Personal API and MCP

The same signup action is available as `account.waitlists.join` over MCP and `POST /api/v1/me/services/{program}/waitlist`, where `{program}` is the programme's Billing id (`acp_…`). It requires a person’s delegated token with `account:waitlists:write`; management keys cannot act as a person. Supply a stable UUID `reference`, optional `country` and `answers`. Subject and verified email are always resolved from the token’s person. The feature switch and configured ID environment apply here too. The action delegates to Billing using the same durable reference as the console.

## Invitations and automation

A team invitation is still an ID membership operation. A service invitation is a Billing operation and grants access in the recipient’s own chosen organization after acceptance. Billing retains inviter-to-recipient attribution for a later referral reward. Apps or Engage decide when to award invitation allowances; the directory does not implement a separate automation engine.

The app’s acceptance backend must verify the recipient’s identity, email and organization membership before calling Billing’s redemption API with its separately authorized management token. The narrow directory token cannot redeem or grant access. A service uses a separate product-bound operator token with `access:enroll` and `access:admit` for the service admission API.

## Feature waitlists

The **Features** tab at `/account/services?kind=feature` lists published feature programmes. Link to `/account/services?kind=feature&service=telemetry` from an app. Billing supports several feature programmes per service alongside its service waitlist. Create these with `kind: feature`, a boolean entitlement, a public name and description, and immutable access scope `person` or `organization`.

A verified existing customer chooses an active team and **Notify me** or **Join the test group**. ID checks membership and team status on the server, including for delegated API calls. Feature interest is per person and team; choosing another team shows that team's registration. The programme's scope tells the customer whether admission covers just them or their whole team. Features have no anonymous signup page.

`account.waitlists.join` additionally accepts `organization` and `interest` (`notify` or `test`). `account.waitlists.leave`, also exposed at `POST /api/v1/me/services/{program}/waitlist/leave`, takes `organization` and a fresh stable UUID `reference`. Both derive the person from authentication. Withdrawal removes only their interest and invalidates a saved beta selection. Active beta access is managed by the service operator; revoked access is shown explicitly.

Billing excludes notification-only signups from beta cohorts, counts each team once for team programmes, and activates existing customers without an acceptance URL. The customer's plan must include the feature. A beta can restrict commercial access, never expand it. For a personal beta, the app uses Billing's single-feature endpoint with the authenticated `actor`; organization-only flags cannot express personal admission. App permissions and membership still apply. See Billing's Product access guide for the enforcement and cache contract.

This feature choice concerns that feature's updates or test group. It does not imply general marketing consent. Engage handles contact synchronization and topic communication independently.

## ID accounts versus the ID product

`/signup` creates a person in Cbox ID; it does not create a project, environment or
subscription. Plain identity teams are also independent of product admission.
`/start` is the branded signup/waitlist page for customers of the ID product. Its
invitation flow admits the verified person's chosen team before product provisioning.
Project and environment writers enforce admission through both the browser and API.

Set `CBOX_ID_PRODUCT_ACCESS_ENABLED=true` and `CBOX_ID_PRODUCT_BILLING_TOKEN` to a
server-only Billing token bound to the `cbox-id` product, with `access:enroll` and
`access:admit`. The connection uses `CBOX_WAITLISTS_BILLING_URL` and the configured
identity environment above. Keep `CBOX_WAITLISTS_BILLING_TOKEN` restricted to enroll:
the cross-service directory never receives admission authority. Set the programme's
acceptance URL to ID's `/start` page.

When several live identity accounts are signed in, ID asks which one to use. One
live account continues SSO; explicit account switching always opens the chooser.
Expired and revoked sessions cannot be selected. This identity choice is separate
from selecting a team within an account.
