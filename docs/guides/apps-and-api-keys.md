---
title: Apps & API keys
weight: 20
description: Register an app so people can sign in to it with their Cbox ID account, configure its redirect URLs, and let it declare the roles it understands.
---

# Apps & API keys

**Console page:** Developers › Apps & API keys

Every app that signs people in through Cbox ID, or calls its API, is registered
here and gets its own credentials. Registering an app is what turns Cbox ID from a
directory into something your colleagues actually use — it is the step that gives
them a thing to sign in *to*.

## Register one

1. **New app**, and name it something you will recognise in a list.
2. Answer **what kind of app is this?** — the one question the form asks. Everything
   the standard would have you decide separately follows from it:

   | You answer | What it is for | What Cbox ID sets up |
   |---|---|---|
   | **Web app** | Runs on a server: Laravel, Rails, Next.js, Django. | A secret, the sign-in redirect flow, redirect URIs. |
   | **Single-page or mobile app** | Runs on the device: React, Vue, iOS, Android. | No secret (it could not keep one), sign-in with PKCE, redirect URIs. |
   | **CLI or device** | No browser of its own: a terminal, a CI job, a TV. | No secret, no redirect URI, the device grant. See [Sign in from a CLI](../getting-started/sign-in-from-a-cli.md). |
   | **Service or background job** | Calls the API as itself, no person involved. | A secret, client credentials, no redirect URI. |
   | **AI agent** | Acts on somebody's behalf, and asks them first. | A secret, and the approval flow behind [agent approvals](agent-approvals.md). |
   | **Something else** | A combination none of the above describes. | You pick the grants and whether it holds a secret. |

3. Copy the **client ID**, and the **client secret** if the kind you chose has one.
   The secret is shown once — put it straight into your app's configuration or secret
   store. A public app (single-page, mobile, CLI) is issued none on purpose: the code
   runs where a secret could be read out of it.
4. For the kinds that sign people in through a browser, add the **redirect URI**: the
   exact URL Cbox ID may send someone back to. It must match what the app asks for
   character for character, trailing slash and all.
5. Optionally add **post-logout redirect URIs** — where the app may send people
   after signing out. If none are listed, Cbox ID keeps them on its own signed-out
   page.
6. Optionally set a **role manifest URL**, so the app declares the roles it
   understands and they appear on the [Roles](roles.md) page for you to assign.

## Things worth knowing

- **One registration per app, per environment.** Sharing credentials between a
  staging and a production deployment means you cannot revoke one without taking
  down the other, and the activity log can no longer tell you which one did what.
- **The secret cannot be recovered, only replaced.** That is deliberate. If it is
  lost, or has ever been pasted somewhere it should not have been, rotate it.
- **Redirect URIs are a security control, not configuration.** They are the reason
  an attacker cannot have your app's sign-in send the resulting code to their
  server. Keep the list exact and short.
- **First-party apps appear in your team's launcher** on the console overview, so
  people can get to them without a bookmark.
- **Scopes are not permissions.** What you tick when registering an app is the
  *ceiling on what that app may ask for*. What a *person* is allowed to do is a
  [permission](permissions.md), composed into a [role](roles.md). The two words are
  easy to swap; the pages link to each other for that reason.

## Troubleshooting

**"redirect_uri mismatch"** — the URI the app sent is not in the list, exactly.
Compare them character by character; it is almost always a trailing slash, `http`
vs `https`, or a port.

**The app signs people in but sees no roles** — nothing has been assigned, or the
app has not declared the roles it expects. See [Roles](roles.md).

**`invalid_scope` from a CLI or an agent** — it asked for a scope the app is not
registered for. Those flows are refused rather than quietly given less, because no
browser is in front of them to notice a smaller grant. Either ask for less, or add the
scope to the app here.

## Related

- [Roles](roles.md), [Webhooks](webhooks.md), [Token vault](token-vault.md).
