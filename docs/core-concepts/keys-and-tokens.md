---
title: Keys and tokens
weight: 15
description: Every credential Cbox ID issues — secret, publishable, agent, member and organization keys, app credentials, and the tokens a person delegates — and which one to reach for.
---

# Keys and tokens

Cbox ID issues two families of credential, and the difference matters more than the
prefixes:

- A **key** acts as itself. Whatever holds it can do what the key may do, with no person
  behind it. You mint it, it is shown once, and it works until it expires or you revoke it.
- A **delegated token** acts as a person. Somebody signed in and allowed a client to act
  for them, so the token can do only what that person may do *and* what they granted, and
  it stops working when they leave.

## Keys

| Key | Looks like | Held by | Reaches | Bounded by |
|---|---|---|---|---|
| **Secret key** | `cbid_env_…` | your backend, a CI job, an agent | one environment's API and `/mcp`, on that environment's host | its scopes, and its approval policy if it has one |
| **Organization key** | `cbid_ws_…` | automation for the organization | the organization API and the root's `/mcp` | a built-in role, narrowed by optional scopes |
| **Publishable key** | `pk_live_…`, `pk_test_…` | a browser bundle, in public | the Frontend API, from the origins you list | its origin list |
| **Member API key** | your app's prefix, e.g. `acme_live_…` | the people using your app | **your** app's API, which verifies it with Cbox ID | what your app decides |
| **App credentials** | a client ID and a client secret | an app that signs people in | the OAuth and OpenID Connect endpoints | the grants and redirect URIs of the app |

- **Secret keys** are the management keys. In an environment console they are listed on
  **AI agents › Agents**, each as the agent (or backend) that holds it, which is where an
  **agent key** comes from: an agent key is a secret key with a name, scopes chosen from a
  preset, an expiry and, usually, an approval policy. See [API keys](../guides/keys.md#secret-keys)
  and [Agents and MCP](../guides/agents-and-mcp.md#option-2-a-management-key).
- **Organization keys** carry Admin, Developer, Member or Viewer and can do only what that
  role may do. See [API keys](../guides/keys.md#organization-keys).
- **Publishable keys** are public on purpose; the origin list is what makes them safe. See
  [Integrate your app](../getting-started/integrate-your-app.md#6-talking-to-cbox-id-from-the-browser).
- **Member API keys** are the keys your customers create for *your* product's API, on a
  hosted page, without you building a key table. See
  [Let your customers create API keys](../getting-started/let-your-customers-create-api-keys.md).
- **App credentials** are not management keys at all; they belong to an application on
  [Applications](../guides/apps-and-api-keys.md).

A secret or organization key is **shown once**: only a hash is stored. Creating or revoking
one asks the person doing it to confirm it is them, and both are recorded on the
[Audit log](../guides/activity-log.md).

## Delegated tokens

| Token | How it is obtained | Acts as | Reaches |
|---|---|---|---|
| A sign-in token in your app | your app's sign-in (authorization code + PKCE) | the user | your app's APIs, `/userinfo` |
| An MCP sign-in | an MCP client sends you through sign-in and consent | you, through that client | `/mcp` on the host you signed in to, audienced to it |
| A `cbox login` token | the device flow at the platform root | you, a member of your organization's team | every plane at the root, within your role |
| An agent acting for a user (CIBA) | an AI agent application asks the person to approve on their device | that person | what they approved |

A delegated token is held to **two limits**: the scopes the person granted, and what the
person may do themselves, here, right now. A scope never gives more than the person's role.
Every [critical](actions.md#danger) action a delegated token tries **waits for the
person's approval** on their device, whatever its scopes. See [Approvals](approvals.md).

## The ID token and UserInfo

After a sign-in your app holds an ID token and can ask `/oauth/userinfo` about the person.
By default the ID token is small: who signed in (`sub`), the organization the sign-in is for
(`org`, `org_name`, `org_role`) and, under their own scopes, `groups` and `feature_flags`.
The person's `email` and `name`, their `roles` and `permissions` in your app, `org_slug` and
the `organizations` list are answered by UserInfo.

An app that holds the `identity_claims` scope and asks for it gets those claims on the ID
token as well, each under the scope it needs at UserInfo (`email`, `profile`,
`organizations`), so its backend makes one call at sign-in instead of two.

| | |
|---|---|
| Who gives it | An environment administrator, on the app's Scopes tab ("Full identity on the ID token") or with `PUT /api/v1/apps/{id}/scopes` (`apps.scopes.set`, also an MCP tool and a `cbox id` command). The same in the platform's own environment. |
| Who cannot | The app itself. The scope is not in discovery's `scopes_supported`, and a self-registering client is not allowed it. |
| Asking without holding it | `error=invalid_scope` back to the app's callback, like any scope the app is not registered for. |
| Holding it without asking | Nothing changes: the ID token stays small. |

**Sign-out.** The ID token is what an app sends back as `id_token_hint` to end the person's
session. With `identity_claims` that token names the person, so it must not travel in a
URL, which is written to access logs, kept in browser history and sent on as `Referer`.
`/oauth/logout` takes the same parameters by POST (OIDC RP-Initiated Logout 1.0 §2): send
the hint in the request body, or leave it out and send `client_id`. Give the scope only to
an app that does one of the two.

## Which one do I want?

| You are… | Use |
|---|---|
| a backend creating organizations and users | a secret key with only the scopes it needs |
| an AI agent running unattended | a secret key created on **AI agents › Agents**, with an approval policy |
| you, working with Claude Code or Cursor at your desk | an MCP sign-in at the root's `/mcp`: no key to leak |
| a script provisioning environments | an organization key with the Developer role |
| a browser drawing its own sign-in form | a publishable key |
| an app signing people in | app credentials, through an SDK — see the [quickstarts](../quickstarts/_index.md) |

## Related

- [Planes and hosts](planes-and-hosts.md): where each credential is accepted.
- [Approvals](approvals.md): when a person has to say yes first.
- [API keys](../guides/keys.md): creating, expiring and revoking keys in the console.
