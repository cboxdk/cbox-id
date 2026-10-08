---
title: Log streams
weight: 40
description: Send your organization's audit log to your own SIEM through the setup portal — the destinations and authentication supported, the signing key, and testing a destination.
---

# Log streams

**Page:** Log streams

A log stream sends your organization's audit log (sign-ins, membership changes, settings,
and the audit events the product records about your organization) to your own SIEM as it
happens. You get your organization's entries only, never anyone else's.

## Add a destination

Under **Add a destination**:

1. **Name** it, for example "Splunk — production".
2. Choose the **Destination**, the format your SIEM accepts:

   | Destination | Use for |
   |---|---|
   | Splunk (HTTP Event Collector) | Splunk. An endpoint with no path gets `/services/collector/event` added. |
   | Elastic (ECS) | Elastic, in Elastic Common Schema |
   | Graylog (GELF over HTTP) | Graylog's GELF HTTP input |
   | CEF over HTTP | SIEMs that read ArcSight CEF |
   | JSON over HTTP | Anything else that accepts an HTTPS POST of JSON |

3. Enter the **Endpoint URL**: a public `https` address your SIEM accepts events at.
   Addresses on private networks are refused.
4. Choose the **Authentication**:

   | Authentication | Sent with every delivery |
   |---|---|
   | None | Nothing |
   | Bearer token | `Authorization: Bearer <token>` |
   | Splunk HEC token | `Authorization: Splunk <token>` |
   | HMAC signature | `X-Cbox-Timestamp` and `X-Cbox-Signature: t=<timestamp>,v1=<signature>` |

   For a bearer or Splunk token, paste it into **Token**. With **HMAC signature**, leave
   **Token** blank: a signing key is generated for you.
5. Choose **Add destination**.

With HMAC, the page then shows the **Signing key**. **Copy it now**; it is shown only once.
To check a delivery, compute HMAC-SHA256 over the timestamp, a `.`, and the raw request
body, keyed with the signing key, and compare the hex result with `v1` in
`X-Cbox-Signature`. Reject deliveries whose timestamp is more than a few minutes old.

## Test it

Choose **Send test entry**. One entry is sent straight away, in the same format and with the
same authentication as real ones. The page says either "Your endpoint accepted the test
entry" or what your endpoint answered instead, for example `destination responded with HTTP
403`.

## What to expect

- **Each entry is delivered at least once.** After a timeout an entry can arrive twice.
  Every entry has a unique `id`; deduplicate on it.
- **Entries are batched** and sent within a minute or so of being written. Earlier entries
  are not backfilled.
- **Failed deliveries are retried** for a few hours. If your endpoint keeps failing, the
  destination shows **Failing**; fix the endpoint and delivery resumes.
- **Your destinations** shows each destination's status (**On**, **Paused** or **Failing**)
  and its last delivery.

## Remove a destination

**Remove** stops delivery to that endpoint at once. You are asked to type its name to
confirm. You cannot pause a destination from the portal; remove it and add it again, or
ask the person who sent you the link.

## Related

- [Audit logs](audit-logs.md) — read and export the product's audit events in the portal instead.
- [For IT admins](_index.md) — how the setup link works.
