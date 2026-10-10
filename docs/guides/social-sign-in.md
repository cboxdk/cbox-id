---
title: Social login and connected accounts
weight: 35
description: Turn on Google, Microsoft, GitHub, Discord, Apple, Facebook and others for your whole environment — or one organization — connect a provider to an account people already have, and understand why we never merge two accounts because their email addresses match.
---

# Social login and connected accounts

**Console page:** Authentication › Social login in the environment console (Sign-in › Social login on an organization's console)

Enterprise SSO connects your *company's* identity provider. Social login is the
other case: individual people arriving with an account they already hold somewhere
else. Both run through the same connection machinery, so what you learn here applies
to either.

You supply the client credentials. Everything else about a provider — its endpoints,
the scopes to request, where the identity sits in the response, whether it even speaks
OpenID Connect — comes from a built-in catalogue, so connecting Google does not require
knowing that its issuer is `https://accounts.google.com`.

## Providers in the catalogue

| Provider | Protocol | What you supply beyond the client ID and secret |
| --- | --- | --- |
| Google | OpenID Connect | Nothing |
| Slack | OpenID Connect | Nothing |
| GitHub | OAuth 2.0 | Nothing — see below |
| Discord | OAuth 2.0 | Nothing |
| Facebook | OAuth 2.0 | Nothing |
| Microsoft Entra ID | OpenID Connect | Your directory (tenant) ID |
| Okta | OpenID Connect | Your Okta domain |
| Auth0 | OpenID Connect | Your Auth0 domain |
| GitLab | OpenID Connect | Your GitLab host (`gitlab.com`, or your own) |
| Keycloak | OpenID Connect | Your Keycloak host and realm |
| Apple | OpenID Connect | Team ID, Key ID and the `.p8` signing key — see below |

Entra deserves one note. You supply your **own** directory ID, not `common`. The
multi-tenant `common` endpoint publishes an issuer with a placeholder in it rather than
a real one, and we refuse that on purpose: accepting it would mean accepting tokens
issued by any Entra tenant in the world, not just yours.

Two of these behave differently enough to call out.

**GitHub** is not an OpenID Provider. There is no `id_token` and no signature over the
profile. What we rely on instead is that the code was exchanged at GitHub's own token
endpoint using your client secret, and that the profile came back from the endpoint the
catalogue names — not one an administrator typed. GitHub also returns `email: null` for
anyone who has not made theirs public, which is the default, so we ask its address
endpoint and take the address marked primary.

**Apple** has no client secret to paste. The secret is a short-lived token minted from a
signing key you download from Apple, and it expires within six months. A setup that
treats it as a text field will fail half a year later on a day nobody touched it.

## Turn on Google for your app

Set a provider up once for the **environment** and it appears on every sign-in page in it —
the plain one, and every organization's. This is the usual case:

1. **Authentication › Social login**, then **Google** under **Add a provider**.
2. **Who is it for?** is already **The whole environment**. Leave it.
3. Copy the **redirect URI** and register it in Google's console. It is the real one: the
   id it contains is reserved for this provider when the form opens, and stays the same if
   you reload while you are in Google's console.
4. Paste the client ID and secret Google gave you, then **Turn on Google for everyone**.

For an OpenID Connect provider we run discovery the moment you save, so a mistyped domain
fails with the provider's own error while you are still looking at the form — not silently,
later, for one of your users. Nothing is offered on a sign-in page until it has been saved.

The setup screen is ordered the way the work goes: the redirect URI first ("the redirect URI
does not match" is the most common way any of these fails, and the error names the client id
rather than the URI); then the provider's own steps, beside the fields rather than linked
away to; then what the provider gave you — usually a client ID and secret, plus anything
per-installation (your Okta domain, your Entra directory id). **Extra scopes** is optional,
for an app that needs more from the provider than sign-in does, such as `read:org` on
GitHub. Sign-in's own scopes are always requested.

## One organization's own provider

An organization can bring its own credentials for a provider, offered on its own sign-in
page in place of the environment's. Choose the organization under **Who is it for?**, or
open **Social login** filtered to it.

On one organization's sign-in page, for each provider, the first of these that applies wins:

1. **The organization has its own.** Its own is used. If it turned its own off, the button
   is gone — the environment's credentials do not stand in for it.
2. **The organization turned the environment's off** for its page. No button.
3. **The environment has it, turned on.** The environment's is offered.

The plain sign-in page, before anybody has said which organization they belong to, shows
the environment's providers.

Social login filtered to one organization shows exactly what its page offers, and where
each button comes from. **Turn off here** removes one of the environment's from that
organization's page without setting up its own; **Offer it again** brings it back.

Two things to know:

- **Signing in with an environment provider does not make anyone a member** of the
  organization whose page they were on. Holding a Google account says nothing about
  belonging to Acme. An organization's *own* provider adds the person as a member, as
  before.
- **Turning a provider off for an organization only removes the button.** It does not stop
  somebody signing in with that provider elsewhere. An organization that must keep every
  other way in closed requires SSO on its **Authentication policy** tab.

## Change, turn off, remove

- **Change** replaces the client ID, secret, provider values or extra scopes. A secret or
  private key left empty keeps the one on file. The redirect URI does not change, so there
  is nothing to update at the provider.
- **Turn off** takes the button away and keeps the credentials, so **Turn on** brings it
  back as it was.
- **Remove** deletes the provider and its credentials.

Anyone who signed in with a provider keeps their account and can still use their password.

### Through the management API

Every step is an action, the same one the console runs:

| Action | Request |
|---|---|
| `signin.social.set` | `POST /v1/sign-in/social-providers` with `environment_wide: true`, or `organization_id` |
| `signin.social.update` | `PATCH /v1/sign-in/social-providers/{id}` |
| `signin.social.enable`, `signin.social.disable` | `POST /v1/sign-in/social-providers/{id}/enable` and `/disable` |
| `signin.social.delete` | `DELETE /v1/sign-in/social-providers/{id}` |
| `signin.social.inherit` | `PUT /v1/sign-in/social-providers/inherited/{provider}` with `organization_id` and `offered` |
| `signin.social.list` | `GET /v1/sign-in/social-providers`, optionally `level=environment` |
| `signin.social.offered` | `GET /v1/sign-in/social-providers/offered?organization_id=…` — what one page shows, and from where |

`signin.social.set` refuses a request that names neither an organization nor
`environment_wide: true`: a provider on every customer's page should not be what a
forgotten field means. Turning a provider on — when it is created, or turned back on — sends
the `connection.activated` webhook. Every change is on the audit log.

### Your credentials, not ours

If the platform operator has configured a provider, it appears on every sign-in page in
the deployment. When your environment or organization has connected the same provider,
yours is used. That matters because the accounts people end up with should sit with the
environment and organization that invited them.

## Connecting a provider to an existing account

Someone who already signs in with a password can add a provider from
**My account › Connected accounts**. Doing so requires being signed in *and* completing
the provider's own sign-in, so both sides are proven. They can disconnect it again from
the same place — unless it is the only way they can get in, which we refuse.

## What happens when the email address is already taken

This is the part worth understanding, because the obvious behaviour is the wrong one.

Someone signs in with GitHub. GitHub says the address is `dana@acme.test`. That address
already belongs to an account here. The tempting move is to treat it as the same person
and sign them in.

**We never do that.** An address a provider hands us is a claim, not proof. Some
providers do verify addresses; others let you type whatever you like. Even for the ones
that do, their verification is a statement about their relationship with that person,
not about ours. If merging on a matching address were enough, anyone able to set an
address at any connected provider could walk into the account that owns it.

So instead:

1. The identity is held aside, and the person is asked to sign in to the existing
   account normally.
2. Once signed in, we ask them plainly: *someone just signed in with GitHub as this
   address — do you want to connect it?*
3. **Yes** links the two, and they can use either from then on. **No** discards it and
   changes nothing.

Confirming proves three things at once: control of the provider account, control of
this account, and intent. That is strictly more than a matching address ever showed.

The held identity expires after ten minutes and is bound to the account that was asked.
It cannot be claimed by a different account, including another one signed in in the same
browser.

### Why we do not require the addresses to match

An earlier version linked automatically when the held identity's address equalled the
account's. That rule was wrong in both directions. It was too strict, because people
have several addresses at one provider — a GitHub account may carry five, and the
provider chooses which one to send — so legitimate links were silently discarded and
the feature simply appeared not to work. It was also too weak, because it leaned on the
provider having verified the address, which is the one assumption we had already decided
not to make.

If you cannot complete the sign-in, use password reset on the existing account.

## Signing up with a provider

When the address is *not* already taken, a social login creates the account there and
then — that is the point of one-click sign-in.

That account is a signup like any other, so it carries the same obligations:

- **The address is unverified until we verify it.** We send our own confirmation link.
  Whatever the provider asserted does not count.
- **We prompt for a password.** A brand-new social account has exactly one way in and it
  belongs to somebody else. If the provider is unreachable, or the person loses that
  account, this one goes with it. A password is also what every step-up prompt asks for.

The prompt is a prompt, not a wall — holding someone on a form at the first moment of a
one-click sign-in defeats the purpose. Both actions live on **My account**, which is
where they will still be tomorrow.

## What an unverified account can and cannot do

An unverified account can sign in and read. It cannot **create** things:
applications, identity connections, roles or webhooks.

The reason is that those are durable objects other people come to trust, and an
unverified address is one that may genuinely belong to somebody else. Refusing to create
them costs the legitimate owner one click in their inbox and costs an impostor the whole
attempt. The refusal says so, and says where the link is.

This applies to every account, not only social ones — an ordinary signup is unverified
until the link is clicked too.

## Related

- [Single sign-on](single-sign-on.md) — connecting a company identity provider
- [Apps](apps-and-api-keys.md)
