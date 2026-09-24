---
title: Let your customers create API keys
weight: 22
description: Give the people using your app API keys for your own API without building a key table — set a prefix, link them to the hosted page, verify each key with your app's credentials.
---

# Let your customers create API keys

Your app has an API, and the people using your app want to call it from their own
scripts, their accounting system or a CI job. They need an API key. You do not need to
build one: Cbox ID hosts the page where they create keys, stores only a hash of each one,
caps what a key can do at what its holder can do, and answers one question for your API:
is this key good, and what may it do?

This page assumes your app is already registered and signing people in
([Integrate your app](integrate-your-app.md)) and that it declares its roles and
permissions in a manifest, because a key carries **your app's permissions**.

## How a key works

A key is bound to four things:

| | |
|---|---|
| **Your app** | the `client_id` it was created for. Only your app can verify it. |
| **An organization** | the organization it acts in. |
| **A person** | who created it. It never outlives their access. |
| **Permissions** | a subset of your app's permissions the person picked. |

Two caps keep it honest:

1. **When the key is created**, a person can only pick permissions they hold in your app
   right now. The page only offers those, and anything else is refused.
2. **Every time the key is verified**, its permissions are cut down to what the person
   holds in your app at that moment. Take a role away and the key loses it on the next
   request. Remove the person from the organization and the key stops working.

## 1. Give your app a key prefix

A prefix is how your app opts in, and it becomes the start of every key:
`ctx_live_…` for a prefix of `ctx_live`. It must be a lowercase name of 2 to 16
characters followed by `_live` or `_test`, for example `acme_live`. Use `_test` on
the app you registered in a sandbox environment, so a leaked key says which kind it is at
a glance. `cbid` is reserved.

Set it in the console under **Developers › Apps ›** *your app* **› Settings › API keys**
(the environment console, or the organization's console for an app an organization
owns), or from code:

```php
use Cbox\Id\Organization\Contracts\CustomerApiKeys;
use Cbox\Id\Organization\ValueObjects\ApiKeyPrefix;

app(CustomerApiKeys::class)->setPrefix($clientId, ApiKeyPrefix::of('acme_live'));
```

Until an app has a prefix, nobody can create a key for it. Removing the prefix stops new
keys. Keys already created keep working until they are revoked.

An app registered for the whole environment is offered to every organization in it. An
app that belongs to one organization is offered to that organization's people only.

## 2. Send people to the hosted page

Link to **My account › API keys** on your environment's host:

```
https://<environment>.cboxid.com/account/api-keys?client_id=<your client id>&return_to=<a page in your app>
```

| Parameter | What it does |
|---|---|
| `client_id` | Preselects your app. If it does not offer keys to the person's organization, the page says so. |
| `return_to` | Adds a **Back to <your app>** link, shown again beside the new key. Only honoured when it is on an origin your app registered as a redirect URI. Any other address is dropped without a word. |
| `organization` | Opens the page in that organization, when the person is a member of it. Their current organization is the default, and people in several can switch on the page. |

This path and its parameters are a stable contract. Bookmark it, put it in your docs,
and link to it from your app's own settings page.

The person signs in if they are not already, picks the organization, gives the key a
name, ticks the permissions it needs, and chooses when it expires. The key is shown
**once**, with a copy button. After that only its first characters (`acme_live_Ab3d…`)
are ever shown again, and the person can see when each key was last used and revoke it.

## 3. Verify keys in your API

Your API receives a request carrying a key, for example
`Authorization: Bearer acme_live_…`. Ask Cbox ID about it, authenticating as your app
with the same client credentials you use at the token endpoint:

```bash
curl -X POST https://<environment>.cboxid.com/oauth/api-keys/verify \
  -u "$CLIENT_ID:$CLIENT_SECRET" \
  -H 'Content-Type: application/json' \
  -d '{"key": "acme_live_…"}'
```

A good key, created for your app:

```json
{
  "active": true,
  "key_id": "01j9…",
  "sub": "01j8…",
  "org": "01j7…",
  "org_role": "member",
  "permissions": ["returns:read"],
  "client_id": "cid_01j6…",
  "expires_at": null
}
```

Everything else, including another app's key, a revoked or expired key, and a holder who
has left, is `{"active": false}` with HTTP 200. The answer never says which, so the
endpoint cannot be used to probe for keys. Only your own bad credentials get a `401`.

Authorize the request on `permissions`, and use `org` for tenancy exactly as you use the
`org` claim of an access token. The SDKs are getting a `verifyApiKey()` helper that wraps
this call; the HTTP request above is the contract it wraps.

If you cache the answer, the cache length is how long a revoked key keeps working. A few
seconds is reasonable. Never cache `active: false` for longer than `active: true`.

## Who can see and revoke keys

- **The holder**, on My account › API keys.
- **The organization's owners and admins**, on People › Member API keys: every key in the
  organization, with its holder, app, permissions, last use and expiry.
- **Your environment's administrators**, on the organization's page in the environment
  console.

Admins can **revoke** any key in their organization. Nobody can **create** a key for
somebody else: a key acts as its holder, so each person creates their own.

## What you get told

Creating and revoking a key each write an entry in the organization's activity log
(`api_key.created`, `api_key.revoked`) and send the webhook event of the same name. The
payload carries the key's id, holder, app and organization, never the key itself. See
[Webhooks](../guides/webhooks.md).

## Related

- [API keys](../guides/api-keys.md) — the same feature from the side of the people who
  create and revoke keys.
- The framework's reference:
  [Customer API keys](https://github.com/cboxdk/laravel-id/blob/main/docs/core-concepts/customer-api-keys.md).
