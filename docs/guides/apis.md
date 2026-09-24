---
title: APIs
weight: 21
description: Register the APIs your apps call, decide which of their scopes each app may be given, and see what audience their tokens carry.
---

# APIs

**Console page:** Developers › APIs (`/admin/apis`, in an environment console)

An **API** here is a service of yours that receives access tokens — `https://api.example.com`,
say. Registering it tells Cbox ID three things it cannot otherwise know:

- **Its identifier.** The address tokens for it name as their audience (the `aud` claim),
  and the value your service checks before it trusts a token.
- **The scopes it owns.** `invoices:read`, `invoices:write` — and, scope by scope, whether
  apps registered by the organizations in this environment may be given them.
- **Whose roles it enforces.** Optionally, the app whose declared roles and permissions a
  token for this API should carry.

## Why register one

Until an API is registered, a scope is just text on an app. Anybody who can edit an app —
including an organization's own administrator on their console — can type
`invoices:write` onto it and receive a token whose audience and scope say exactly what
your service is waiting to read.

A registered API's scopes have an owner. An app may be given one of them only when its
owner allows it, Cbox ID refuses to save anything else, and a typed scope can never carry
a registered API's audience. The check runs when an app is saved *and* when a token is
minted, so a scope typed onto an app before the API was registered is left out of its
tokens too.

## Register one

1. **New API.** Give it a name you will recognise, and its **identifier** — an absolute URL
   with a host, such as `https://api.example.com`. The identifier cannot be changed later:
   every token already issued carries it, and every service checking for it would break.
   `https://api.example.com` and `https://api.example.com/` are different identifiers.
2. Choose the **owner**:

   | Owner | Who may be given its scopes |
   |---|---|
   | **This environment** | Your own apps may hold any of them. An app an organization registered may hold only the scopes marked **Organizations' apps may request this**. |
   | **An organization** | Only that organization's own apps. To register an API for an organization, choose the organization in the console header first. |

3. Optionally choose **Roles and permissions from** — the app whose declared roles and
   permissions tokens for this API carry. It must have the same owner as the API. Left
   empty, each token carries the roles of the app that asked for it.
4. On the API's page, **add its scopes**: a key, a description, and — on an API the
   environment owns — whether organizations' apps may request it. Scope keys are unique in
   the environment, because a token request names a scope by its key alone. The sign-in
   scopes (`openid`, `profile`, `email`, `offline_access`, `organizations`, `groups`) are
   Cbox ID's own and cannot belong to an API.

Every change — registering, renaming, linking an app, adding, changing or removing a
scope, deleting — is recorded on the activity log: on the owning organization's trail for
an organization's API, and on the environment's for its own.

## Why only the environment's administrators register APIs

An identifier and a scope key are **first come, first served** in an environment. If an
organization's administrator could register APIs, the first to register
`https://api.example.com` or `invoices:read` would own it — and could claim the audience
or the scopes another organization, or you, meant to use. So APIs are registered from the
environment console, and an API that belongs to one organization is registered there and
assigned to it.

## Give an app an API's scopes

On the app's **Scopes** tab, under **Your APIs**, every registered API the app may hold
scopes of is listed with those scopes. An organization's administrator sees their own
organization's APIs and the scopes you let every organization's apps request — never
another organization's API, and never a scope you kept for your own apps.

If somebody types a scope the app may not hold under **Advanced**, the save is refused and
the page says which scope, which API it belongs to and why.

## The audience a token gets

The Scopes tab says what `aud` the app's tokens will carry, using the same rule as the
token endpoint:

- **No registered scope:** the audience is this environment's issuer, exactly as before
  any API was registered.
- **Scopes of one API:** the audience is that API's identifier. If the app also signs
  people in (`openid`), the issuer is added too, so the app's sign-in keeps working.
- **Scopes of more than one API:** a token is for one API at a time. The app names the one
  it wants with the `resource` parameter; asking for scopes of two APIs without one is
  refused with `invalid_target`.

Typed scopes that no API owns travel as they are, and never on a registered API's
audience. Your service should accept a token only when `aud` names it.

## Removing and deleting

- **Removing a scope** turns it back into typed text on the apps that held it. It no
  longer reaches the API.
- **Deleting an API** removes its scopes the same way. Tokens already issued for it keep
  working until they expire; nothing new is issued for its audience.

## Related

- [Apps](apps-and-api-keys.md) — the Scopes, Secrets and Settings tabs of an app.
- [Permissions](permissions.md) and [Roles](roles.md) — what a *person* may do, as opposed
  to what an app may ask for.
