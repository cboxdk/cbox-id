---
title: Live updates
weight: 35
description: How console and account pages update without a reload — Reverb, the three channels, who may listen, what is sent, and how to run, scale and switch it off.
---

# Live updates

Console and account pages update while they are open. A trusted device enrolled on a
phone appears on the person's **Trusted devices** page, a new sign-in on **Sessions &
activity**, a member added to an organization on its **Members** page, an approval on
**Approvals**, an audit entry on the audit log, without anybody reloading.

It is optional. With `BROADCAST_CONNECTION=null`, the default, nothing is queued, no page
connects and every page works exactly as it always has.

## How it works

1. A record a page shows changes: a device, a session, a grant, a membership, a role, an
   app, an approval, an audit entry. Cbox ID observes those records where laravel-id and
   the app already write them, so every way of changing them is covered: a page, the API,
   MCP, SCIM, a job.
2. Once the transaction has **committed**, the change is buffered. When the request (or
   job, or command) is over, one message per channel is **queued**. The request that made
   the change never talks to the socket server.
3. The queue worker publishes the message to Reverb, which sends it to the browsers
   subscribed to that channel.
4. The page reloads its own data with an Inertia partial reload: the same request,
   through the same gates, as opening the page.

### What is sent

An **invalidation signal**, never data:

```json
{
  "topics": ["devices"],
  "changes": [{ "topic": "devices", "type": "device", "id": "01m7…", "action": "created" }],
  "truncated": false
}
```

The kind of record, its id and what happened. No name, email address, IP address,
device label, token or secret. A page that cares reads the record through its own gates.

Topics: `users`, `sessions`, `devices`, `credentials` (passkeys, authenticator apps,
linked sign-ins), `tokens`, `api_keys`, `approvals`, `organizations`, `members`, `roles`,
`apps`, `keys`, `projects`, `audit`.

### Channels

Every channel is private. A browser joins one only with a signature the page's own host
gives it.

| Channel | Who may listen | Signed at |
|---|---|---|
| `private-id.environment.{environment}` | Whoever may open that environment's console, read-only roles included. | `{environment console}/realtime/auth` |
| `private-id.organization.{organization}` | An owner or administrator of that organization. | `/orgs/{org}/realtime/auth`, or `/realtime/auth` for the organization the session is in |
| `private-id.subject.{person}` | That person. | `/realtime/auth` |

Each endpoint is mounted inside the route group of the pages that listen on it, so it is
reached through the same middleware: the host and surface fences (login-host mode
included), the impersonation window, the environment or organization binding and its
membership check. The platform root environment's console is an environment console like
any other.

**Nothing while impersonating.** An impersonation may look at a person's pages within its
time box; a socket would outlive the box, so no channel is signed during one.

The endpoints are `GET`: subscribing is a read, and a read-only console role must be able
to listen to what it may look at. The answer is a signature over the asking page's own
socket and is useless to anybody else.

### When a page reloads

- Signals arriving within 500 ms are one reload, and reloads start at least a second
  apart.
- Never under an edit. If a field has focus, a dialog is open or a form has unsaved
  changes, the page shows **New data on this page — Refresh** instead.
- A hidden tab waits until it is looked at.
- Without a connection the page simply does not update until it is reloaded; it shows
  nothing about it. pusher-js reconnects with backoff on its own.

### Load

- **Nobody watching, nothing sent.** A channel is published to only while a page has been
  signed into it recently (`CBOX_ID_REALTIME_WATCH_SECONDS`). Token refreshes and sign-ins
  of people with no page open cost one cache read, not a job.
- **Bursts are throttled**: at most one message per channel and topic per second, plus
  one trailing message for what changed after it. A bulk import is a few messages.
- **Heartbeats are not news**: a session's last-activity stamp, a CIBA poll, a key's
  last-used time do not publish anything.

## Running it

Reverb is its own process: `php artisan reverb:start`. Never inside the web server, and
never under Laravel Octane.

Set the same `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` in the web, the
worker and the Reverb process, and `BROADCAST_CONNECTION=reverb` in the web and the
worker. All keys are in [Environment variables](../configuration/environment-variables.md#live-updates-reverb).

### Behind a proxy (recommended)

Route the Pusher protocol's path on every host the console is served on to Reverb, and
nothing else:

```
^/app/<REVERB_APP_KEY>$   →  reverb:8080
everything else           →  the web
```

Leave `REVERB_HOST` unset: the browser connects to the host the page came from, so the
socket is same-origin and the Content-Security-Policy admits it. Do not route Reverb's
`/apps/…` publishing API or `/up` publicly. The web and worker publish over the private
network: `REVERB_PUBLISH_HOST=<reverb service>`, `REVERB_PUBLISH_PORT=8080`,
`REVERB_PUBLISH_SCHEME=http`.

A custom domain the proxy does not route `/app/…` on gets no live updates; its pages
work as before.

### Several web servers

Every web and worker process publishes over HTTP to Reverb, so the web tier scales as it
did. To run more than one Reverb process, set `REVERB_SCALING_ENABLED=true`: they share
connections over Redis/Valkey (`REDIS_*`).

Give Reverb the Redis host by its **fully qualified name** (in Kubernetes
`valkey.<namespace>.svc.cluster.local`, not `valkey`). Reverb resolves it with its own
DNS client, which does not apply the resolver's search domains: a short name fails to
resolve, Reverb retries for `REDIS_TIMEOUT` (60 s) and then stops. A Reverb that logs
"Starting server" and exits a minute later is this.

### Locally

```bash
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=local REVERB_APP_KEY=localkeylocalkey0000 REVERB_APP_SECRET=localsecret
REVERB_HOST=localhost REVERB_PORT=8080 REVERB_SCHEME=http
php artisan reverb:start
```

…with a queue worker running.

### How cboxid.com runs it

Namespace `cbox-id` on cbox-hel1: Deployment `cbox-id-reverb` (one replica, Valkey
scaling on, `REDIS_HOST` fully qualified) from the same image as the web, Service `cbox-id-reverb:8080`, and a
Cloudflare Tunnel path rule `^/app/[a-z0-9]{20}$` on `cboxid.com` and `*.cboxid.com`
ahead of the catch-all. Network policies admit the tunnel connectors and the cbox-id
pods, nothing else. The release rolls it with the web and the worker.

## Switching it off

`BROADCAST_CONNECTION=null` (or `CBOX_ID_REALTIME_ENABLED=false`) and roll the web and
worker. Pages stop connecting on their next load; nothing else changes. Reverb can then be
stopped.

## Checking it

- `GET https://<host>/app/<key>?protocol=7&client=js&version=8.4.0` with
  `Upgrade: websocket` answers `101 Switching Protocols`.
- `GET https://<host>/realtime/auth?socket_id=1.1&channel_name=private-id.subject.x`
  without a session is refused.
- In a browser, open a page that is live (Trusted devices) and enrol a device elsewhere.
