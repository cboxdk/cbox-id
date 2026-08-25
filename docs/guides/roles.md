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

## Roles that apply everywhere

Every grant above is scoped to one organization, which is right for the people who work
inside one. It cannot describe three others:

- **Your own support staff**, who act across every customer.
- **Somebody who has joined no organization** — a person exists before they belong
  anywhere.
- **An app with no tenancy of its own.** If your service provider has no notion of
  customers, there is no organization to hang a grant on, and it should not have to
  invent one.

For those, define the role as **Environment-wide** when you create it, then grant it from
the person's page under **Roles everywhere in this environment**. It applies in every
organization *and* to a person who belongs to none, and it comes through in the token like
any other role.

Two things to know before you use it:

- **Only an environment-wide role can be granted this way.** A role belonging to one
  organization is that customer's own policy, named by them; handing it out across the
  environment would give every other customer a role they never defined. The console
  offers only the eligible ones, and the write refuses the rest.
- **It stacks, it does not replace.** Somebody can hold `Support` everywhere and `Editor`
  in one organization, and their token in that organization carries both.

[Role conflicts](role-conflicts.md) see these grants: a pair that must never sit with one
person is still refused when one half is environment-wide and the other belongs to an
organization.

> **Access reviews do not, yet.** A review is opened for one organization and enumerates
> the grants made *in* it, and an environment-wide grant belongs to none — so it is not
> listed, certified or revoked there. Until that is settled, treat these grants as
> something you audit deliberately rather than something a campaign will surface. They are
> recorded on the environment's audit trail when made and withdrawn.

## Things worth knowing

- **Roles are not organization membership.** Being an owner or admin of the
  organization governs the *console*; roles govern *your apps*.
- **Some role pairs should be impossible.** If two roles must never sit with the
  same person, declare that as a [role conflict](role-conflicts.md) rather than
  relying on everyone remembering.
- **Grants accumulate.** People change teams and keep what they had; that is what
  [access reviews](access-reviews.md) exist to clear out.

## If your app asks for "groups"

Plenty of software — Kubernetes, Grafana, Vault, and most SaaS written before this
vocabulary settled — authorizes from a **`groups`** claim on the ID token. There is no
separate Groups page here, and you are not missing one: **your roles are those groups.**

Tick the **`groups`** scope when you register the app under
[Apps & API keys](apps-and-api-keys.md), and the ID token carries the person's role names
under the name that software already looks for:

```json
{
  "sub": "…",
  "groups": ["Support agent", "Editor"]
}
```

So name the role whatever the consuming app expects to see, assign people to it, and it
arrives. Nothing else to create.

> **Not to be confused with directory groups.** The *Sync users in* page also talks about
> groups, and those go the other way: a customer's own identity provider pushes its groups
> to Cbox ID over SCIM, and you map each one **onto** a role. They never reach a token
> themselves. That page is for when somebody *else* is the identity provider. When Cbox ID
> is your identity provider, roles are the whole story.

## Related

- [Permissions](permissions.md) — the individual capabilities a role is built from.
- [Apps & API keys](apps-and-api-keys.md) — where an app's manifest URL is configured.
- [Access reviews](access-reviews.md), [Role conflicts](role-conflicts.md).
