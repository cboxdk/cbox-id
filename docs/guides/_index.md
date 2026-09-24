---
title: Admin guides
weight: 25
description: Task-oriented guides for the people who administer an organization in the Cbox ID console — one per console page, linked from the "?" beside each page title.
---

# Admin guides

These are for the **administrator of an organization** — the person in the console
who connects an identity provider, invites the team, registers apps and answers to
an auditor. They are not developer documentation: building *against* Cbox ID lives
in the framework docs that ship with
[`cboxdk/laravel-id`](https://github.com/cboxdk/laravel-id/blob/main/docs/index.md),
and deploying the platform lives under
[operations](../operations/_index.md).

Each guide is the long form of the **"?" beside a page title in the console**. The
console explains itself in two or three sentences; when that is not enough, the
"Read the guide" link lands here.

## Getting a new organization running

1. [Roles](roles.md) — decide who can do what before you invite anyone, and
   [permissions](permissions.md) if you want that spelled out here rather than in each app.
2. [Members and invitations](members.md) — invite the team, send them back to your app, hand ownership over.
3. [Apps](apps-and-api-keys.md) — register the first app people will sign in to.
4. [Single sign-on](single-sign-on.md) — let people use the company account they already have.
5. [Social sign-in](social-sign-in.md) — Google, GitHub, Apple and the rest, plus how connecting one to an existing account works.
6. [Sync users in](sync-users-in.md) — have your provider create and deactivate people for you.

## Keeping it running

- [Keys](keys.md) — management, workspace and frontend keys, and who can see which.
- [API keys](api-keys.md) — keys people create for your apps' APIs, and how admins see and revoke them.
- [Sync users out](sync-users-out.md) — push your people into your other SaaS products.
- [Webhooks](webhooks.md) — get told when something happens.
- [Inline hooks](inline-hooks.md) — have a say while it happens.
- [Token vault](token-vault.md) — credentials your apps use elsewhere.
- [Agent approvals](agent-approvals.md) — approving a request to act as you, and reviewing every pending request in an environment.
- [Trusted devices](trusted-devices.md) — a phone as the authenticator that answers those approvals.

## Proving it is under control

- [Access reviews](access-reviews.md) — certify who still needs what.
- [Role conflicts](role-conflicts.md) — roles that must never be combined.
- [Activity log](activity-log.md) — the tamper-evident record of every change.
