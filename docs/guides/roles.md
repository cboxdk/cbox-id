---
title: Roles
weight: 10
description: How roles work in Cbox ID — you assign them, each app decides what they mean — and how an app declares the roles it understands.
---

# Roles

**Console page:** People › Roles

A role is a job title your apps understand: `Editor`, `Support agent`,
`Read-only`. You assign roles to people here; **each app decides for itself what
its roles are allowed to do**. That split is the whole design, and it is worth
understanding before you create anything:

- **Cbox ID owns who holds which role.** One place to grant, one place to revoke,
  one record of both.
- **The app owns what the role can do.** No app has to ask us for permission
  semantics, and we never have to model every app's internal rules.

The role travels with the person into every connected app, in their token. Revoke
it here and every app sees that on their next sign-in.

## Two kinds of role

**Org roles** are yours: you create them for your organization, and they mean
whatever your apps agree they mean.

**App-declared roles** come from the app itself. An app tells Cbox ID which roles
it understands — either by publishing a manifest at a URL you configure, or by
pushing one — and those roles then appear here, ready to assign. This is the
better path when the app has real internal permissions, because the list can never
drift out of date with the app's own code.

An app-declared role whose app stops declaring it is marked **orphaned** rather
than silently removed, so an existing grant is never quietly reinterpreted.

## Saying what a role may do, here

If you want "what can an Editor actually do" written down in the console rather than left
to each app's own code, that is what [permissions](permissions.md) are — and you can write
them yourself on the Permissions page without any integration at all.

## Assigning

- Person by person on the **Members** page.
- Automatically, by mapping a group from your identity provider onto a role — see
  [Sync users in](sync-users-in.md). For anything above a handful of people this is
  the one to use: access follows the group, and the group is already someone else's
  job to maintain.
- At invite time, so someone has the right access the moment they accept rather
  than after a second chore.

## Things worth knowing

- **Roles are not organization membership.** Being an owner or admin of the
  organization governs the *console*; roles govern *your apps*.
- **Some role pairs should be impossible.** If two roles must never sit with the
  same person, declare that as a [role conflict](role-conflicts.md) rather than
  relying on everyone remembering.
- **Grants accumulate.** People change teams and keep what they had; that is what
  [access reviews](access-reviews.md) exist to clear out.

## Related

- [Permissions](permissions.md) — the individual capabilities a role is built from.
- [Apps & API keys](apps-and-api-keys.md) — where an app's manifest URL is configured.
- [Access reviews](access-reviews.md), [Role conflicts](role-conflicts.md).
