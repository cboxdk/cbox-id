---
title: Log streams
weight: 112
description: Mirror the Audit log into a SIEM as it is written — the destinations and authentication schemes supported, what is sent, at-least-once delivery, testing a stream, and how secrets are kept.
---

# Log streams

**Console page:** Monitoring › Log streams in an environment console, and Audit log › Log
streams in an organization's console

A log stream sends a copy of every [Audit log](activity-log.md) entry to your security
team's own tools, such as Splunk or Elastic, as it is written. An investigation then
starts in the SIEM your team already uses, not with a request for an export.

## What is sent

- **Every Audit log entry** the stream covers: sign-ins, membership changes, roles,
  connections, keys, settings. Each one carries its chain fields (`sequence`, `hash`,
  `prev_hash`) and its `organization_id`. The event's `id` is the entry's chain hash, so
  your SIEM can recognise a duplicate.
- **[App audit logs](audit-logs.md) events**, to streams an organization owns. These are
  marked `source: app`, so a SIEM can tell your app's `user.created` from Cbox ID's.

Which entries a stream gets depends on who owns it:

| Created in | Owner | Carries |
|---|---|---|
| An environment console | The environment | Every organization's entries in the environment, plus the environment's own. Not App audit logs events. |
| An organization's console, or the [Admin Portal](admin-portal.md) | That organization | That organization's entries, and its App audit logs events. Nothing from any other organization. |

An organization admin never sees the environment's own streams.

Every entry is mapped to a category (authentication, session, authorization, identity and
access, configuration, threat or audit) from its action, an outcome (failure for actions
such as `*.failed` or `*.denied`) and a severity, so your SIEM's rules have something to
match on.

## Destinations

Every destination is an HTTPS `POST` of newline-delimited records:

| Destination (`destination`) | Format | Default authentication |
|---|---|---|
| Splunk HEC (`splunk_hec`) | Splunk HTTP Event Collector events, `sourcetype` `cbox:siem`. A URL with no path gets `/services/collector/event`. | Splunk token |
| Elastic (ECS) (`elastic_ecs`) | Elastic Common Schema documents | Bearer token |
| Graylog (GELF) (`graylog_gelf`) | GELF over HTTP | Bearer token |
| CEF over HTTP (`cef_http`) | ArcSight CEF lines, `text/plain` | Bearer token |
| Generic JSON (`generic_json`) | One JSON object per entry: `id`, `timestamp`, `action`, `category`, `outcome`, `severity`, `actor`, `target`, `source_ip`, `message`, `context` | Bearer token |

There is no syslog, S3 or Datadog destination. Datadog and most others accept Generic
JSON over HTTP.

## Authentication

| Scheme (`auth`) | What is sent |
|---|---|
| None (`none`) | No authentication header. |
| Bearer token (`bearer`) | `Authorization: Bearer <secret>` |
| Splunk (`splunk`) | `Authorization: Splunk <token>` |
| HMAC (`hmac`) | `X-Cbox-Timestamp: <unix time>` and `X-Cbox-Signature: t=<timestamp>,v1=<hex>`, where `v1` is HMAC-SHA256 of `timestamp + "." + body`, keyed with the stream's signing key. The same scheme as [webhooks](webhooks.md#verifying-a-delivery). |

With HMAC, leave the secret empty and a 64-character signing key is generated for you
and **shown once**. Copy it into your receiver then; only the encrypted form is stored, so
it cannot be shown again. A bearer or Splunk token you supply is stored encrypted, used
only to build the header, and scrubbed from any error message.

## Create one

In the console, choose **New stream** (you are asked for your password first):

1. **Name** it, for example "Splunk — production".
2. Pick the **Destination** your SIEM speaks. Generic JSON works with anything that
   accepts a POST.
3. Paste the **Endpoint URL**. It must be a public address: private, reserved and cloud
   metadata addresses are refused, and redirects are not followed.
4. Choose the **Auth scheme** and paste the **Secret**, or leave it empty for a
   generated HMAC key.
5. **Create stream.** Entries flow from the next one written; earlier entries are not
   backfilled.

The console has no test button. Send a test entry with the API (below), or wait for the
next change and check the stream's last delivery.

## Delivery

- **At least once.** Each entry is written to an outbox in the same transaction as the
  Audit log entry itself, then delivered. A retry after a timeout can deliver an entry
  twice; deduplicate on `id`.
- **Batched.** A scheduled pump runs every minute and sends up to 500 records, or 512 KB,
  per request.
- **Retried.** A failed batch is retried with exponential backoff and jitter, up to 12
  attempts, about two and a half hours in all. After that the entries are dead-lettered and not retried.
- **Circuit-broken, not disabled.** After 5 consecutive failures the stream is skipped
  for 5 minutes, then one probe is tried. The stream shows its failures and last
  successful delivery; it is never switched off for you.
- **Bounded.** A stream holds at most 100,000 pending entries. Beyond that the oldest
  are dropped by default, so a receiver that is down for days does not grow the outbox
  forever.

The operator of the deployment can change these limits; see
[the framework's audit streaming guide](https://github.com/cboxdk/laravel-id/blob/main/docs/core-concepts/audit-streaming.md).
Delivery needs the scheduler and a queue worker running.

## Disable, resume, delete

- **Disable stream** stops delivery and **keeps** what is pending. **Resume** sends it
  with the next batch, so nothing is lost while it was off. Disabling is critical:
  it is how a trail goes dark.
- **Delete stream** stops delivery at once and destroys the signing key. The Audit log
  itself is untouched.

Each change is recorded on the Audit log as `log_stream.created`, `log_stream.disabled`,
`log_stream.enabled` or `log_stream.deleted`.

## From code

The same lifecycle is a set of [actions](../core-concepts/actions.md), on the management
API, MCP and the CLI alike:

| Action | REST | Scope | Danger |
|---|---|---|---|
| `log_streams.list`, `log_streams.get` | `GET /api/v1/log-streams`, `…/{id}` | `log_streams:read` | read |
| `log_streams.create` | `POST /api/v1/log-streams` | `log_streams:write` | critical |
| `log_streams.update` | `PATCH /api/v1/log-streams/{id}` | `log_streams:write` | critical |
| `log_streams.test` | `POST /api/v1/log-streams/{id}/test` | `log_streams:write` | write |
| `log_streams.delete` | `DELETE /api/v1/log-streams/{id}` | `log_streams:write` | destructive |

`log_streams.create` takes `name`, `destination`, `endpoint_url`, optionally `auth` and
`secret`, and either `organization_id` or `"environment_wide": true`. Its answer carries
`secret` only when the platform generated it. `log_streams.update` takes `enabled`:
`false` to disable, `true` to resume.

`log_streams.test` sends one entry (`log_stream.test`, "Your log stream is connected.")
straight to the endpoint, through the same format and authentication as real delivery,
and answers `{ "delivered": true|false, "error": … }`. It is not retried and does not go
through the outbox.

Creating and updating a stream are critical, so a key with an approval policy may wait for
a person first ([step-up approvals](step-up-approvals.md)).

## Related

- [Audit log](activity-log.md) — what is being streamed.
- [App audit logs](audit-logs.md) — your app's own events, which organization-owned streams also carry.
- [Admin Portal](admin-portal.md) — let a customer's IT admin point their organization's entries at their own SIEM.
- [Log streams, for IT admins](../for-it-admins/log-streams.md) — what that IT admin sees.
- [Webhooks](webhooks.md) — notifications for your systems to react to, rather than a record.
