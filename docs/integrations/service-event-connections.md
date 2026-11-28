---
title: Organization event connections
weight: 70
description: Read organization login events with a centrally revocable service grant.
---

# Organization event connections

Register the consumer client in `CBOX_SERVICE_CONNECTION_CLIENTS`, allow its human
OAuth flow `service_connections.manage`, and its machine flow
`service_connections.lease`. Configure `CBOX_ID_CONNECTION_RESOURCE` to the exact
realm issuer. The `cbox_id_events` catalog entry grants only `signals:events:read`.
No environment management key is issued to the consumer.

An organization administrator authorizes the selected organization with PKCE and
calls `POST /api/v1/service-connections/cbox_id_events`. Its consumer can subsequently
lease through `POST /api/v1/service-connections/{id}/token`, even after that person
logs out. Use the lease at `GET /api/v1/service-connections/{id}/events`, with
`types[]`, `after` and `limit` (1–100). The response uses the same domain-event
shape and cursor as the public events API. An `organization_id` query parameter
is rejected: the grant fixes that boundary.

Every page requires the lease's service client, organization, scope and audience,
a live grant and active organization, and the continued registration of its
consumer. Central revocation or removing consumer trust stops existing leases
at the read endpoint. Platform events and other organizations are not returned.
The same contract is available to registered external consumers.

## Sites-managed Assets

The `cbox_assets` service grants exactly `assets:consumer:read` and
`assets:consumer:write` for `https://api.cboxassets.com` (not the `/mcp` resource).
It reuses the organization connection protocol; it grants no Assets dashboard
membership and creates no Assets subscription. Assets separately enforces the
Sites-funded capacity contract before accepting uploads.

### Registration and activation

The operator registers Sites' confidential OAuth client in
`CBOX_SERVICE_CONNECTION_CLIENTS` and sets `CBOX_ASSETS_SERVICE_CLIENT_ID` to the
Assets provider's confidential client. `CBOX_ASSETS_CONNECTION_RESOURCE` defaults
to the audience above. These are ID configuration, not customer-supplied values.
Set `CBOX_SITES_SERVICE_CLIENT_ID` to that same Sites client. Sites needs
`id:service-connection-provisions:write` for automatic provisioning and
`id:service-connection-leases:write` for leasing. Assets needs
`id:service-connections:read` for provider inspection. The legacy scope names
`service_connections.lease` and `service_connections.inspect` remain accepted for
those operations. `service_connections.manage` still authorizes person management,
never machine provisioning.

If `CBOX_ID_PROTECTED_RESOURCES` declares the Assets audience for MCP, the host
adds the two consumer scopes to that same declaration and reserves them against
dynamic client registration. Existing MCP scopes, resource settings and reserved
scopes remain unchanged. The reservation applies even before a provider client is
configured. Do not register a second API row at this audience or register the
consumer scopes under another API: registered APIs take precedence and would
change the existing MCP or lease grants. This needs no engine upgrade or data
migration; deploy the host change and rebuild its configuration cache normally.

Sites calls `POST /v1/service-connections/cbox_assets/provision` with
`{"organization_id":"org_<ULID>"}` (a bare ULID also works). Its client-credentials
token uses the issuer audience and the separate provisioning scope. No person
token, membership inference, MFA or consent is required. The SDK capability is
`ProvisionsServiceConnections::provision($organization, 'cbox_assets', $audience)`;
it requires `CBOX_ID_API_URL`. The scope is reserved against dynamic registration
and client metadata documents, and is not advertised on the person API resource.

Only the configured, non-dynamic, platform-owned confidential Sites client can
provision, only in the active platform root, and only to the configured confidential
Assets provider with the fixed audience and two scopes. ID requires the organization
to be active and admitted to **both** Sites and Assets. It checks Billing using two
production-environment/product-bound operator credentials with only `access:check`:

- `CBOX_SITES_CONNECTION_ADMISSION_TOKEN`, bound to `cbox-sites`.
- `CBOX_ASSETS_CONNECTION_ADMISSION_TOKEN`, bound to `cbox-assets`.
- `CBOX_WAITLISTS_BILLING_URL`, the trusted HTTPS Billing origin, without a path.

These credentials only read policy/check; do not use broader `access:enroll`,
`access:read` or admission credentials. Billing must deploy `access:check` support
before activation. ID sends the verified Sites client id as `subject` and the bare
organization id as `organization` to each `/api/v1/access/services/{key}/check`.
Only an organization-scoped service policy for the exact key and `allowed: true`
with reason `granted` or `subscription` passes. `open` is not admission. ID performs
no enrollment, grant creation in Billing, subscription or purchase. Billing may keep
positive decisions for 60 seconds with feature-epoch invalidation; ID adds no cache.

Repeated provisioning returns the same matching active connection. Revoked,
missing-backing-client or conflicting grants return 409; provisioning never revives
them. Denied caller/organization returns 403; unavailable or malformed eligibility
returns 503. Requests fail closed, without a person-connect or storage fallback.
Sites stores the connection id, never its backing credentials. Existing grants are
retained and leased; new-site creation and explicit backfill use the same operation.

The ordinary person-managed `ServiceConnections::connect()` and revocation paths
retain their administrator checks. Their explicit API audience support is unchanged;
provisioning, leasing and inspection retain issuer-audience tokens. Do not add a
shadow API registration or change global approval policy. The temporary operator
migration below describes the earlier migration mechanism, not automatic activation.

### Leasing and provider inspection

Sites' ID-audience machine token calls
`POST /v1/service-connections/{id}/lease`. The resulting bearer JWT has the
customer organization in `org`, the fixed Assets audience, and exactly the two
catalog scopes. Its `sub` and `client_id` both equal the connection's
`service_client_id`, **not** Sites' OAuth client id. The response contains
`access_token`, `token_type`, `expires_in`, `scope` and `audience`; retain the
token only server-side until its returned expiry. `ServiceConnections::lease()`
supports this without an SDK change.

Sites sends that bearer and `Cbox-Service-Connection: {id}` to Assets. After
validating JWT signature, issuer, expiry and audience, Assets must inspect the
connection on every request, including HEAD and Range, using its own ID-audience
machine token with `id:service-connections:read`:

```http
POST /v1/service-connections/{id}/inspect
Authorization: Bearer <Assets provider's ID-audience machine token>
Accept: application/json
```

An active response contains `active: true`, `id`, `organization_id`, `service`,
`consumer_client_id`, `audience`, `scopes`, `revoked_at: null` and
`service_client_id`. An inactive or absent connection returns
`{"active": false}`. Invalid credentials return 401; an unrelated provider or
consumer is refused with 403. Responses are `no-store`.

The connection `id` is currently a bare ULID on both API surfaces; pass back the
returned value unchanged. `conn_` belongs to SSO connections, not these grants.
On `/v1`, `organization_id` is `org_<ULID>` while JWT `org` stays bare: normalize
only that known organization prefix when comparing. Both client ids are opaque
OAuth protocol values and must never be stripped or rewritten.

Assets compares the connection id, organization, `service=cbox_assets`, audience
and service client against the verified token; requires machine `sub=client_id`
with no acting party; compares scopes as the exact catalog set, regardless of
order; and maps `consumer_client_id` through its trusted Sites client allowlist.
An active response alone is not enough. Only after those checks may Assets
idempotently mirror the organization and connection without adding a membership
or standalone subscription. A local binding may deny access but cannot replace
live ID inspection. An ID outage fails closed; no positive authorization cache.
A stream authorized before revocation may finish; the next request rechecks.

Provider inspection uses the HTTP contract above: the current SDK's
`ServiceConnections::status()` is consumer-only and rejects another client's
connection. It is not a provider inspection method.

### Revocation and rotation

`DELETE /v1/service-connections/{id}` with the authorized administrator token
retires the backing client and invalidates its existing tokens. Inspection then
returns inactive and new leases fail. Removing consumer trust, disabling the
organization or changing the catalog also stops live authorization.

Reactivation uses the same PUT as activation. It retains the connection id but
creates a **new service client**. Assets must revalidate and mirror the new tuple;
an old JWT cannot match the new `service_client_id` even if its expiry is later.
An explicitly revoked local binding remains an additional deny boundary. Never
rotate or disclose the backing client's secret: only the lease endpoint issues
its tokens. Rotation of Sites' own confidential credentials uses the normal ID
client-credential lifecycle and does not change its customer connections.

Before rollout, register the three scope grants and both trusted clients, verify
the no-consent Sites round trip, prove first-call provisioning and revocation
against Assets, and attest/approve the existing-organization inventory. The ID
regression in `tests/Feature/ServiceConnectionsTest.php` exercises the public API
with real signed tokens for two organizations, unrelated-client denial,
idempotency, revocation without collateral revocation, and reactivation without
reviving the old token; it does not prove the cross-service rollout.

## Temporary operator provisioning for existing Sites organizations

This capability only migrates the explicit inventory of already-admitted Sites
organizations. It is not product admission and cannot enroll an organization in
Sites. The infra operator must independently verify Sites' inventory and Billing
admission before installing the manifest. ID validates the attestation and current
organization standing, not Sites' database or Billing's admission ledger.

Set `CBOX_SITES_SERVICE_CLIENT_ID` to the registered Sites consumer and install
`CBOX_SITES_ASSETS_MIGRATION` as a JSON object with exactly these fields:

```json
{
  "environment_id": "<bare platform-root ULID>",
  "consumer_client_id": "<exact Sites OAuth client id>",
  "organization_ids": ["<bare admitted organization ULID>"],
  "evidence": "<verified inventory reference or digest>",
  "expires_at": "<UTC YYYY-MM-DDTHH:MM:SSZ>"
}
```

The example is a schema illustration, not deployable inventory. Use 1–50 distinct
organization ids, at most 300 characters of evidence, and a short explicit expiry.
Both client registrations must exist in that realm; the consumer must also match
the configured Sites client and existing consumer allowlist. ID fixes service
`cbox_assets`, audience `https://api.cboxassets.com` and the two consumer scopes.
Changing the catalog to broader scopes disables migration rather than widening it.
The provider client, realm, inventory, evidence and expiry all enter the plan digest.

The public Actions are `admin.service_connections.sites_assets.plan` (read) and
`admin.service_connections.sites_assets.provision` (critical). They require a real
platform operator's delegated token with `id:admin-service-connections:read` for
the plan and `id:admin-service-connections:write` for provisioning. The aliases
`operator:service-connections:read` and `operator:service-connections:write` also work.
There is no management-key, service-token or console bypass for provisioning.

1. GET `/v1/admin/sites-assets-migrations/plan` on the ID API host. The
   `plan` object lists every organization and the fixed grant shared by each,
   plus `manifest_digest`, provider/client ids, evidence and expiry. It writes
   nothing. Public organization/environment ids are accepted back by `/v1`.
2. POST `/v1/admin/sites-assets-migrations/provision` with
   `{"plan": <the exact returned plan>}` and a durable `Idempotency-Key`.
   HTTP 202 returns the approval itself (`object: "approval"`, `id: "idapr_…"`,
   `poll_url`); nothing was provisioned. The held approval contains the full
   bounded plan for the operator to inspect.
3. The real operator approves through ID's normal approval flow. Poll the returned
   approval URL, then repeat the identical body/key with `Cbox-Approval` set to
   that approval id. A changed plan cannot spend the approval.
4. A successful response contains `connections`, each with
   `object: "service_connection"`, a bare `id`, `organization_id` and
   `service_client_id`. No secrets or access tokens are returned. Install the
   connection references into Sites through its supported migration workflow;
   Sites uses its existing lease client from then on.

Execution revalidates the current manifest and all active organizations, locks the
organization rows in stable order, then creates the whole batch atomically through
ID's service-account domain service. Matching active grants are reused. Revoked,
missing-client or conflicting grants refuse the batch: an operator migration must
not undo a customer's revocation. Audit records identify the operator, approval,
manifest digest and inventory evidence. A spent approval cannot execute again;
an identical idempotency replay only returns the recorded result, not new grants.

Missing/expired configuration returns 503. A changed plan, inactive organization or
conflicting connection returns 409. A caller without operator authority or the
scope is refused. Legacy equivalents are below `/api/v1/platform/` on the issuer
host; `/v1` is preferred. The generated OpenAPI describes both.

After verified migration, remove `CBOX_SITES_ASSETS_MIGRATION` from deployment
configuration and remove these temporary operator scopes from migration credentials.
New organizations continue through the person-admin activation path. Removal of
the temporary Actions can follow once their rollout is complete; expiry/removal
of the manifest does not revoke the successfully created service connections.
Production execution remains blocked until infra supplies the verified inventory,
installs the expiring attestation and obtains the real runtime operator approval.
