---
title: API keys
weight: 22
description: Keys people create for the APIs of the apps they use — creating your own under My account, and seeing and revoking everybody's as an organization admin.
---

# API keys

**Console pages:** My account › API keys, People › Member API keys, and each
organization's page in an environment console

Some apps that sign you in through Cbox ID have an API of their own. An API key lets
your scripts and integrations call that API as you, without signing in. You create the
key here; the app checks it with Cbox ID every time it is used.

These are not the [Keys](keys.md) under Workspace, which are for your own code calling
Cbox ID.

## Creating a key

**My account › API keys.** The page appears once an app in your organization offers
keys, and an app can link you straight to it.

1. If you belong to several organizations, pick the one the key should act in.
2. Pick the **app**.
3. Give the key a **name** that says what will use it, such as "Accounting sync".
4. Tick the **permissions** it needs. Only what you can do in that app yourself is
   offered, each with the app's own description. Tick as little as the job needs.
5. Choose when it **expires**: never, 30 days, 90 days, a year, or a date.
6. **Create key**, then copy it. It is shown once. Afterwards only its first characters
   are shown.

If you came from the app, a **Back to** link takes you there.

### When a key is refused

The page says why. The usual one is a permission you do not hold in the app, for
example because a colleague changed your role while the page was open. It is named in the
message. Ask an admin for the role, or create the key without it.

## What a key can do

A key can only do what you can do in the app, and never more:

- it carries only the permissions you ticked;
- if you lose a permission, the key loses it on its next use;
- if you leave the organization, or it is suspended, the key stops working. Being added
  back later does not revive it.

## Revoking a key

Revoke a key the moment it leaks or its integration is retired. Anything still using it
stops working at once. The list shows when each key was last used, which tells you
whether anything still depends on it.

- **Your own keys:** My account › API keys.
- **Everybody's keys in an organization:** People › Member API keys, for owners and
  admins. The environment console shows the same list on each organization's page.

Admins can revoke any key in their organization. They cannot create a key for somebody
else: a key acts as the person who holds it, so each person creates their own.

## In the activity log

Creating and revoking a key are both in the organization's
[activity log](activity-log.md), as `api_key.created` and `api_key.revoked`, with who did
it. An admin revoking somebody else's key is recorded as the admin.

## For app developers

To offer keys for your own app's API, see
[Let your customers create API keys](../getting-started/let-your-customers-create-api-keys.md).
