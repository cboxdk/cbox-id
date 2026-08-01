---
title: Inline hooks
weight: 70
description: Endpoints Cbox ID calls in the middle of an operation, whose answer changes the outcome — the hook points available, and the failure modes to decide about first.
---

# Inline hooks

**Console page:** Developers › Inline hooks

An inline hook is your endpoint, called **while** an operation is happening, whose
answer changes what happens next: add a claim to a token, or refuse a sign-in
outright.

This is the powerful one, and the one to reach for last. It sits directly in the
critical path — a slow endpoint is felt by a real person waiting at a sign-in
screen, and a broken one can lock your organization out. If what you need is to
*know* about something rather than *decide* it, use a [webhook](webhooks.md).

## Hook points

| Hook point | Called |
| --- | --- |
| `token_minting` | While an access or ID token is being issued — to add claims |
| `post_login` | Immediately after a successful sign-in |
| `pre_registration` | Before a new person is created — this one can refuse |
| `post_registration` | After a person is created |
| `pre_password_change` | Before a password change — can refuse |
| `post_password_change` | After a password change |

## Set one up

1. **Register endpoint**, choose the hook point, and give your HTTPS URL.
2. Copy the signing secret shown once, and verify every call against it — the
   verification is the same scheme as [webhooks](webhooks.md).
3. Answer within the timeout. Return quickly and do nothing expensive inline: no
   third-party API calls, no unindexed queries.
4. Test with a real sign-in before enabling anything that can refuse.

## Decide your failure mode first

Before you register a hook that can veto, answer this: **what should happen when
your endpoint is down?**

- *Fail open* — the operation proceeds. Availability preserved, the control is
  advisory.
- *Fail closed* — the operation is refused. The control holds, and an outage at
  your endpoint becomes an outage at sign-in.

Neither is wrong, but drifting into one by accident is. Write down which one you
chose and why, and check that reality matches it in staging.

## Related

- [Webhooks](webhooks.md) — after the fact, cannot block, retried.
- [Apps & API keys](apps-and-api-keys.md).
