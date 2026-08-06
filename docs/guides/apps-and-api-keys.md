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

Two shapes, registered the same way:

- **Apps people sign in to.** They redirect the person to Cbox ID, and get back an
  identity plus that person's roles.
- **Machine-to-machine clients.** No person involved; the app authenticates as
  itself to call the API.

## Register one

1. **New app**, and name it something you will recognise in a list.
2. Copy the **client ID** and **client secret**. The secret is shown once — put it
   straight into your app's configuration or secret store.
3. Add the **redirect URI**: the exact URL Cbox ID may send someone back to after
   they sign in. It must match what the app asks for character for character,
   trailing slash and all.
4. Optionally add **post-logout redirect URIs** — where the app may send people
   after signing out. If none are listed, Cbox ID keeps them on its own signed-out
   page.
5. Optionally set a **role manifest URL**, so the app declares the roles it
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

## Troubleshooting

**"redirect_uri mismatch"** — the URI the app sent is not in the list, exactly.
Compare them character by character; it is almost always a trailing slash, `http`
vs `https`, or a port.

**The app signs people in but sees no roles** — nothing has been assigned, or the
app has not declared the roles it expects. See [Roles](roles.md).

## Related

- [Roles](roles.md), [Webhooks](webhooks.md), [Token vault](token-vault.md).
