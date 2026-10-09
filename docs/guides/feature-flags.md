---
title: Feature flags
weight: 75
description: Turn a feature on for named customers, named people or a share of everyone, and read the answer in your app's token or from the API — without a deploy.
---

# Feature flags

**Console page:** Developers › Feature flags (`/admin/feature-flags`, in an environment console)

A **feature flag** is a switch your apps ask about for one person in one organization:
"is `new-dashboard` on for Ada at Acme?". Because the flag lives in the environment rather
than in each app, every app gives the same answer, and turning a feature on for a customer
is a change here, not a deploy.

## What a flag is made of

- **A key** — what your code asks for: `new-dashboard`, `billing.v2`. Lowercase letters and
  digits with `-`, `_` or `.` between them. It cannot be changed once the flag exists.
- **Switched on / off** — the kill switch. Off means off for everyone, whatever the rules
  say. New flags are switched on.
- **On by default** — the answer for everyone no rule names. New flags are off by default,
  so creating one changes nothing until you add a rule.
- **Rules** — on (or off) for named **organizations**, on (or off) for named **users**,
  and a **rollout** percentage over everyone else.

## Who gets it

The first rule that matches decides:

1. Switched off → **off** for everyone.
2. A **user** rule names the person → that rule.
3. An **organization** rule names the organization they are in → that rule.
4. The person falls inside the **rollout** percentage → **on**.
5. Otherwise → the **default**.

So a user rule can keep one person out of a feature their whole organization has, and an
organization rule can keep a customer out of a rollout.

The rollout is stable: the same people are in it every time, on every server, and raising
10% to 20% only adds people — nobody who had the feature loses it. Each flag picks its own
people, so being in the first 10% of one rollout says nothing about the next. A rollout
only adds people on top of a default that is off; with the default on, everyone already has
it.

## Turn a feature on for a beta customer

1. **New flag**, with the key your code will ask for.
2. Under **Who it is on for**, find the customer under **Add an organization**, and
   **Save rules**.
3. Check it: under **Is it on for…?**, name somebody at that customer. The answer says
   which rule decided.

To roll out to everyone after that, set the rollout to 10, then 50, then turn the flag
**On by default** and remove the rules you no longer need.

## Read it in your app

**From the token.** Give your app the **Their feature flags** scope (`feature_flags`) on its
Scopes tab and request it at sign-in. The access token, the ID token and UserInfo then carry
the keys of every flag that is on for the person in the organization they signed in to:

```json
{ "feature_flags": ["acme-beta", "new-dashboard"] }
```

With the scope, the claim is always there, as an empty list when nothing is on. A token
carries the flags as they were when it was issued; the next refresh picks up a change, and
UserInfo is always current. People are asked to allow it on the consent screen as "Which
features are turned on for you".

**From your backend.** When there is no token in hand — a background job, a webhook
handler — or a change must show at once, ask the management API with a secret key that has
the **Read feature flags** scope:

```bash
curl -H "Authorization: Bearer $CBOX_ID_KEY" \
  "https://acme.cboxid.com/api/v1/feature-flags/evaluate?user_id=$USER&organization_id=$ORG"
```

```json
{
  "data": {
    "user_id": "01J…",
    "organization_id": "01J…",
    "feature_flags": ["acme-beta", "new-dashboard"],
    "evaluations": [
      { "key": "acme-beta", "enabled": true, "reason": "organization_target" },
      { "key": "new-dashboard", "enabled": true, "reason": "rollout" },
      { "key": "old-reports", "enabled": false, "reason": "disabled" }
    ]
  }
}
```

`feature_flags` is exactly what the token's claim would carry; `evaluations` has every flag
with the rule that decided.

## Manage flags from code or an agent

Every change on this page is also an API call and an MCP tool, with the **Manage feature
flags** scope: `feature_flags.create`, `feature_flags.update`, `feature_flags.delete`, plus
`feature_flags.list`, `feature_flags.get` and `feature_flags.evaluate`. In
`feature_flags.update`, `users`, `organizations` and `rollout_percentage` each replace that
part of the rules when sent and leave it alone when not. See the
[actions reference](../reference/actions-environment.md).

## Things worth knowing

- **Changes apply at once** to the API and UserInfo, and to every token issued from then
  on. Tokens already issued keep what they carry until they are refreshed.
- **Deleting a flag** turns it off for every app still asking for it.
- **Rules name people and organizations in this environment only.** A flag in staging is
  not the flag in production; define it in each.
- **Keep rules short.** A flag holds at most 1,000 user and organization rules. Target the
  organization, or use a rollout, rather than listing every user.
- **Every change is in the [audit log](activity-log.md)** as `feature_flag.created`,
  `feature_flag.updated` (with what changed) or `feature_flag.deleted`, under the person or
  key that made it, and is sent to [webhooks](webhooks.md) subscribed to the same events.
- **Erasing a person** removes the rules that name them.

## Related

- [Applications](apps-and-api-keys.md) — where an app is given the `feature_flags` scope.
- [API keys](keys.md) — a secret key for the evaluation endpoint.
- [Webhooks](webhooks.md) — `feature_flag.*` events.
