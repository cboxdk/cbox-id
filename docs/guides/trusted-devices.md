---
title: Trusted devices
weight: 130
description: Enrol a phone as an authenticator, approve sign-ins from it, and understand what happens when a push cannot be delivered.
---

# Trusted devices

A trusted device is a phone running the Cbox ID authenticator app, enrolled against one
user in one environment. Once enrolled it does two things:

- **Answers approvals.** When an app or an agent starts a sign-in on the user's behalf
  ([Agent approvals](agent-approvals.md)), the phone gets a push and the person taps
  approve or deny.
- **Receives security alerts.** Chosen account events — a sign-in, a new session —
  arrive as a notification, so an unexpected one is noticed by the person it happened to
  rather than by whoever reads the audit log next quarter.

The module is **off by default**. A deployment with no mobile app has no devices to
notify, and the push decorator should not sit in the sign-in path doing nothing.

```bash
CBOX_ID_DEVICES_ENABLED=true
```

With that set and nothing else configured, the feature is fully usable except that
pushes go nowhere: notification rows are written and immediately settled as *Skipped*,
and the console shows the whole delivery history. That is a deliberate state to be able
to sit in — you can enrol, inspect and demo without a Firebase project.

## Turning it on properly

### 1. Provision the app's OAuth client

The authenticator is a public OAuth client, one per environment, because `client_id` is
globally unique and one app binary has to serve every tenant.

```bash
php artisan cbox-id:devices:client --environment=prod
```

Re-running is safe: it will not mint a second client and strand every handset that
enrolled against the first.

The app finds its own configuration at an unauthenticated discovery endpoint, so a
single binary can be pointed at any deployment:

```
GET /.well-known/cbox-authenticator
```

which answers with the issuer, the `client_id`, the scopes to request, the redirect URIs
and the API base. A public client's id is not a credential, which is why this needs no
authentication.

### 2. Give pushes somewhere to go

```bash
CBOX_ID_DEVICES_TRANSPORT=fcm
CBOX_ID_DEVICES_FCM_CREDENTIALS=/etc/cbox-id/fcm-service-account.json
CBOX_ID_DEVICES_FCM_PROJECT_ID=your-firebase-project
```

Firebase Cloud Messaging covers Android natively and iOS by relaying to APNs, which is
why there is no separate APNs driver — one integration reaches both.

> The service-account JSON can push to **every device you have ever enrolled**. It
> belongs on the server. Never bundle it into the mobile app.

If either FCM value is missing the transport silently stays on the null driver rather
than failing at send time. A misconfigured push must not be able to break a login.

> **Do not run this with `CACHE_STORE=array`.** FCM access tokens are cached for their
> lifetime; with no shared cache every worker re-exchanges the JWT on every single send,
> and Google's token endpoint will rate-limit you long before FCM itself complains. The
> module logs a warning at boot when it sees that combination.

### 3. Enrol a phone

A user opens **Account → Devices** and scans the enrolment QR with the app. Nothing is
required of an administrator. **Sign-in → Devices** in the console shows the fleet: who
enrolled what, when it was last seen, and every notification sent to it.

## When a push cannot be delivered

This is the part worth understanding before you are debugging it at 2am.

**Transient failures retry** with exponential backoff — `min(60, 2^attempt)` minutes, up
to `CBOX_ID_DEVICES_MAX_ATTEMPTS` (12) — after which the notification dead-letters as
*Exhausted*.

**Permanent failures do not.** If FCM answers `UNREGISTERED` or `INVALID_ARGUMENT`, the
device token is retired on the first attempt. A token FCM has told us is dead will not
become alive again after eleven hours of retrying.

**A repeatedly failing handset is taken out of rotation.** Five consecutive failures open
a per-device circuit breaker for five minutes, then one half-open probe decides whether
to close it. The state lives on the device row rather than in the cache, so it survives a
cache flush and you can see it in the console. While the breaker is open, notifications
are parked *without* being charged an attempt — the trip is the device's fault, not the
notification's, and charging it would burn a dead phone's backlog through all twelve
attempts inside a single cooldown.

**A notification whose queue job vanished is rescued.** Anything still *Pending* after
`CBOX_ID_DEVICES_STRANDED_AFTER_SECONDS` (15 minutes) is re-enqueued by the sweep. This
is what makes "durable row, then enqueue" true rather than aspirational.

> If you change `stranded_after_seconds`, know that the delivery job's unique-lock window
> is deliberately the same value: while the lock holds the sweep's re-enqueue is
> correctly suppressed as a duplicate, and the instant a row counts as stranded the lock
> has expired so the rescue can fire. Raising one without the other wedges the rescue
> shut.

## Approvals on the critical path

An approval push is racing a CIBA request's 300-second TTL, so give it a queue that is
not sharing a worker pool with slow bulk work:

```bash
CBOX_ID_DEVICES_QUEUE_CONNECTION=redis
CBOX_ID_DEVICES_QUEUE=push
```

On `QUEUE_CONNECTION=sync` — the test suite, and some self-hosted installs — the FCM call
runs inline inside the CIBA request, bounded by the transport's own timeouts.

By default **any** client holding the CIBA grant can cause a push to a user's phone. To
narrow that:

```bash
CBOX_ID_DEVICES_CIBA_CLIENT_ALLOWLIST=client_abc,client_def
```

This is enforced as a refusal to notify, not a log line. CIBA's whole value is the human
in the loop, and an attacker who can spray approval prompts at a phone is attacking
exactly that — a person who has dismissed thirty prompts will approve the thirty-first.
The CIBA request itself still succeeds and the client can still poll; it simply produces
no push.

## Security alerts

```php
// config/id-devices.php
'alerts' => ['user.login', 'user.session_started'],
```

These ride the ordinary once-a-minute event relay rather than the synchronous path
approvals use: "your password changed" is worth knowing about, but not worth putting on
the sign-in critical path.

Only event types actually emitted to the `events` outbox can appear here. Audit-only
actions are not reachable this way — adding one produces no alert and no error.

An alert stops being worth delivering after `CBOX_ID_DEVICES_ALERT_TTL_SECONDS` (24
hours). That deadline is not only about staleness: because a notification parked by an
open circuit breaker is not charged an attempt, without a deadline one permanently
soft-failing handset would accumulate Failed rows that — being oldest, and the sweep
being oldest-first — would occupy every retry slot forever and starve every other
tenant's approvals. Approvals take their deadline from the CIBA request's own TTL
instead.

## The app's API

Enrolment and approval run over a small REST surface at `/api/v1`, authenticated with a
DPoP-bound OAuth access token whose audience is pinned to this issuer.

| Endpoint | Scope | What it does |
|---|---|---|
| `POST /api/v1/devices` | `devices.manage` | Enrol this handset |
| `GET /api/v1/devices` | `devices.manage` | List the user's devices |
| `DELETE /api/v1/devices/{id}` | `devices.manage` | Remove one |
| `GET /api/v1/approvals` | `approvals.read` | Pending approvals |
| `GET /api/v1/approvals/{id}` | `approvals.read` | One approval's detail |
| `POST /api/v1/approvals/{id}/approve` | `approvals.write` | Approve it |
| `POST /api/v1/approvals/{id}/deny` | `approvals.write` | Deny it |

Reading and answering are separate scopes on purpose: a watch complication or a
home-screen widget can show what is pending without carrying the authority to answer it.

Requests are budgeted at `CBOX_ID_DEVICES_RATE_LIMIT` (60) per minute, keyed on a
fingerprint of the presented token — so a per-user bucket. Keying on `client_id` would
put every mobile user in one bucket and let a single busy account throttle the fleet.

## Housekeeping

Settled notification rows are kept for `CBOX_ID_DEVICES_RETENTION_DAYS` (30) so the
console can show delivery history, then pruned by the module's own scheduled job. Only
terminal rows are pruned; anything still Pending or Failed is left alone whatever its
age.

This table grows with **traffic**, not with tenants — one row per enrolled device per
alerted event — so the prune is not optional housekeeping. Make sure the scheduler is
running; see [Scheduled work](../operations/operations.md).

## Every setting

See [Trusted devices](../configuration/environment-variables.md#trusted-devices-push) in
the environment-variable reference.
