---
title: Social sign-in and connected accounts
weight: 35
description: Let people sign in with Google, Microsoft, GitHub, Discord, Apple, Facebook and others, connect a provider to an account they already have, and understand why we never merge two accounts because their email addresses match.
---

# Social sign-in and connected accounts

**Console page:** Sign-in › Connections

Single sign-on connects your *company's* identity provider. Social sign-in is the
other case: individual people arriving with an account they already hold somewhere
else. Both run through the same connection machinery, so what you learn here applies
to either.

You supply the client credentials. Everything else about a provider — its endpoints,
the scopes to request, where the identity sits in the response, whether it even speaks
OpenID Connect — comes from a built-in catalogue, so connecting Google does not require
knowing that its issuer is `https://accounts.google.com`.

## Providers in the catalogue

| Provider | Protocol | Notes |
| --- | --- | --- |
| Google | OpenID Connect | |
| Microsoft Entra ID | OpenID Connect | You supply the directory (tenant) id |
| Okta | OpenID Connect | You supply your Okta domain |
| Auth0 | OpenID Connect | You supply your Auth0 domain |
| Keycloak | OpenID Connect | You supply the base URL and realm |
| GitLab | OpenID Connect | |
| Slack | OpenID Connect | |
| GitHub | OAuth 2.0 | No OpenID Connect; see below |
| Discord | OAuth 2.0 | |
| Facebook | OAuth 2.0 | |
| Apple | OpenID Connect | The client secret is a signing key, not a password |

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

When the address is *not* already taken, a social sign-in creates the account there and
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
- [Apps and API keys](apps-and-api-keys.md)
