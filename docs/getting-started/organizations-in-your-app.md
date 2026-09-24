---
title: Organizations in your app
weight: 22
description: How an app asks for an organization, lets people pick or switch one, lets them create one, and lets strangers sign up — the authorize parameters, the hosted pages, and the environment switch behind them.
---

# Organizations in your app

A person can belong to several organizations in one environment: their own company, a
client's, a team they were invited to. Every token Cbox ID issues is for **one** of them —
`org`, `org_name`, `org_role`, `roles` and `permissions` all describe the person *in that
organization*. This page is how your app decides which one.

The SDKs (`@cboxdk/id-js` and the React, Vue and Nuxt wrappers, `cboxdk/laravel-id-client`,
`id-go`) already send everything below. The raw parameters are listed for anyone building
the request by hand.

## Which organization a sign-in gets

| Your app sends | What happens |
| --- | --- |
| nothing | The organization the person is working in on Cbox ID (their session's). This is what every sign-in did before the parameters below existed. |
| `organization=<org id>` | Exactly that organization, or an `access_denied` error back to your callback. |
| `prompt=select_organization` | The hosted picker, always — even for someone in one organization. |
| `prompt=select_organization` + `organization_hint=<org id>` | The picker, with that organization marked as the suggestion. |
| `prompt=create_organization` | The hosted "Create an organization" step. The person becomes its Owner. |

Whatever was chosen is bound to the authorization code, and from there to the access
token, the ID token, UserInfo and **every refresh**. A refresh never changes organization.

## Switching organization

Switching is a new sign-in bound to the other organization:

```js
const request = await cbox.switchOrganization(globexId);
// persist request.state / codeVerifier / nonce / organization as for any sign-in,
// then redirect the browser to request.url
```

Cbox ID already holds the person's session, so this is a redirect there and straight back
with no form (or the consent screen, the first time for an app that is not first-party).
Replace your session with the tokens that come back — `roles` and `permissions` differ
between organizations, so patching the old session keeps the wrong ones.

`id-js` checks that the tokens are for the organization it asked for and throws if they are
not. A person who is not an **active** member of that organization comes back with
`error=access_denied` and the description *"The user is not an active member of the
requested organization."* — the same answer whether the organization does not exist, is in
another environment, was suspended or deleted, or the person left it, was suspended in it or
never accepted the invitation. Answer it by switching back, not by signing them out.

Under `prompt=none` the answer is the same `access_denied`, not `interaction_required`: no
page Cbox ID could show would make that account a member.

## The hosted picker

`prompt=select_organization` shows the organizations the signed-in person may use **in this
environment**: live organizations they hold an active membership in, each with their role.
It lists nothing else — not an invitation they have not accepted, not a suspended team, not
an organization in another environment.

Choosing one binds this sign-in only. The picker does **not** move the person's Cbox ID
console to that organization and does not remember the choice for the next app. The next
authorization without `organization` still gets their session's organization.

The suggestion is your `organization_hint` when it names one of theirs, otherwise the
organization they are working in. A hint naming anything else is ignored silently, so the
page never confirms to an app which organizations exist.

When the environment lets people create organizations (below), the picker also offers
**Create an organization**, and a person with no organization yet is sent there.

## The hosted "Create an organization" step

`prompt=create_organization` asks for a name, creates the organization through the same
services the console uses (so `organization.created` and the membership webhook fire and
the activity log records it), makes the person its **Owner**, and finishes the sign-in bound
to it — your app receives `org_role: owner`.

It is offered only where self-service sign-up is on for the environment, and a person can
create five organizations an hour.

## Self-service sign-up: `prompt=create`

`prompt=create` (OpenID Connect Prompt Create 1.0) sends somebody who is not signed in to the
environment's sign-up form instead of the sign-in form. They give their name, email,
password and **team or company name**, and come back to your app signed in, bound to the
organization they just created, as its Owner — "Anna signs up for your app and creates her
team" in one round trip. Somebody already signed in is not given a second account: the
sign-in continues as them. Send `prompt=login create` if you want a fresh sign-in first.

Sign-up applies everything the environment's sign-in rules already apply: password length
and the breach check, the confirmation email (the account works at once and the address is
confirmed out of band, as for any sign-up), the per-environment rate limit, the bot and risk
checks with a challenge when they are unsure, and home-realm capture — an address on a
domain whose organization requires SSO is sent to its identity provider instead.

## Turning it on

Self-service sign-up is **off** for every environment until an administrator turns it on,
under **Sign-in › Sign-in rules › Self-service sign-up** in the environment console. It
governs three things at once:

- the environment's `/signup` page and the "Create an account" link on its sign-in page;
- `prompt=create`;
- `prompt=create_organization`, and the "Create an organization" option on the picker.

With it off, all three are refused: `/signup` explains that joining is by invitation,
`prompt=create` and `prompt=create_organization` return `error=invalid_request` to your
callback, and a magic link is only sent to an address that already has an account. The
deployment's `CBOX_ID_SIGNUP_MODE=closed` still closes every environment regardless.

A single-tenant install has no switch: sign-up there follows `CBOX_ID_SIGNUP_MODE`, as it
always has.

## Discovery

The discovery document (`/.well-known/openid-configuration`, and the RFC 8414 document at
`/.well-known/oauth-authorization-server`) lists what the environment honours in
`prompt_values_supported`:

```json
"prompt_values_supported": ["none", "login", "consent", "select_account", "select_organization", "create_organization", "create"]
```

`create` and `create_organization` appear only while self-service sign-up is on, so an app
can decide from the document whether to show a "Sign up" button.

## Combinations that are refused

Each of these has two meanings, and is answered with `error=invalid_request` rather than a
guess:

| Request | Why |
| --- | --- |
| `organization` + `prompt=select_organization` | Nothing left to choose. Use `organization_hint` to preselect instead. |
| `organization` + `prompt=create_organization` | Bind to an existing one, or create a new one — not both. |
| `organization=` (present but empty) | Omit it to sign in without binding. |
| `prompt=none` with any other value | OIDC Core §3.1.2.1. |
| `organization` + `prompt=create` | A new account is not a member of anything yet. |
| `prompt=create` + `prompt=create_organization` | Sign-up already asks for the team. |

With pushed authorization requests (`/oauth/par`), `organization` and `organization_hint`
are read from the pushed request only. One added to the browser URL beside a `request_uri`
is ignored.

## Invitations that land in your app

An invitation sent with your app's `client_id` and a `return_to` sends the person to your
app when they accept. Accepting also moves their Cbox ID session into the organization they
just joined, so your app's next sign-in — even one without `organization`, because your app
does not know yet which team they joined — comes back bound to that organization. See
[Members and invitations](../guides/members.md).

## Where the person lands in Cbox ID's own console

None of this changes the organization the person sees in the Cbox ID console; they switch
that with the console's own organization switcher. Choosing an organization for an app and
choosing one for the console are separate decisions.
