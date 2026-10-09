---
title: Pipes
weight: 82
description: Let the people who use your apps connect their GitHub, Google, Slack or Salesforce account, and lease a fresh access token from your backend whenever you call that API as them.
---

# Pipes

**Console page:** Developers › Pipes (environment console)
**For your users:** My account › Connected services

A pipe lets a person connect **their own account** at another service, so your app can
call that service's API on their behalf: list their GitHub repositories, read their
Google Calendar, post to Slack as them, sync their Salesforce contacts.

Cbox ID runs the OAuth flow, stores the person's tokens encrypted in the
[token vault](token-vault.md), refreshes them before they expire, and gives a fresh access
token to the apps you grant when they ask. Your app never stores a third-party token.

Providers: **GitHub, Google, Microsoft 365, Slack, Salesforce, HubSpot, Linear, Notion.**

## How it fits together

1. You create an OAuth app at the provider (for example a GitHub OAuth App) and enter its
   client ID and secret here. That is the **pipe**.
2. You **grant** the apps that may use it. Nothing else can get a token through it.
3. A person opens the **connect page**, approves access at the provider, and comes back.
   That is a **connection**, bound to that person in this environment.
4. Your backend calls the **lease endpoint** whenever it needs to call the provider, and
   gets a working access token back.

## End to end: list a person's GitHub repositories

### 1. Set up the GitHub pipe

In GitHub, open **Settings › Developer settings › OAuth Apps › New OAuth App**. Set the
**Authorization callback URL** to the redirect URI shown on the Pipes page:

```
https://id.example.com/account/connected-services/github/callback
```

Generate a client secret. Then in the console open **Developers › Pipes › GitHub ›
Set up**, paste the client ID and secret, and set the scopes to `read:user repo`.

The same over the management API, with a key that holds `pipes:write`:

```bash
curl -X POST https://id.example.com/api/v1/pipes \
  -H "Authorization: Bearer $CBOX_ID_KEY" \
  -H "Content-Type: application/json" \
  -d '{
        "provider": "github",
        "client_id": "Ov23liAbCdEf",
        "client_secret": "'"$GITHUB_CLIENT_SECRET"'",
        "scopes": ["read:user", "repo"]
      }'
```

The client secret is sealed when you save it and never shown again.

### 2. Grant your app

On the pipe's page, under **Apps that may lease tokens**, pick your app and select
**Grant**. Or:

```bash
curl -X POST https://id.example.com/api/v1/pipes/$PIPE_ID/grants \
  -H "Authorization: Bearer $CBOX_ID_KEY" \
  -H "Content-Type: application/json" \
  -d '{"client_id": "cid_01j9yk4w2g8ma1hq3zv0t5r7bc"}'
```

### 3. Send the person to connect

Link the signed-in person to the connect page. Add your app's `client_id` and a
`return_to` on one of your app's registered redirect origins, and they come back to you
afterwards:

```
https://id.example.com/account/connected-services/github/connect
  ?client_id=cid_01j9yk4w2g8ma1hq3zv0t5r7bc
  &return_to=https://app.example.com/settings/integrations
```

They see what will be shared, in their own language, continue to GitHub, approve, and
land back on `return_to` with `?provider=github&status=connected` (or `cancelled`, or
`failed`). A `return_to` that is not on your app's registered origins is ignored, and they
land on Connected services instead.

### 4. Lease a token and call GitHub

From your backend, with an access token for your app that carries the `vault.lease`
scope (client credentials is fine):

```bash
curl -X POST https://id.example.com/api/v1/vault/pipes/github/token \
  -H "Authorization: Bearer $APP_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"user_id": "01j9yk3s7p5n4q2m1k0h8g6f4d", "purpose": "list-repos"}'
```

```json
{
  "access_token": "gho_16C7e42F292c6912E7710c838347Ae178B4a",
  "token_type": "Bearer",
  "provider": "github",
  "user_id": "01j9yk3s7p5n4q2m1k0h8g6f4d",
  "connection_id": "01j9yk5c4t3r2e1w0q9p8o7i6u",
  "scopes": ["read:user", "repo"],
  "expires_at": null,
  "lease_expires_at": "2026-10-09T14:05:00+00:00",
  "metadata": {}
}
```

Then call GitHub with it:

```bash
curl https://api.github.com/user/repos \
  -H "Authorization: Bearer gho_16C7e42F292c6912E7710c838347Ae178B4a" \
  -H "Accept: application/vnd.github+json"
```

In PHP:

```php
$lease = Http::withToken($appAccessToken)
    ->post('https://id.example.com/api/v1/vault/pipes/github/token', [
        'user_id' => $user->cbox_id,
        'purpose' => 'list-repos',
    ]);

if (in_array($lease->status(), [404, 409], true)) {
    return redirect($lease->json('connect_url'));   // not connected, or must reconnect
}

$repos = Http::withToken($lease->json('access_token'))
    ->accept('application/vnd.github+json')
    ->get('https://api.github.com/user/repos')
    ->json();
```

Drop the token when you are done and lease again next time. The lease refreshes it first
when it is about to expire, so you never handle a refresh token.

If your app has a token issued **for the person** (they signed in to your app through
Cbox ID), leave out `user_id`: the lease is for them, and naming anyone else is refused.

## What the lease endpoint answers

| Status | `error` | What to do |
|---|---|---|
| 200 | — | Use `access_token`. `metadata` has what some providers need to be called at all — Salesforce's `instance_url`. |
| 403 | `lease_denied` | Your app is not granted this pipe, the pipe is disabled or does not exist, or (for an app owned by an organization) the person is not a member of it. The answer is the same for every reason. |
| 404 | `not_connected` | The person has not connected this provider. Send them to `connect_url`. |
| 409 | `reauthorization_required` | The provider stopped accepting the connection. Send them to `connect_url` to connect again. |
| 503 | `temporarily_unavailable` | The provider could not refresh the token just now. Retry after `Retry-After`. |

## Keeping tokens fresh

Tokens are refreshed in the background a few minutes before they expire, and again when
your app leases one that is about to. Only one refresh runs per connection at a time, so
two of your servers asking at once do not both spend the refresh token.

When the provider refuses to refresh — the person revoked your app at GitHub, or a
refresh token expired — the connection is marked **needs reconnect**, the
`pipe.connection.needs_reauth` webhook fires, and the next lease answers 409. Subscribe to
the webhook to tell the person before they hit it.

## Per provider

| Provider | Good to know |
|---|---|
| GitHub | OAuth App tokens do not expire. A GitHub App with expiring user tokens is refreshed every 8 hours. |
| Google | Refresh tokens are requested with offline access and a consent prompt, so a reconnect always yields one. |
| Microsoft 365 | Keep `offline_access` in the scopes or nothing can be refreshed. Set the tenant to restrict which directories may connect. Microsoft has no revocation endpoint: disconnecting forgets the token here; the person removes consent at myapps.microsoft.com. |
| Slack | Scopes are user-token scopes. Tokens only expire and refresh when token rotation is turned on for your Slack app. `metadata` carries `team.id`. |
| Salesforce | Call the API at `metadata.instance_url`. Use `test.salesforce.com` as the login domain for sandboxes. |
| HubSpot | Tokens live 30 minutes; they are refreshed for you. |
| Linear | Scopes are comma-separated at Linear; enter them as usual here. |
| Notion | No scopes: the person picks pages on Notion's screen. Tokens do not expire, and Notion has no revocation endpoint. |

## Disconnecting

People disconnect under **My account › Connected services**. You can disconnect someone
from the pipe's page (for a leaver, say), or with
`DELETE /api/v1/pipes/{id}/connections/{connection_id}`. Where the provider supports it,
access is revoked at the provider too.

Removing a pipe forgets every connection through it and revokes their tokens here, but
does not call the provider for each person. Disconnect people first if that matters.

## Things worth knowing

- The pipes pages are behind the environment **step-up**: a pipe holds a client secret,
  and a grant hands an app every connected person's token.
- Changing a pipe's scopes applies to the next connect. People already connected keep
  what they agreed to until they connect again.
- The audit trail records every configuration change, grant, connect, refresh, lease and
  disconnect, with who did it — never a token or a client secret.
- Erasing a person deletes their connections and tokens.

## Related

- [Token vault](token-vault.md) — the store every pipe token lives in.
- [Webhooks](webhooks.md) — `pipe.connection.connected`, `pipe.connection.needs_reauth`,
  `pipe.connection.disconnected`.
- [Social login](social-sign-in.md) — signing people **in** with GitHub or Google. A pipe is
  the other direction: your app reaching **out** as them.
