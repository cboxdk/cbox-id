---
title: Agent approvals
weight: 120
description: What it means when an app or AI agent asks to act on your behalf, how to check the request is really yours, and when to deny.
---

# Agent approvals

**Console page:** Overview › Agent approvals

Sometimes an app or an AI agent needs your go-ahead to act as you, and cannot ask
on the screen in front of you — it is running on a server, in a terminal, or on a
device with no browser. It asks here instead, and waits.

Each request names the app, and lists exactly what it is asking to be allowed to
do. Nothing happens until you approve; deny, and the app is told no.

## Before you approve

**Did you start this?** That is the whole question. A request you did not initiate,
arriving out of nowhere, is someone trying to get you to authorise something on
their behalf — deny it. This is the same reflex as an unexpected two-factor prompt.

**Does the code match?** Where the request shows a code, check it against the one
displayed on the device or terminal that asked. If they differ, you are not
approving what you think you are.

**Do the permissions match the task?** A request asking for more than the thing you
were doing needs an explanation before it gets an approval.

## Things worth knowing

- **Approval is tied to you.** It is your consent, recorded against your account —
  approving another person's request is not possible, and a request that does not
  belong to you is refused rather than silently approved.
- **Requests expire.** If one has been sitting here a while, deny it and start over
  rather than approving something stale.
- **Every decision is recorded** in the [activity log](activity-log.md), including
  denials.

## Building one

This page is for the person who receives a request. If you are building the thing that
sends them, the shape is short.

Register the app under [Apps & API keys](apps-and-api-keys.md) and answer **AI agent**.
That gives it a client secret and the backchannel grant. Then ask for approval:

```bash
curl -X POST https://<tenant>.cboxid.com/oauth/backchannel_authentication \
  -u $CBOX_ID_CLIENT_ID:$CBOX_ID_CLIENT_SECRET \
  -d login_hint=person@example.com \
  -d binding_message="Deploy release 4.2 to production" \
  -d scope="openid profile"
```

Two of those decide whether the person can answer well:

- **`login_hint`** names who is being asked. There is no other way to say it, and a
  request without one is refused rather than shown to somebody arbitrary.
- **`binding_message`** is the sentence they read on this page. Write the specific
  action — "Deploy release 4.2 to production", not "Perform an operation". It is the
  only thing standing between an approval and a habit of approving.

The response carries an `auth_req_id`; poll `/oauth/token` with
`grant_type=urn:openid:params:grant-type:ciba` until they answer. `authorization_pending`
means keep waiting, `access_denied` means they said no and you should stop.

The scopes are bounded by what the app is registered for — a request naming one outside
that ceiling is refused with `invalid_scope` rather than quietly given less, because
there is no browser in front of it to notice a smaller grant.

## Related

- [Token vault](token-vault.md) — the credentials agents use once approved.
- [Apps & API keys](apps-and-api-keys.md).
- [Sign in from a CLI](../getting-started/sign-in-from-a-cli.md) — the same idea when
  the person and the program are at the same keyboard.
