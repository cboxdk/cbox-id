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

1. [Roles](roles.md) — decide who can do what before you invite anyone.
2. [Apps & API keys](apps-and-api-keys.md) — register the first app people will sign in to.
3. [Single sign-on](single-sign-on.md) — let people use the company account they already have.
4. [Sync users in](sync-users-in.md) — have your provider create and deactivate people for you.

## Keeping it running

- [Sync users out](sync-users-out.md) — push your people into your other SaaS products.
- [Webhooks](webhooks.md) — get told when something happens.
- [Inline hooks](inline-hooks.md) — have a say while it happens.
- [Token vault](token-vault.md) — credentials your apps use elsewhere.
- [Agent approvals](agent-approvals.md) — approving something an app or agent asks to do as you.

## Proving it is under control

- [Access reviews](access-reviews.md) — certify who still needs what.
- [Role conflicts](role-conflicts.md) — roles that must never be combined.
- [Activity log](activity-log.md) — the tamper-evident record of every change.
