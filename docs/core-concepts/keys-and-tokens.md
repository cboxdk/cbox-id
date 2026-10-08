---
title: Keys and tokens
weight: 15
description: Every credential Cbox ID issues — secret, publishable, agent, member and workspace keys, app credentials, and the tokens a person delegates — and which one to reach for.
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
| **Workspace key** | `cbid_ws_…` | automation for the workspace | the workspace API and the root's `/mcp` | a built-in role, narrowed by optional scopes |
| **Publishable key** | `pk_live_…`, `pk_test_…` | a browser bundle, in public | the Frontend API, from the origins you list | its origin list |
| **Member API key** | your app's prefix, e.g. `acme_live_…` | the people using your app | **your** app's API, which verifies it with Cbox ID | what your app decides |
| **App credentials** | a client ID and a client secret | an app that signs people in | the OAuth and OpenID Connect endpoints | the grants and redirect URIs of the app |

- **Secret keys** are the management keys. In an environment console they are listed on
  **AI agents › Agents**, each as the agent (or backend) that holds it, which is where an
  **agent key** comes from: an agent key is a secret key with a name, scopes chosen from a
  preset, an expiry and, usually, an approval policy. See [API keys](../guides/keys.md#secret-keys)
  and [Agents and MCP](../guides/agents-and-mcp.md#option-2-a-management-key).
- **Workspace keys** carry Admin, Developer, Member or Viewer and can do only what that
  role may do. See [API keys](../guides/keys.md#workspace-keys).
- **Publishable keys** are public on purpose; the origin list is what makes them safe. See
  [Integrate your app](../getting-started/integrate-your-app.md#6-talking-to-cbox-id-from-the-browser).
- **Member API keys** are the keys your customers create for *your* product's API, on a
  hosted page, without you building a key table. See
  [Let your customers create API keys](../getting-started/let-your-customers-create-api-keys.md).
- **App credentials** are not management keys at all; they belong to an application on
  [Applications](../guides/apps-and-api-keys.md).

A secret or workspace key is **shown once**: only a hash is stored. Creating or revoking
one asks the person doing it to confirm it is them, and both are recorded on the
[Audit log](../guides/activity-log.md).

## Delegated tokens

| Token | How it is obtained | Acts as | Reaches |
|---|---|---|---|
| A sign-in token in your app | your app's sign-in (authorization code + PKCE) | the user | your app's APIs, `/userinfo` |
| An MCP sign-in | an MCP client sends you through sign-in and consent | you, through that client | `/mcp` on the host you signed in to, audienced to it |
| A `cbox login` token | the device flow at the platform root | you, a member of your workspace's team | every plane at the root, within your role |
| An agent acting for a user (CIBA) | an AI agent application asks the person to approve on their device | that person | what they approved |

A delegated token is held to **two limits**: the scopes the person granted, and what the
person may do themselves, here, right now. A scope never gives more than the person's role.
Every [critical](actions.md#danger) action a delegated token tries **waits for the
person's approval** on their device, whatever its scopes. See [Approvals](approvals.md).

## Which one do I want?

| You are… | Use |
|---|---|
| a backend creating organizations and users | a secret key with only the scopes it needs |
| an AI agent running unattended | a secret key created on **AI agents › Agents**, with an approval policy |
| you, working with Claude Code or Cursor at your desk | an MCP sign-in at the root's `/mcp`: no key to leak |
| a script provisioning environments | a workspace key with the Developer role |
| a browser drawing its own sign-in form | a publishable key |
| an app signing people in | app credentials, through an SDK — see the [quickstarts](../quickstarts/_index.md) |

## Related

- [Planes and hosts](planes-and-hosts.md): where each credential is accepted.
- [Approvals](approvals.md): when a person has to say yes first.
- [API keys](../guides/keys.md): creating, expiring and revoking keys in the console.
