---
title: Webhooks
weight: 60
description: Receive signed notifications when something happens in your organization — which events exist, how to verify the signature, and how retries behave.
---

# Webhooks

**Console page:** Developers › Webhooks, in an organization's console and in an environment console

A webhook endpoint is a URL of yours that Cbox ID posts to **after** something
happens: a member was added, a user signed in, a directory deactivated somebody.
Your systems find out as it happens instead of polling, and — importantly — a
webhook is a notification, not a vote. Your endpoint is told; it cannot hold
anything up or refuse. If you need a say in the outcome, you want a
[hook](inline-hooks.md) instead.

## Events you can subscribe to

The picker lists every event Cbox ID sends. The list comes from the catalogue in
`cboxdk/laravel-id` ([webhook events reference](https://github.com/cboxdk/laravel-id/blob/main/docs/reference/webhook-events.md)).
Common ones:

| Event | Fires when |
| --- | --- |
| `user.created` | A person is created in this organization |
| `user.login` | A person signs in |
| `identity.linked` | An external identity is linked to a person |
| `membership.created` | Someone joins the organization |
| `membership.updated` | A member's role changes, including an ownership transfer |
| `membership.deleted` | Someone is removed from it, or leaves |
| `invitation.created` / `invitation.accepted` / `invitation.revoked` | An invitation's lifecycle |
| `organization.deleted` | The organization is closed |
| `directory.user.provisioned` | A directory sync created or updated a person |
| `directory.user.deactivated` | A directory sync deactivated a person |

**Older names.** `organization.member_added`, `organization.member_removed` and the other
`organization.member_*` and `organization.invitation_*` events are still sent to endpoints
that subscribe to them. They are no longer offered for new endpoints. An endpoint
subscribed to `*` receives both names for the same change, so count on one family only.

## Set one up

1. **Add endpoint**, give it your HTTPS URL, and tick the events it should receive.
2. Copy the **signing secret**. It is shown once.
3. Verify every delivery against that secret before acting on it (below).
4. Make something happen that the endpoint subscribes to, such as inviting a test
   member, and confirm the delivery arrives and verifies before you rely on it. There is
   no "send test event" button.

An endpoint in an organization's console carries that organization's events only. One
created in an environment console can instead be **environment-wide**, carrying every
organization's events, which is why the API makes you say which you mean.

From your backend or an agent, the same lifecycle is a set of [actions](../core-concepts/actions.md),
on the management API, MCP and the CLI alike:

| Action | REST | Scope | Danger |
|---|---|---|---|
| `webhooks.list`, `webhooks.get` | `GET /api/v1/webhooks`, `GET /api/v1/webhooks/{id}` | `webhooks:read` | read |
| `webhooks.create` | `POST /api/v1/webhooks` | `webhooks:write` | critical |
| `webhooks.update` | `PATCH /api/v1/webhooks/{id}` | `webhooks:write` | write |
| `webhooks.pause`, `webhooks.resume` | `POST /api/v1/webhooks/{id}/pause`, `…/resume` | `webhooks:write` | write |
| `webhooks.secret.rotate` | `POST /api/v1/webhooks/{id}/rotate` | `webhooks:write` | critical |
| `webhooks.delete` | `DELETE /api/v1/webhooks/{id}` | `webhooks:write` | destructive |

`webhooks.create` takes `url`, `event_types` and either `organization_id` or
`"environment_wide": true`, never neither. Its answer carries the signing secret once;
`webhooks.secret.rotate` issues a new one the same way. Creating an endpoint and rotating
its secret are critical, so a key with an approval policy may have to wait for a person
([step-up approvals](step-up-approvals.md)). Every change, from the console or the API, is
on the [audit log](activity-log.md) as `webhook.*`, naming who made it. See
[the management API](../getting-started/management-api.md#webhooks-hooks-log-streams-and-the-trail).

**Pause** an endpoint to stop deliveries while you work on the receiver without losing
its configuration; **Resume** picks up again.

Can't accept inbound requests? Poll `GET /api/v1/events?after=<last id>` (`events:read`)
for the same events instead.

## Verifying a delivery

Each request carries two headers:

```
X-Cbox-Timestamp: 1753900000
X-Cbox-Signature:  t=1753900000,v1=<hex>
```

`v1` is `HMAC-SHA256` over the string `timestamp + "." + raw request body`, keyed
with your endpoint's signing secret. To verify:

1. Read the raw body — **before** any JSON parsing or framework normalisation.
2. Recompute the HMAC and compare it to `v1` with a constant-time comparison.
3. Reject the delivery if the timestamp is outside a tolerance window you choose
   (a few minutes is usual). This is what stops a captured delivery being replayed
   at you later.

## Delivery behaviour

- **Answer quickly.** Cbox ID waits 5 seconds to connect and 10 seconds for the answer;
  do the real work in the background and return `2xx` immediately.
- **Retries use exponential backoff**, up to 12 attempts by default
  (`CBOX_ID_WEBHOOKS_MAX_ATTEMPTS`). After 5 consecutive failures an endpoint is
  circuit-broken and skipped for 5 minutes rather than hammered. That is recorded on
  the endpoint's health, not as a pause: **Paused** is only ever your own choice.
- **Handle repeats safely.** A delivery can arrive more than once — make your
  handler idempotent rather than assuming exactly-once.
- **Redirects are not followed**, and the endpoint must be publicly resolvable.
  A `30x` to an internal host is refused on purpose.

## Related

- [Hooks](inline-hooks.md) — when you need to influence the outcome.
- [Log streams](log-streams.md) — the audit log itself, mirrored to your SIEM.
- [Audit log](activity-log.md) — the authoritative record, whatever your
  endpoint did or did not receive.
- [Step-up approvals](step-up-approvals.md) — why `webhooks.create` from a key may wait for a person.
- [Webhook events reference](https://github.com/cboxdk/laravel-id/blob/main/docs/reference/webhook-events.md) — every event and its payload.
