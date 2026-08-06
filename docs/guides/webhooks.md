---
title: Webhooks
weight: 60
description: Receive signed notifications when something happens in your organization — which events exist, how to verify the signature, and how retries behave.
---

# Webhooks

**Console page:** Developers › Webhooks

A webhook endpoint is a URL of yours that Cbox ID posts to **after** something
happens: a member was added, a user signed in, a directory deactivated somebody.
Your systems find out as it happens instead of polling, and — importantly — a
webhook is a notification, not a vote. Your endpoint is told; it cannot hold
anything up or refuse. If you need a say in the outcome, you want an
[inline hook](inline-hooks.md) instead.

## Events you can subscribe to

| Event | Fires when |
| --- | --- |
| `user.created` | A person is created in this organization |
| `user.login` | A person signs in |
| `identity.linked` | An external identity is linked to a person |
| `organization.member_added` | Someone joins the organization |
| `organization.member_removed` | Someone is removed from it |
| `directory.user.provisioned` | A directory sync created or updated a person |
| `directory.user.deactivated` | A directory sync deactivated a person |

## Set one up

1. **Add endpoint**, give it your HTTPS URL, and tick the events it should receive.
2. Copy the **signing secret**. It is shown once.
3. Verify every delivery against that secret before acting on it (below).
4. Send yourself a test event and confirm the whole path works before you rely on it.

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

- **Answer quickly.** Cbox ID allows a short timeout; do the real work in the
  background and return `2xx` immediately.
- **Retries use exponential backoff**, and a repeatedly failing endpoint is
  circuit-broken rather than hammered.
- **Handle repeats safely.** A delivery can arrive more than once — make your
  handler idempotent rather than assuming exactly-once.
- **Redirects are not followed**, and the endpoint must be publicly resolvable.
  A `30x` to an internal host is refused on purpose.

## Related

- [Inline hooks](inline-hooks.md) — when you need to influence the outcome.
- [Activity log](activity-log.md) — the authoritative record, whatever your
  endpoint did or did not receive.
