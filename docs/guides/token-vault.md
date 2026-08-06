---
title: Token vault
weight: 80
description: Store the API keys your apps and agents present to other services, grant them per app, and rotate them centrally instead of in each app's configuration.
---

# Token vault

**Console page:** Developers › Token vault

The vault holds credentials your apps and agents need **for other services** — a
provider's API key, a partner's token. They are encrypted at rest, handed only to
the apps you explicitly grant, and never displayed again after you store them.

The alternative is the status quo everywhere else: the same key pasted into three
apps' environment files, unrotatable without a deploy, and impossible to answer
"who can use this?" about. Storing it here makes the answer a list you can read and
change.

## Store a secret

1. **New secret**: give it a name, the provider it belongs to, and the value.
2. Save. The value is sealed immediately — you will not see it again, so make sure
   it is right, or be prepared to rotate.
3. Open the secret's **grants** and add the apps allowed to use it. Nothing else
   can read it, including apps registered later.

Storing and revealing secrets is behind the **step-up (sudo) gate**: you will be
asked to re-authenticate even though you are already signed in. That is
deliberate — it means a hijacked console session is not automatically a key theft.

## Rotating

When the provider issues a new value, rotate it here. Granted apps keep working and
need no redeploy, because they never held the value themselves — they ask for it.

Rotate whenever a key has been in a place it should not have been: a chat message, a
ticket, a screen share, a laptop that left.

## Things worth knowing

- **A grant is a capability.** Granting an app access to a secret means anything
  that can act as that app can use the credential. Keep grants narrow.
- **This is not a password manager for people.** It is for apps and agents. Human
  credentials belong in your own password manager.
- **Every store, grant and rotation is recorded** in the [activity log](activity-log.md).

## Related

- [Apps & API keys](apps-and-api-keys.md) — the apps you grant.
- [Agent approvals](agent-approvals.md).
