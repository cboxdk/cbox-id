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
