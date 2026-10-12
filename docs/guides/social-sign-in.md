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

**These buttons are for the people who sign in to your apps**, on your environment's hosted
pages. Your environment's console (`/admin`) is not signed in to with them. Its
administrators are Cbox ID accounts and sign in at the platform's root, with the platform's
own sign-in methods. The page says which of the two it is.

**Google on cboxid.com itself.** That is the platform's own environment. Its operators turn
it on the same way, in that environment's console at `cboxid.com/admin`. The redirect URI to
register is on the root host. The button then appears on `cboxid.com/login`, the sign-in
for every workspace owner and operator and for every environment console. See
[Administering the platform's own environment](../operations/platform-environment.md).

On a single-tenant install, platform operators and owners of the install's own organization
(see [Authentication policy](authentication-policy.md)) find the same form under
**Sign-in › Social login** on the organization console, with **Who is it for?** set to **Every sign-in page in this
environment**; choose **Only this organization's sign-in page** for the organization's own.

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

Someone who is signed in can add a provider from **My account › Sign-in methods**. The
section lists every way into the account: whether a password is set, how many passkeys
there are, and every linked provider account — its name, the address the provider gave,
when it was linked and when it was last used. Under it is a **Connect** button for every
provider this environment offers that is not linked yet.

Connecting needs a fresh step-up (your password again, or a passkey) and then the
provider's own sign-in, so both sides are proven. It comes back through the same redirect
URI the sign-in button uses — the one you registered with the provider — so there is
nothing extra to configure. The provider's address does not have to match the account's.

**Disconnect** removes one linked identity. It is refused when it would leave the account
with no way in at all — no password, no passkey and no other linked provider — and the page
says so. An organization's own single sign-on is listed but cannot be disconnected here:
it is how the organization knows the person, and their next sign-in through it would bring
it straight back.

Over the API: `DELETE /api/v1/me/sign-in-methods/{identity_id}` (action
`account.sign_in_methods.disconnect`, scope `account:sign_in:write`), and the same action
over MCP. Connecting is a browser ceremony and has no API equivalent.

## What happens when the email address is already taken

This is the part worth understanding, because the obvious behaviour is the wrong one.

Someone signs in with Google. Google says the address is `dana@acme.test`. That address
already belongs to an account here. The tempting move is to treat it as the same person
and sign them in.

**We never do that.** An address a provider hands us is a claim, not proof of owning the
account here. If merging on a matching address were enough, anyone able to get a provider
to assert an address could walk into the account that uses it.

Refusing outright is wrong too: the legitimate owner is stuck. So the sign-in stops on a
**link step** — *"Link your Google account to dana@acme.test?"* — hosted, in the
environment's branding and in the visitor's language:

1. The person proves they hold the existing account. What counts follows from what the
   account already demands:
   - an account with a **second factor** answers with it — the authenticator app (or a
     recovery code), or a texted code. A password alone is not accepted, because a link is a
     new way in that never asks for that factor again;
   - otherwise the **password**, or a **one-time code mailed** to the account's own address
     (no weaker than the password: a password reset needs nothing more);
   - an account with neither gets the mailed code straight away.
2. The provider identity is linked — on the provider's stable subject id, never the
   address — and the person is signed in and sent where they were going, exactly as a
   sign-in through that provider would have been. That includes the hand-off into a
   tenant's `/admin` from the platform's sign-in page.
3. **Don't link** drops it and changes nothing.

The rules around it:

- **Only when the provider vouched for the address.** Google's `email_verified`, GitHub's
  verified address list, an organization's SSO for a domain it has verified. Otherwise the
  person is told that the provider has not confirmed the address, and that they can sign in
  another way and connect the provider from My account.
- **Short-lived and single-use.** The held identity lives in the server-side session,
  signed with the application key, for ten minutes. It is spent by the answer that links
  it; five wrong answers drop it, and they count against the account's lockout too.
- **Radar.** A block ends the attempt with a generic sentence. A challenge takes the
  password off the table — a code from the inbox (or the second factor) is asked for
  instead.
- **SSO mandates.** If the account's organization requires its own single sign-on, a link
  through any other provider is refused with the same explanation every other door gives.
- **Email confirmation.** A code answered from the account's inbox confirms the address.
  The provider's say-so never does.
- **Audit.** Every link is recorded as `user.identity_linked` — by the person, with the
  provider, the proof used (`password`, `email_code`, `totp`, …) and whether it came from
  the link step or My account. A disconnect is `user.identity_unlinked`.

## When a sign-in with a provider does not complete

Whatever the reason — the person pressed Cancel at the provider, the sign-in link expired
or was replayed, the provider's answer did not verify, the provider could not be reached,
the provider was switched off, or a link was refused — they land on the sign-in page with
a sentence saying which, in their language. Somebody already signed in (connecting from My
account) sees it on My account instead.

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
