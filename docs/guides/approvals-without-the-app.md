---
title: Approvals without the mobile app
weight: 121
description: How to build agent and backchannel (CIBA) approvals that work for people who have not installed the Cbox Authenticator app — what Cbox ID does with the request, where the person answers it, and what your agent has to tell them.
---

# Approvals without the mobile app

The fastest way to approve a request is a push to the Cbox Authenticator app: the person
sees who is asking and taps approve. Many of your users will not have it. The Android
app is not on Google Play yet, a deployment may run without the devices module, and
somebody signing up today has not enrolled a phone.

Approvals still work for them. Nothing in the protocol depends on the app. What changes
is **who tells the person** that a request is waiting. With the app, Cbox ID does. Without
it, your agent does.

## What Cbox ID does with every request

When your app calls `POST /oauth/backchannel_authentication`, or an agent's key holds an
action for approval, Cbox ID:

1. **Creates the request** for the person named by `login_hint` (or the key's owner), with
   your `binding_message` and the scopes asked for. It lives for
   `CBOX_ID_CIBA_TTL_SECONDS`, 300 seconds by default.
2. **Pushes it to their trusted devices**, if they have any.
3. **Mails them, if no device can take the push.** The mail names who is asking, links to
   their Approvals page and says when the request expires. It does not contain the
   `binding_message`: an inbox preview is as readable as a lock screen. At most five such
   mails go to one person in ten minutes; past that the request is still filed, unmailed.
4. **Lists it on their Approvals page**, `https://<environment-host>/approvals`, whether or
   not a push or a mail went out. Approving there is the same decision as approving on the
   phone.
5. **Answers your polling** on `/oauth/token` with `authorization_pending` until the person
   decides or the request expires.

Steps 1, 4 and 5 always happen. Step 2 needs the app; step 3 is what happens instead.
`CBOX_ID_APPROVAL_EMAIL` changes step 3: `fallback` (the default), `always` (mail even
when a push went out) or `off`.

## What your agent must do

Your agent is the one thing the person is already looking at: a chat, a terminal, a
Slack thread. When it starts a request, it has to say so there. The mail is a backstop,
not a substitute: it can land in spam, arrive late, or be withheld by the per-person limit.

**Tell them where to go.** Print the Approvals URL for the environment, not a generic
"check your phone":

```text
I need your approval to deploy release 4.2 to production.
Open https://auth.example.com/approvals and approve "Deploy release 4.2 to production".
This request expires in 5 minutes.
```

**Show the same words they will see.** The `binding_message` you sent is what the
Approvals page shows. Repeat it, so the person recognises the request as the one in
front of them.

**Show the deadline.** Use `expires_in` from the response. Somebody who comes back after
six minutes should know to ask again instead of hunting for a request that has lapsed.

**Poll, and respect the interval.** Poll `/oauth/token` with
`grant_type=urn:openid:params:grant-type:ciba` no faster than the `interval` you were given.
`slow_down` means wait longer. `access_denied` means they said no, so stop and say so.
`expired_token` means start again.

A minimal loop:

```bash
# 1. Ask
curl -s -X POST https://auth.example.com/oauth/backchannel_authentication \
  -u "$CLIENT_ID:$CLIENT_SECRET" \
  -d login_hint=alex@example.com \
  -d binding_message="Deploy release 4.2 to production" \
  -d scope="openid"
# → {"auth_req_id":"…","expires_in":300,"interval":5}

# 2. Tell the person: the Approvals URL, the binding message, the deadline.

# 3. Poll every `interval` seconds
curl -s -X POST https://auth.example.com/oauth/token \
  -u "$CLIENT_ID:$CLIENT_SECRET" \
  -d grant_type=urn:openid:params:grant-type:ciba \
  -d auth_req_id="$AUTH_REQ_ID"
# → {"error":"authorization_pending"} … until tokens, access_denied or expired_token
```

## Actions held by a key's approval policy

An action held by a key's [approval policy](step-up-approvals.md) waits for the person who
created the key. Without the app, Cbox ID mails them a link to their Approvals page, which
lists the held action, and they can also approve it on **AI agents › Approvals** in the
environment console. The `202` response carries a `binding_code`. Show it next to the link,
so they can check that the held action they approve is the one your agent is waiting on.

## Which path a person gets

| The person has | They learn about the request from | They answer on |
|---|---|---|
| The Authenticator app, enrolled | A push on their phone | The phone, or the Approvals page |
| No app | Your agent's message, and a mail from Cbox ID | The Approvals page |

You cannot tell from the CIBA response which row applies. Write the message for the second
row every time. A person who also got a push loses nothing by reading it.

## The person has to be able to sign in

The Approvals page is behind sign-in on the environment's host. That is the point: the
approval is their consent, given in a session that proves who they are.

So the person needs a working sign-in to that environment, by passkey, password, magic
link or SSO. A user who only exists as a directory record and has never signed in cannot
approve anything until they do.

## Keep requests worth approving

Without a phone in the loop, the Approvals page is the only check. Keep it a real one:

- **Write a specific `binding_message`.** "Deploy release 4.2 to production", not "Perform
  an operation". It is the sentence the person decides on.
- **Ask for the scopes the task needs.** A request outside the app's registered scopes is
  refused with `invalid_scope`, not narrowed.
- **Ask once per task.** A person who sees a queue of identical requests learns to approve
  without reading.
- **Let administrators watch.** Every pending request in an environment shows on
  **AI agents › Approvals** in the environment console, where an administrator can deny
  one that looks wrong.

## When the app arrives

Nothing in your integration changes when a person enrols a phone. The same request starts
pushing to it, and the Approvals page keeps working. To set up pushes for your environment,
see [Trusted devices](trusted-devices.md).

## Related

- [Approvals](agent-approvals.md): the approver's side, and what to check before saying yes.
- [Approvals (concept)](../core-concepts/approvals.md): held actions and requests to act as somebody.
- [Step-up approvals](step-up-approvals.md): a key's approval policy, and handling `approval_required`.
- [Agents and MCP](agents-and-mcp.md): connecting the agents that ask.
- [Trusted devices](trusted-devices.md): the app, enrolment and push delivery.
