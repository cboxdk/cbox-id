---
title: Permissions
weight: 11
description: What a permission is, how to write one in the console without any code, and who can see the ones you write.
---

# Permissions

**Console page:** People › Permissions

A [role](roles.md) is a job title — `Editor`, `Support agent`. A **permission** is one
specific thing a role is allowed to do: `invoices:create`, `reports:read`.

You do not have to use permissions at all. Plenty of organizations grant roles and let
each app decide internally what a role means. Permissions are for when you want that
decision written down here instead — so "what can an Editor actually do" has an answer you
can read, review and hand to an auditor, rather than one that lives in somebody's code.

## Writing one — no integration required

The **New permission** box at the top of the page is all of it. A key, optionally a
description, and Add.

A key is two words with a colon between them: the thing, then the action.

| Good | Why |
|---|---|
| `invoices:create` | The thing is a noun, the action is a verb. |
| `reports:read` | Reads and writes are worth separating. |
| `billing:refund` | Specific enough that "who can do this" is a real question. |

Lower-case, and no spaces. `invoices:*` means every action on invoices, if your apps
choose to honour it.

Once it exists it appears in the **Roles** editor, and you compose it into whichever roles
should have it.

There is nothing else to install. The API and SDKs can do the same thing, and an app that
already declares its own permissions will fill in that half by itself — but the console
form is the whole feature for an organization that does not want to write code.

## Who sees what you write

The page shows up to three groups, and the difference between them is who owns the row:

**Yours** — the ones you wrote. Only your organization sees them; other organizations on
the same platform never do. You can edit and delete them freely.

**From your environment** — permissions the operator of your environment publishes for
every organization. You compose them into your roles like any other, but you cannot change
or remove them, because they are not yours: other organizations are using the same ones.

**App-declared** — synced automatically from an app that publishes a manifest. These are
read-only here on purpose: the app is the source of truth, and it enforces them. If an app
stops declaring one it is marked **orphaned** rather than deleted, so an existing grant is
never quietly reinterpreted.

## Things worth knowing

- **Deleting one takes it out of every role that granted it.** The confirmation says so.
  This cannot be undone — you would have to recreate the permission and re-add it to each
  role.
- **Your apps have to honour it.** Cbox ID records that a role includes `invoices:create`
  and puts it in the token; the app is what refuses the button. A permission nobody checks
  is documentation, not enforcement.
- **You can reuse a name another organization uses.** Names only have to be unique within
  what you can see, so `billing:refund` being taken elsewhere is not your problem and you
  are never told about it.
- **A permission is not organization membership.** Owner and admin govern the *console*;
  permissions govern *your apps*.

## Related

- [Roles](roles.md) — what you compose permissions into, and how apps declare their own.
- [Role conflicts](role-conflicts.md) — pairs that must never sit with the same person.
- [Access reviews](access-reviews.md) — clearing out access that piled up.
